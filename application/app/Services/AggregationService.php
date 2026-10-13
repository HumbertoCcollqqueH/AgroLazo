<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AggregationService
{
    public const TRANSITIONS = [
        'draft' => ['published', 'cancelled'],
        'published' => ['paused', 'cancelled'],
        'paused' => ['published', 'cancelled'],
        'expired' => ['cancelled'],
        'cancelled' => [],
    ];

    private const COMPATIBILITY_FIELDS = ['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id'];

    public function __construct(private LotAvailability $availability, private CollectionProximity $proximity) {}

    public static function rules(): array
    {
        return [
            'source_key' => ['required', 'uuid'],
            'portions' => ['required', 'array', 'min:1', 'max:100'],
            'portions.*' => ['required', 'array:offer_id,quantity,offer_version,agreement_id'],
            'portions.*.offer_id' => ['required', 'integer', 'distinct:strict', 'exists:offers,id'],
            'portions.*.quantity' => ['required', 'string', 'regex:/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/'],
            'portions.*.offer_version' => ['required', 'integer', 'min:1'],
            'portions.*.agreement_id' => ['required', 'integer', 'exists:offer_price_agreements,id'],
            'allow_price_variation' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'status' => ['prohibited'], 'created_by' => ['prohibited'], 'available_quantity' => ['prohibited'],
        ];
    }

    /** The suggestion never creates a lot or consumes quantities. */
    public function suggest(User $actor, Offer $anchor, bool $allowPriceVariation = false): Collection
    {
        Gate::forUser(User::query()->findOrFail($actor->id))->authorize('manage-lots');
        $anchor = $this->availability->ungroupedOffers()->findOrFail($anchor->id);
        $price = $anchor->activeAgreement();
        $this->ensure($price !== null, 'anchor_id', 'Las condiciones de la oferta vencieron. Actualiza la selección.');
        $query = $this->availability->ungroupedOffers()->whereHas('producer', fn ($q) => $q->where('seed_batch', $anchor->producer->seed_batch));
        foreach (self::COMPATIBILITY_FIELDS as $field) {
            $query->where($field, $anchor->getAttribute($field));
        }
        $candidates = $query->whereDate('available_from', '<=', $anchor->available_until)
            ->whereDate('available_until', '>=', $anchor->available_from)
            ->orderBy('available_from')->orderBy('id')->limit(500)->get();
        $selected = $anchor->newCollection([$anchor]);
        $from = $anchor->available_from->toDateString();
        $until = min($anchor->available_until->toDateString(), $price->valid_until->setTimezone('America/Lima')->toDateString());
        foreach ($candidates as $candidate) {
            if ($candidate->id === $anchor->id || $selected->count() >= 100) {
                continue;
            }
            $agreement = $candidate->activeAgreement();
            if ($agreement === null || (! $allowPriceVariation && bccomp($agreement->gross_unit_price, $price->gross_unit_price, 6) !== 0)) {
                continue;
            }
            $nextFrom = max($from, $candidate->available_from->toDateString());
            $nextUntil = min($until, $candidate->available_until->toDateString(), $agreement->valid_until->setTimezone('America/Lima')->toDateString());
            if ($nextFrom > $nextUntil) {
                continue;
            }
            if (! $this->proximity->inspect($selected->concat([$candidate]))['allowed']) {
                continue;
            }
            $from = $nextFrom;
            $until = $nextUntil;
            $selected->push($candidate);
        }

        return $selected->load(['producer', 'product', 'variety', 'qualityGrade', 'location', 'baseUnit']);
    }

    public function create(User $actor, array $data): Lot
    {
        return $this->write($actor, function (User $actor, CarbonImmutable $at) use ($data): Lot {
            $data = Validator::make($data, self::rules())->validate();
            $portions = collect($data['portions'])->map(fn (array $portion): array => [
                'offer_id' => (int) $portion['offer_id'],
                'quantity' => bcadd($portion['quantity'], '0', 3),
                'offer_version' => (int) $portion['offer_version'],
                'agreement_id' => (int) $portion['agreement_id'],
            ])->sortBy('offer_id')->values();
            $this->ensure($portions->pluck('offer_id')->unique()->count() === $portions->count(), 'portions', 'Selecciona cada oferta una sola vez.');
            $allowPriceVariation = (bool) ($data['allow_price_variation'] ?? false);
            $hash = hash('sha256', json_encode(['portions' => $portions->all(), 'allow_price_variation' => $allowPriceVariation], JSON_THROW_ON_ERROR));
            if ($existing = Lot::query()->where('source_key', $data['source_key'])->first()) {
                $this->ensure($existing->created_by === $actor->id && hash_equals($existing->source_hash, $hash), 'source_key', 'Esta referencia ya se utilizó con otra composición. Abre un lote nuevo.');

                return $existing;
            }
            $offers = Offer::query()->whereKey($portions->pluck('offer_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            LotOffer::query()->whereIn('offer_id', $offers->keys())->orderBy('id')->lockForUpdate()->get();
            $eligibleIds = $this->availability->eligibleOffers($at)->whereKey($offers->keys())->pluck('offers.id');
            $this->ensure($eligibleIds->count() === $offers->count() && $offers->count() === $portions->count(), 'portions', 'Una oferta dejó de estar publicada, verificada o vigente. Actualiza la selección.');
            $anchor = $offers->first();
            $proximity = $this->proximity->inspect($offers);
            $this->ensure($proximity['allowed'], 'portions', $proximity['reason']);
            $from = $at->setTimezone('America/Lima')->toDateString();
            $until = $anchor->available_until->toDateString();
            $firstPrice = null;
            foreach ($portions as $index => $portion) {
                $offer = $offers->get($portion['offer_id']);
                $this->ensure($offer->producer->seed_batch === $anchor->producer->seed_batch, 'portions', 'Estas aportaciones pertenecen a cargas distintas y deben conservar lotes separados.');
                foreach (self::COMPATIBILITY_FIELDS as $field) {
                    $this->ensure($offer->getAttribute($field) === $anchor->getAttribute($field), 'portions', 'Las ofertas deben compartir producto, variedad, calidad, localidad y unidad base.');
                }
                $this->ensure($portion['offer_version'] === $offer->version, 'portions', 'Cambió una versión de oferta. Revisa la composición antes de confirmar.');
                $agreement = $offer->activeAgreement();
                $this->ensure($agreement !== null && $agreement->id === $portion['agreement_id'], 'portions', 'Las condiciones económicas cambiaron. Revisa los precios antes de confirmar.');
                $this->ensure(bccomp($portion['quantity'], '0', 3) > 0, 'portions.'.$index.'.quantity', 'Cada aportación debe ser positiva.');
                $this->ensure(bccomp($portion['quantity'], $this->availability->ungroupedQuantity($offer), 3) <= 0, 'portions.'.$index.'.quantity', 'La cantidad supera el saldo libre de la oferta #'.$offer->id.'.');
                if ($offer->baseUnit->dimension === 'count') {
                    $this->ensure(bccomp($portion['quantity'], bcadd($portion['quantity'], '0', 0), 3) === 0, 'portions.'.$index.'.quantity', 'Los productos por unidad requieren cantidades enteras.');
                }
                $firstPrice ??= $agreement->gross_unit_price;
                $this->ensure($allowPriceVariation || bccomp($firstPrice, $agreement->gross_unit_price, 6) === 0, 'allow_price_variation', 'Los precios aceptados difieren. Revisa el rango y confirma expresamente esta composición.');
                $from = max($from, $offer->available_from->toDateString());
                $until = min($until, $offer->available_until->toDateString(), $agreement->valid_until->setTimezone('America/Lima')->toDateString());
            }
            $this->ensure($from <= $until, 'portions', 'Las ofertas y sus acuerdos no tienen una ventana de disponibilidad común.');
            $lot = Lot::query()->create([
                'code' => 'L-'.Str::upper(Str::random(8)), 'source_key' => $data['source_key'], 'source_hash' => $hash,
                'created_by' => $actor->id, ...$anchor->only(self::COMPATIBILITY_FIELDS),
                'available_from' => $from, 'available_until' => $until,
                'status' => 'draft', 'version' => 1, 'allow_price_variation' => $allowPriceVariation,
                'compatibility_rule' => 'locality-proximity-v2',
            ]);
            foreach ($portions as $portion) {
                $lot->portions()->create($portion);
            }
            $this->history($lot, $actor, 'created', ['status' => ['before' => null, 'after' => 'draft'], 'portions' => $portions->all()], $data['reason']);

            return $lot;
        });
    }

    public function transition(User $actor, Lot $lot, array $data): Lot
    {
        return $this->write($actor, function (User $actor, CarbonImmutable $at) use ($lot, $data): Lot {
            $data = Validator::make($data, ['version' => 'required|integer|min:1', 'result' => ['required', Rule::in(array_keys(Lot::STATUSES))], 'reason' => 'required|string|min:5|max:1000'])->validate();
            $this->lockSources($lot);
            $lot = Lot::query()->lockForUpdate()->findOrFail($lot->id);
            $this->ensure($lot->version === (int) $data['version'], 'version', 'El lote cambió. Recarga la ficha antes de continuar.');
            $this->ensure(in_array($data['result'], self::TRANSITIONS[$lot->status], true), 'result', 'Esta transición no está permitida.');
            if ($data['result'] === 'published') {
                $proximity = $this->proximity->inspect(Offer::query()->whereKey($lot->portions()->pluck('offer_id'))->get());
                $this->ensure($proximity['allowed'], 'result', $proximity['reason']);
                $this->ensure($this->availability->eligibleLots($at)->whereKey($lot->id)->exists(), 'result', 'Revisa las fuentes, cantidades y vigencias: el lote no está disponible para publicación.');
            }
            $released = $data['result'] === 'cancelled' ? $this->release($lot) : [];
            $before = $lot->status;
            $lot->fill(['status' => $data['result'], 'version' => $lot->version + 1]);
            if ($lot->status === 'published' && $lot->published_at === null) {
                $lot->published_at = $at;
            }
            $lot->save();
            $this->history($lot, $actor, 'status_changed', ['status' => ['before' => $before, 'after' => $lot->status], 'released_portions' => $released], $data['reason']);

            return $lot->fresh();
        });
    }

    public function expire(): int
    {
        return $this->write(null, function (?User $actor, CarbonImmutable $at): int {
            $lots = Lot::query()->whereIn('status', ['draft', 'published', 'paused'])
                ->whereDate('available_until', '<', $at->setTimezone('America/Lima')->toDateString())->orderBy('id')->get();
            foreach ($lots as $lot) {
                $this->lockSources($lot);
                $lot = Lot::query()->lockForUpdate()->findOrFail($lot->id);
                $before = $lot->status;
                $lot->update(['status' => 'expired', 'version' => $lot->version + 1]);
                $this->history($lot, null, 'expired', ['status' => ['before' => $before, 'after' => 'expired']], 'Finalizó la disponibilidad. Las porciones se conservan hasta resolverlas.');
            }

            return $lots->count();
        });
    }

    private function lockSources(Lot $lot): void
    {
        $offerIds = $lot->portions()->pluck('offer_id');
        Offer::query()->whereKey($offerIds)->orderBy('id')->lockForUpdate()->get();
        LotOffer::query()->whereIn('offer_id', $offerIds)->orderBy('id')->lockForUpdate()->get();
    }

    private function release(Lot $lot): array
    {
        $portions = $lot->portions()->get();
        $held = DB::table('reservation_items')->join('reservations', 'reservations.id', '=', 'reservation_items.reservation_id')
            ->whereIn('lot_offer_id', $portions->pluck('id'))->where('reservations.status', 'active')->whereRaw('reservations.expires_at > clock_timestamp()')->exists();
        $committed = DB::table('order_allocations')->whereIn('lot_offer_id', $portions->pluck('id'))->whereRaw('quantity > released_quantity')->exists();
        $this->ensure(! $held && ! $committed, 'result', 'El lote tiene reservas o compras comprometidas. Resuélvelas antes de liberar cantidades.');
        $changes = [];
        foreach ($portions as $portion) {
            $before = $portion->released_quantity;
            $portion->update(['released_quantity' => bcsub($portion->quantity, $portion->retired_quantity, 3)]);
            $changes[] = ['portion_id' => $portion->id, 'before' => $before, 'after' => $portion->released_quantity];
        }

        return $changes;
    }

    private function history(Lot $lot, ?User $actor, string $action, array $changes, string $reason): void
    {
        $lot->histories()->create(['actor_id' => $actor?->id, 'action' => $action, 'lot_version' => $lot->version, 'changes' => $changes, 'reason' => $reason, 'occurred_at' => now()]);
        AuditLog::record($actor, 'lot.'.$action, $lot, ['version' => $lot->version, 'status' => $lot->status], 'lot_operation');
    }

    private function write(?User $actor, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $callback): mixed {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            if ($actor !== null) {
                $actor = User::query()->findOrFail($actor->id);
                Gate::forUser($actor)->authorize('manage-lots');
            }
            $at = CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()'));

            return $callback($actor, $at);
        }, 3);
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
