<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Producer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfferRegistry
{
    public const TRANSITIONS = [
        'draft' => ['pending_verification', 'cancelled'],
        'pending_verification' => ['needs_information', 'verified', 'rejected', 'cancelled'],
        'needs_information' => ['cancelled'],
        'verified' => ['published', 'needs_information', 'cancelled'],
        'published' => ['verified', 'cancelled'],
        'rejected' => ['cancelled'], 'cancelled' => [], 'expired' => [],
    ];

    private const FIELDS = ['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'quantity', 'unit_id', 'context', 'available_from', 'available_until', 'requested_unit_price', 'notes'];

    public function __construct(private UnitConversionService $conversion) {}

    public static function rules(bool $updating = false): array
    {
        return [
            'source_key' => $updating ? ['prohibited'] : ['required', 'uuid'],
            'producer_id' => $updating ? ['prohibited'] : ['required', 'integer', 'exists:producers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variety_id' => ['nullable', 'integer', 'exists:product_varieties,id'],
            'quality_grade_id' => ['nullable', 'integer', 'exists:quality_grades,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'quantity' => ['required', 'string', 'regex:/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'context' => ['nullable', 'string', 'max:120'],
            'available_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('America/Lima')->toDateString()],
            'available_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:available_from'],
            'requested_unit_price' => ['nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'version' => $updating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'status' => ['prohibited'], 'registered_by' => ['prohibited'], 'normalized_quantity' => ['prohibited'],
            'conversion_factor' => ['prohibited'], 'base_unit_id' => ['prohibited'],
        ];
    }

    public function create(User $actor, array $data): Offer
    {
        return $this->write($actor, function (?User $actor) use ($data): Offer {
            return $this->insert($actor, Validator::make($data, self::rules())->validate(), 'assisted_web');
        });
    }

    public function submitMessage(Producer $producer, array $data, string $sourceKey, string $channel): Offer
    {
        return $this->write(null, function () use ($producer, $data, $sourceKey, $channel): Offer {
            $producer = Producer::query()->lockForUpdate()->findOrFail($producer->id);
            $this->ensure($producer->canPublishOffers(), 'producer_id', 'El productor debe estar verificado antes de confirmar una oferta por mensajes.');
            $this->ensure(in_array($channel, ['simulator', 'whatsapp', 'sms'], true), 'channel', 'Canal no permitido.');
            $data = [...Arr::only($data, self::FIELDS), 'source_key' => $sourceKey, 'producer_id' => $producer->id, 'reason' => 'Intención confirmada mediante '.$channel];

            return $this->insert(null, Validator::make($data, self::rules())->validate(), $channel);
        });
    }

    private function insert(?User $actor, array $data, string $channel): Offer
    {
        $hashData = Arr::except($data, ['reason']);
        ksort($hashData);
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        if ($existing = Offer::query()->where('source_key', $data['source_key'])->first()) {
            $this->ensure(hash_equals($existing->source_hash, $hash) && $existing->registered_by === $actor?->id && $existing->channel === $channel,
                'source_key', 'La referencia ya se utilizó con otros datos. Abre un registro nuevo.');

            return $existing;
        }
        $producer = Producer::query()->lockForUpdate()->findOrFail($data['producer_id']);
        $this->ensure(! in_array($producer->status, ['archived', 'suspended', 'rejected'], true), 'producer_id', 'Este productor no está disponible para nuevas ofertas.');
        $prepared = $this->prepare($data);
        $offer = Offer::query()->create([...$prepared, 'producer_id' => $producer->id, 'source_key' => $data['source_key'], 'source_hash' => $hash, 'registered_by' => $actor?->id, 'channel' => $channel, 'status' => 'pending_verification', 'version' => 1]);
        $this->history($offer, $actor, 'created', ['status' => ['before' => null, 'after' => $offer->status]], $data['reason']);

        return $offer;
    }

    public function update(User $actor, Offer $offer, array $data): Offer
    {
        return $this->write($actor, function (User $actor) use ($offer, $data): Offer {
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $data = Validator::make($data, self::rules(true))->validate();
            $this->version($offer, $data);
            $this->ensure($offer->canBeEdited(), 'status', 'Primero solicita información o pausa la revisión. Una oferta aprobada no se edita directamente.');
            $this->noCommitments($offer);
            $prepared = $this->prepare($data);
            $changes = [];
            foreach ($prepared as $field => $value) {
                $before = $offer->getAttribute($field);
                if ($before instanceof \DateTimeInterface) {
                    $before = $before->format('Y-m-d');
                }
                if ((string) $before !== (string) $value) {
                    $changes[$field] = ['before' => $before, 'after' => $value];
                }
            }
            $changes['status'] = ['before' => $offer->status, 'after' => 'pending_verification'];
            $offer->fill([...$prepared, 'status' => 'pending_verification', 'version' => $offer->version + 1])->save();
            $this->history($offer, $actor, 'corrected', $changes, $data['reason']);

            return $offer->fresh();
        });
    }

    public function transition(User $actor, Offer $offer, array $data): Offer
    {
        return $this->write($actor, function (User $actor) use ($offer, $data): Offer {
            $data = Validator::make($data, ['version' => 'required|integer|min:1', 'result' => ['required', Rule::in(array_keys(Offer::STATUSES))], 'reason' => 'required|string|min:5|max:1000'])->validate();
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $this->version($offer, $data);
            $this->ensure(in_array($data['result'], self::TRANSITIONS[$offer->status], true), 'result', 'Esta transición no está permitida.');
            if (in_array($data['result'], ['verified', 'published'], true)) {
                $this->eligible($offer);
            }
            if ($data['result'] === 'verified' && $offer->status === 'published') {
                $this->noCommitments($offer);
            }
            if ($data['result'] === 'published') {
                $this->ensure($offer->activeAgreement() !== null, 'agreement', 'Registra la aceptación de condiciones económicas vigentes para esta versión antes de publicar.');
            }
            if ($data['result'] === 'cancelled') {
                $this->noCommitments($offer);
            }
            $before = $offer->status;
            $offer->status = $data['result'];
            $offer->save();
            $this->history($offer, $actor, 'status_changed', ['status' => ['before' => $before, 'after' => $offer->status]], $data['reason']);

            return $offer->fresh();
        });
    }

    public function completeMarketVariety(User $actor, Offer $offer, int $varietyId, int $expectedVersion, string $evidence): Offer
    {
        return $this->write($actor, function (User $actor) use ($offer, $varietyId, $expectedVersion, $evidence): Offer {
            Gate::forUser($actor)->authorize('manage-lots');
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $this->version($offer, ['version' => $expectedVersion]);
            $this->ensure($offer->status === 'verified' && $offer->variety?->code === 'unspecified', 'variety_id', 'Solo puede completarse una variedad sin especificar de una oferta verificada.');
            $this->ensure(! $offer->agreements()->exists(), 'agreement', 'La oferta ya tiene condiciones económicas; requiere revisión para cambiar la variedad.');
            $this->ensure(mb_strlen($evidence) >= 10 && mb_strlen($evidence) <= 1000, 'evidence', 'Registra la respuesta del productor que identifica la variedad.');
            $allowed = array_column(app(AutomatedMarketPriceProvider::class)->classificationChoices($offer), 'variety_id');
            $this->ensure(in_array($varietyId, $allowed, true), 'variety_id', 'La variedad debe corresponder a las opciones vigentes de este producto.');
            $this->noCommitments($offer);
            $this->eligible($offer);
            $before = $offer->variety_id;
            $offer->fill(['variety_id' => $varietyId, 'version' => $offer->version + 1])->save();
            $this->history($offer, $actor, 'market_variety_completed', ['variety_id' => ['before' => $before, 'after' => $varietyId]], $evidence);

            return $offer->fresh();
        });
    }

    public function proposeAgreement(User $actor, Offer $offer, array $data): OfferPriceAgreement
    {
        return $this->write($actor, function (User $actor) use ($offer, $data): OfferPriceAgreement {
            $data = Validator::make($data, [
                'version' => 'required|integer|min:1',
                'gross_unit_price' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/'],
                'producer_unit_cost' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/'],
                'terms' => 'required|string|min:10|max:2000',
                'valid_until' => 'required|date_format:Y-m-d',
            ])->validate();
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $this->version($offer, $data);
            $this->ensure($offer->status === 'verified', 'status', 'Las condiciones se proponen sobre una oferta verificada.');
            $this->eligible($offer);
            $this->noCommitments($offer);
            $net = bcsub($data['gross_unit_price'], $data['producer_unit_cost'], 6);
            $this->ensure(bccomp($net, '0', 6) > 0, 'producer_unit_cost', 'El neto del productor debe ser positivo y los costos menores al bruto.');
            $until = CarbonImmutable::parse($data['valid_until'], 'America/Lima')->endOfDay()->utc();
            $this->ensure($until->isFuture() && $data['valid_until'] <= $offer->available_until->toDateString(), 'valid_until', 'La vigencia debe ser futura y no superar la disponibilidad.');
            $agreement = $offer->agreements()->create([
                'offer_version' => $offer->version, 'version' => (int) $offer->agreements()->max('version') + 1,
                'gross_unit_price' => $data['gross_unit_price'], 'producer_unit_cost' => $data['producer_unit_cost'], 'net_unit_price' => $net,
                'terms' => $data['terms'], 'valid_until' => $until, 'proposed_by' => $actor->id, 'status' => 'proposed', 'currency' => 'PEN',
            ]);
            $this->history($offer, $actor, 'agreement_proposed', ['agreement_id' => $agreement->id], 'Propuesta de condiciones económicas; aún sin aceptación.');

            return $agreement;
        });
    }

    public function decideAgreement(User $actor, Offer $offer, OfferPriceAgreement $agreement, array $data): OfferPriceAgreement
    {
        return $this->write($actor, function (User $actor) use ($offer, $agreement, $data): OfferPriceAgreement {
            $data = Validator::make($data, ['version' => 'required|integer|min:1', 'decision' => ['required', Rule::in(['accepted', 'rejected'])], 'evidence' => 'required|string|min:10|max:2000'])->validate();
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $this->version($offer, $data);
            $agreement = $offer->agreements()->lockForUpdate()->findOrFail($agreement->id);
            $this->ensure($offer->status === 'verified' && $agreement->offer_version === $offer->version, 'agreement', 'La propuesta no corresponde a una oferta verificada vigente.');
            $this->eligible($offer);
            $this->ensure($agreement->id === $offer->agreements()->latest('version')->value('id'), 'agreement', 'Solo puede responderse a la propuesta más reciente.');
            $this->ensure($agreement->valid_until->isFuture(), 'agreement', 'La propuesta venció.');
            if ($agreement->status === $data['decision']) {
                return $agreement;
            }
            $this->ensure($agreement->status === 'proposed', 'agreement', 'Esta propuesta ya tiene una decisión registrada.');
            $agreement->fill(['status' => $data['decision'], 'evidence' => $data['evidence'], 'recorded_by' => $actor->id, 'accepted_at' => $data['decision'] === 'accepted' ? now() : null])->save();
            $this->history($offer, $actor, 'agreement_'.$data['decision'], ['agreement_id' => $agreement->id], 'Constancia de la decisión del productor sobre condiciones económicas.');

            return $agreement;
        });
    }

    public function expire(): int
    {
        return $this->write(null, function (): int {
            $offers = Offer::query()->whereNotIn('status', ['cancelled', 'expired'])->whereDate('available_until', '<', now('America/Lima')->toDateString())->orderBy('id')->lockForUpdate()->get();
            foreach ($offers as $offer) {
                $before = $offer->status;
                $offer->update(['status' => 'expired']);
                $this->history($offer, null, 'expired', ['status' => ['before' => $before, 'after' => 'expired']], 'Finalizó la ventana de disponibilidad; se conservan los compromisos existentes.');
            }

            return $offers->count();
        });
    }

    private function eligible(Offer $offer): void
    {
        $producer = Producer::query()->findOrFail($offer->producer_id);
        $this->ensure($producer->canPublishOffers(), 'producer_id', 'El productor debe estar verificado y activo.');
        $prepared = $this->prepare([...Arr::only($offer->getAttributes(), self::FIELDS), 'notes' => $offer->notes]);
        $this->ensure($offer->variety_id !== null && $offer->quality_grade_id !== null, 'quality_grade_id', 'Completa variedad y calidad antes de verificar.');
        $this->ensure($prepared['normalized_quantity'] !== null, 'quantity', 'Falta una equivalencia aprobada para la presentación indicada.');
        if ($offer->status === 'pending_verification') {
            $offer->fill(Arr::only($prepared, ['normalized_quantity', 'conversion_factor', 'conversion_id', 'conversion_source', 'conversion_date']));
        } else {
            $this->ensure($prepared['normalized_quantity'] === $offer->normalized_quantity && $prepared['conversion_factor'] === $offer->conversion_factor, 'quantity', 'La equivalencia cambió. Solicita información y verifica una nueva versión.');
        }
    }

    private function prepare(array $data): array
    {
        $product = Product::query()->findOrFail($data['product_id']);
        $this->ensure($product->isUsable(), 'product_id', 'El producto o su unidad base no están disponibles.');
        $this->ensure(Unit::query()->find($data['unit_id'])?->active ?? false, 'unit_id', 'La unidad no está disponible.');
        $this->ensure(Location::query()->find($data['location_id'])?->isUsable() ?? false, 'location_id', 'La localidad o su jerarquía no están disponibles.');
        foreach (['variety_id' => $product->varieties(), 'quality_grade_id' => $product->qualityGrades()] as $key => $relation) {
            if (! empty($data[$key])) {
                $this->ensure($relation->whereKey($data[$key])->where('active', true)->exists(), $key, 'La clasificación debe estar activa y pertenecer al producto.');
            }
        }
        $this->ensure(bccomp($data['quantity'], '0', 3) > 0, 'quantity', 'La cantidad debe ser positiva.');
        if (isset($data['requested_unit_price']) && $data['requested_unit_price'] !== '') {
            $this->ensure(bccomp($data['requested_unit_price'], '0', 6) > 0, 'requested_unit_price', 'El precio solicitado debe ser positivo.');
        }
        $from = substr((string) $data['available_from'], 0, 10);
        $until = substr((string) $data['available_until'], 0, 10);
        $this->ensure($until >= now('America/Lima')->toDateString() && $until >= $from, 'available_until', 'La disponibilidad venció o no es válida.');
        $date = now('America/Lima')->toDateString();
        $normalized = ['normalized_quantity' => null, 'conversion_factor' => null, 'conversion_id' => null, 'conversion_source' => null];
        try {
            $result = $this->conversion->convert($data['quantity'], $data['unit_id'], $product->base_unit_id, $product->id, $data['context'] ?? '', $date);
            $normalized = ['normalized_quantity' => $result['quantity'], 'conversion_factor' => $result['factor'], 'conversion_id' => $result['conversion_id'], 'conversion_source' => $result['source']];
        } catch (ValidationException $exception) {
            if (array_keys($exception->errors()) !== ['context']) {
                throw $exception;
            }
        }

        return [...Arr::only($data, self::FIELDS), 'context' => $data['context'] ?? '', 'base_unit_id' => $product->base_unit_id, 'available_from' => $from, 'available_until' => $until, 'conversion_date' => $date, ...$normalized];
    }

    private function version(Offer $offer, array $data): void
    {
        $this->ensure((int) $data['version'] === $offer->version, 'version', 'La oferta cambió. Recarga la ficha antes de continuar.');
    }

    private function noCommitments(Offer $offer): void
    {
        if (Schema::hasTable('lot_offers')) {
            $this->ensure(! DB::table('lot_offers')->where('offer_id', $offer->id)->whereColumn('quantity', '>', 'released_quantity')->exists(), 'status', 'La oferta ya tiene porciones de lote. Requiere resolver sus compromisos.');
        }
    }

    private function history(Offer $offer, ?User $actor, string $action, array $changes, string $reason): void
    {
        $offer->histories()->create(['actor_id' => $actor?->id, 'action' => $action, 'offer_version' => $offer->version, 'changes' => $changes, 'reason' => $reason, 'occurred_at' => now()]);
        AuditLog::record($actor, 'offer.'.$action, $offer, ['version' => $offer->version, 'status' => $offer->status], 'offer_operation');
    }

    private function write(?User $actor, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $callback): mixed {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            if ($actor) {
                $actor = User::query()->findOrFail($actor->id);
                Gate::forUser($actor)->authorize('manage-offers');
            }

            return $callback($actor);
        });
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
