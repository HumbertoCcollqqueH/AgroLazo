<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\Offer;
use App\Models\OperationKey;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OfferPublication
{
    public function __construct(
        private OfferRegistry $registry,
        private AutomaticLotGrouping $grouping,
        private LotAvailability $availability,
    ) {}

    /** @param array{version: int|string, agreement_id: int|string, source_key: string, reason: string} $data */
    public function publish(User $actor, Offer $offer, array $data): Lot
    {
        return DB::transaction(function () use ($actor, $offer, $data): Lot {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('manage-offers');
            Gate::forUser($actor)->authorize('manage-lots');
            $data = Validator::make($data, [
                'version' => 'required|integer|min:1',
                'agreement_id' => 'required|integer|min:1',
                'source_key' => 'required|uuid',
                'reason' => 'required|string|min:5|max:1000',
            ])->validate();
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $publicationHash = hash('sha256', json_encode([
                'offer_id' => $offer->id, 'version' => (int) $data['version'], 'agreement_id' => (int) $data['agreement_id'],
            ], JSON_THROW_ON_ERROR));

            if ($existing = Lot::query()->where('source_key', $data['source_key'])->lockForUpdate()->first()) {
                $receipt = OperationKey::query()->where('operation', 'offer-publication')->where('key', $data['source_key'])->first();
                $portions = $existing->portions()->get();
                $portion = $portions->first();
                $this->ensure(
                    $existing->created_by === $actor->id && ($receipt !== null
                        ? $receipt->actor_id === $actor->id && hash_equals($receipt->payload_hash, $publicationHash) && $receipt->resource_id === $existing->id
                        : $portions->count() === 1 && $portion->offer_id === $offer->id
                            && $portion->offer_version === (int) $data['version'] && $portion->agreement_id === (int) $data['agreement_id']),
                    'source_key', 'Esta referencia ya se utilizó para otra publicación. Recarga la oferta.',
                );
                $this->ensure($existing->status === 'published', 'source_key', 'El lote de esta publicación cambió de estado. Revisa su ficha; no se publicará otra vez automáticamente.');

                return $existing;
            }

            $this->ensure($offer->version === (int) $data['version'], 'version', 'La oferta cambió. Recarga la ficha antes de publicar.');
            $this->ensure(in_array($offer->status, ['verified', 'published'], true), 'status', 'Primero aprueba los datos de la oferta antes de publicarla para compradores.');
            $agreement = $offer->activeAgreement();
            $this->ensure($agreement !== null, 'agreement_id', 'Falta registrar un precio y la aceptación vigente del productor antes de publicar para compradores.');
            $this->ensure($agreement->id === (int) $data['agreement_id'], 'agreement_id', 'Las condiciones económicas cambiaron. Recarga la ficha y revisa el acuerdo antes de publicar.');
            $quantity = $this->availability->ungroupedQuantity($offer);
            $this->ensure(bccomp($quantity, '0', 3) > 0, 'quantity', 'Esta oferta ya no tiene saldo libre. Revisa sus lotes existentes.');

            if ($offer->status === 'verified') {
                $offer = $this->registry->transition($actor, $offer, [
                    'version' => $data['version'], 'result' => 'published', 'reason' => $data['reason'],
                ]);
            }
            $lot = $this->grouping->publish($actor, $offer, $quantity, [
                'source_key' => $data['source_key'], 'reason' => $data['reason'],
            ]);
            OperationKey::query()->create([
                'actor_id' => $actor->id, 'operation' => 'offer-publication', 'key' => $data['source_key'],
                'payload_hash' => $publicationHash, 'resource_type' => Lot::class, 'resource_id' => $lot->id,
            ]);

            return $lot;
        }, 3);
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
