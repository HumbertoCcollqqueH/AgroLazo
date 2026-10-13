<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\Quote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomaticLotGrouping
{
    private const FIELDS = ['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id'];

    public function __construct(
        private Commerce $commerce,
        private AggregationService $aggregation,
        private LotAvailability $availability,
        private AllocationService $allocation,
        private CollectionProximity $proximity,
    ) {}

    /** @param array{source_key: string, reason: string} $data */
    public function publish(User $actor, Offer $offer, string $quantity, array $data): Lot
    {
        return $this->commerce->transaction($actor, 'manage-lots', function (User $actor, CarbonImmutable $at) use ($offer, $quantity, $data): Lot {
            $agreement = $offer->activeAgreement();
            Commerce::ensure($agreement !== null, 'agreement_id', 'El acuerdo de precio ya no está vigente.');
            $from = max($at->setTimezone('America/Lima')->toDateString(), $offer->available_from->toDateString());
            $until = min($offer->available_until->toDateString(), $agreement->valid_until->setTimezone('America/Lima')->toDateString());
            $sources = $this->candidates($offer->only(self::FIELDS), $agreement->gross_unit_price, $from, $until, $at, [$offer->id], seedBatch: $offer->producer->seed_batch);
            $portions = collect([$offer->id => [
                'offer_id' => $offer->id, 'offer_version' => $offer->version,
                'agreement_id' => $agreement->id, 'quantity' => $quantity,
            ]]);

            return $this->regroup($actor, $sources, $portions, $data);
        });
    }

    /** Returns a read-only proposal unless $apply is explicitly requested. */
    public function consolidate(User $actor, Lot $anchor, bool $apply = false): array
    {
        return $this->commerce->transaction($actor, 'manage-lots', function (User $actor, CarbonImmutable $at) use ($anchor, $apply): array {
            $anchor = $this->availability->marketplace()->whereKey($anchor->id)->first();
            if ($anchor === null || bccomp($anchor->unit_price_min, $anchor->unit_price_max, 6) !== 0) {
                return ['lots' => [], 'quantity' => '0.000', 'lot' => null];
            }
            $sources = $this->candidates(
                $anchor->only(self::FIELDS), $anchor->unit_price_min,
                $anchor->available_from->toDateString(), $anchor->available_until->toDateString(), $at,
                firstLot: $anchor->id, seedBatch: $anchor->portions->first()?->offer->producer->seed_batch,
            );
            if (! $sources->contains(fn (array $source): bool => $source['lot']->id === $anchor->id) || $sources->count() < 2) {
                return ['lots' => [], 'quantity' => '0.000', 'lot' => null];
            }
            $quantity = '0.000';
            foreach ($sources as $source) {
                foreach ($source['portions'] as $portion) {
                    $quantity = bcadd($quantity, $portion['quantity'], 3);
                }
            }
            $result = ['lots' => $sources->map(fn (array $source): string => $source['lot']->code)->all(), 'quantity' => $quantity, 'lot' => null];
            if ($apply) {
                $result['lot'] = $this->regroup($actor, $sources, collect(), [
                    'source_key' => (string) Str::uuid(),
                    'reason' => 'Agrupación de saldos disponibles compatibles, conservando compras, acuerdos y trazabilidad.',
                ]);
            }

            return $result;
        });
    }

    /** @return Collection<int, array{lot: Lot, portions: array<int, array{portion: LotOffer, quantity: string}>}> */
    private function candidates(array $classification, string $price, string $from, string $until, CarbonImmutable $at, array $offerIds = [], ?int $firstLot = null, ?string $seedBatch = null): Collection
    {
        $query = $this->availability->eligibleLots($at)->where('lots.status', 'published')
            ->whereDoesntHave('portions.offer.producer', function ($q) use ($seedBatch): void {
                if ($seedBatch === null) {
                    $q->whereNotNull('seed_batch');
                } else {
                    $q->where(fn ($different) => $different->whereNull('seed_batch')->orWhere('seed_batch', '!=', $seedBatch));
                }
            })
            ->where('availability.unit_price_min', $price)->where('availability.unit_price_max', $price)
            ->whereDate('lots.available_from', '<=', $until)->whereDate('lots.available_until', '>=', $from)
            ->whereNotIn('lots.id', Quote::query()->select('lot_id')->where('status', 'issued')->where('expires_at', '>', $at))
            ->whereNotIn('lots.id', DB::table('reservation_items')->join('reservations', 'reservations.id', '=', 'reservation_items.reservation_id')
                ->join('lot_offers', 'lot_offers.id', '=', 'reservation_items.lot_offer_id')->select('lot_offers.lot_id')
                ->where('reservations.status', 'active')->where('reservations.expires_at', '>', $at));
        foreach ($classification as $field => $value) {
            $query->where('lots.'.$field, $value);
        }
        if ($firstLot !== null) {
            $query->orderByRaw('CASE WHEN lots.id = ? THEN 0 ELSE 1 END', [$firstLot]);
        }
        $lots = $query->orderBy('lots.available_until')->orderBy('lots.id')->limit(100)->get();
        $selected = collect();
        foreach ($lots as $lot) {
            $nextFrom = max($from, $lot->available_from->toDateString());
            $nextUntil = min($until, $lot->available_until->toDateString());
            if ($nextFrom > $nextUntil) {
                continue;
            }
            $lot = $this->commerce->lockLot($lot);
            $portions = [];
            foreach ($lot->portions()->get() as $portion) {
                $free = $this->allocation->free($portion);
                if (bccomp($free, '0', 3) > 0) {
                    $portions[] = ['portion' => $portion, 'quantity' => $free];
                }
            }
            $nextOfferIds = array_unique([...$offerIds, ...array_map(fn (array $entry): int => $entry['portion']->offer_id, $portions)]);
            if ($portions === [] || count($nextOfferIds) > 100) {
                continue;
            }
            if (! $this->proximity->inspect(Offer::query()->with('producer.currentLocation')->whereKey($nextOfferIds)->get())['allowed']) {
                continue;
            }
            $from = $nextFrom;
            $until = $nextUntil;
            $offerIds = $nextOfferIds;
            $selected->push(['lot' => $lot, 'portions' => $portions]);
        }

        return $selected;
    }

    private function regroup(User $actor, Collection $sources, Collection $portions, array $data): Lot
    {
        $changes = [];
        foreach ($sources as $source) {
            $lot = $source['lot'];
            $changes[$lot->id] = [];
            foreach ($source['portions'] as $entry) {
                $portion = $entry['portion'];
                $quantity = $entry['quantity'];
                $existing = $portions->get($portion->offer_id);
                Commerce::ensure($existing === null || ($existing['agreement_id'] === $portion->agreement_id && $existing['offer_version'] === $portion->offer_version), 'portions', 'Las condiciones de una oferta cambiaron durante la agrupación.');
                $portions->put($portion->offer_id, [
                    'offer_id' => $portion->offer_id, 'offer_version' => $portion->offer_version, 'agreement_id' => $portion->agreement_id,
                    'quantity' => bcadd($existing['quantity'] ?? '0', $quantity, 3),
                ]);
                $before = $portion->released_quantity;
                $portion->update(['released_quantity' => bcadd($before, $quantity, 3)]);
                $changes[$lot->id][] = ['portion_id' => $portion->id, 'quantity' => $quantity, 'released_before' => $before, 'released_after' => $portion->released_quantity];
            }
        }
        $lot = $this->aggregation->create($actor, [...$data, 'portions' => $portions->sortKeys()->values()->all()]);
        $lot = $this->aggregation->transition($actor, $lot, ['version' => $lot->version, 'result' => 'published', 'reason' => $data['reason']]);
        foreach ($sources as $source) {
            $previous = $source['lot'];
            $before = $previous->status;
            $remaining = $previous->portions()->whereRaw('quantity > released_quantity + retired_quantity')->exists();
            $previous->update(['version' => $previous->version + 1, 'status' => $remaining ? $before : 'cancelled']);
            $previous->histories()->create([
                'actor_id' => $actor->id, 'action' => 'available_balance_grouped', 'lot_version' => $previous->version,
                'changes' => ['target_lot_id' => $lot->id, 'target_lot_code' => $lot->code, 'portions' => $changes[$previous->id], 'status' => ['before' => $before, 'after' => $previous->status]],
                'reason' => 'Saldo libre trasladado a '.$lot->code.'. Las compras y aportaciones originales se conservan.', 'occurred_at' => now(),
            ]);
            AuditLog::record($actor, 'lot.available_balance_grouped', $previous, ['target_lot_id' => $lot->id, 'version' => $previous->version], 'lot_operation');
        }

        return $lot;
    }
}
