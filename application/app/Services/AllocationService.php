<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Quote;
use Carbon\CarbonImmutable;

class AllocationService
{
    public function __construct(private LotAvailability $availability) {}

    public static function availableSql(string $alias = 'lot_offers', ?int $excludeReservation = null): string
    {
        $exclude = $excludeReservation ? ' AND held.id <> '.(int) $excludeReservation : '';

        return "({$alias}.quantity - {$alias}.released_quantity - {$alias}.retired_quantity
            - (SELECT COALESCE(SUM(ri.quantity), 0) FROM reservation_items ri JOIN reservations held ON held.id = ri.reservation_id WHERE ri.lot_offer_id = {$alias}.id AND held.status = 'active' AND held.expires_at > clock_timestamp() {$exclude})
            - (SELECT COALESCE(SUM(oa.quantity - oa.released_quantity), 0) FROM order_allocations oa WHERE oa.lot_offer_id = {$alias}.id))";
    }

    public function free(LotOffer $portion, ?int $excludeReservation = null): string
    {
        return (string) LotOffer::query()->whereKey($portion->id)->selectRaw(self::availableSql('lot_offers', $excludeReservation).' AS free')->value('free');
    }

    public function assertLot(Lot $lot, CarbonImmutable $at): void
    {
        Commerce::ensure($lot->status === 'published' && $lot->available_until->toDateString() >= $at->setTimezone('America/Lima')->toDateString(), 'lot', 'El lote dejó de estar publicado o vigente. Solicita otra cotización.');
        $invalid = $lot->portions()->whereRaw('quantity > released_quantity + retired_quantity')
            ->whereNotIn('lot_offers.id', $this->availability->eligiblePortions($at)->select('lot_offers.id'))->exists();
        Commerce::ensure(! $invalid, 'lot', 'Cambió la verificación o vigencia de una fuente del lote.');
    }

    /** Candidate allocation only. No stock is retained by planning or quoting. */
    public function plan(Lot $lot, string $quantity, string $rule, CarbonImmutable $at): array
    {
        $this->assertLot($lot, $at);
        $remaining = Commerce::quantity($quantity, $lot->baseUnit->dimension);
        $portions = $lot->portions()->with(['offer', 'agreement'])->get()->all();
        usort($portions, function (LotOffer $a, LotOffer $b) use ($rule): int {
            if ($rule === 'lowest_price') {
                $price = bccomp($a->agreement->gross_unit_price, $b->agreement->gross_unit_price, 6);
                if ($price !== 0) {
                    return $price;
                }
            }

            return ($a->created_at <=> $b->created_at) ?: ($a->id <=> $b->id);
        });
        $items = [];
        foreach ($portions as $portion) {
            $free = $this->free($portion);
            if (bccomp($free, '0', 3) <= 0) {
                continue;
            }
            $take = bccomp($remaining, $free, 3) <= 0 ? $remaining : $free;
            $items[] = ['portion' => $portion, 'quantity' => $take];
            $remaining = bcsub($remaining, $take, 3);
            if (bccomp($remaining, '0', 3) === 0) {
                break;
            }
        }
        Commerce::ensure(bccomp($remaining, '0', 3) === 0, 'quantity', 'La cantidad solicitada supera el saldo actual. Actualiza el lote.');

        return $items;
    }

    public function assertQuote(Quote $quote, CarbonImmutable $at, ?int $excludeReservation = null): void
    {
        Commerce::ensure($quote->status === 'issued' && $quote->expires_at->greaterThan($at), 'quote', 'La cotización venció o ya se convirtió. Solicita una nueva.');
        $lot = $quote->lot()->firstOrFail();
        $this->assertLot($lot, $at);
        Commerce::ensure($lot->version === $quote->lot_version, 'quote', 'Cambió la versión del lote. Revisa una cotización nueva.');
        foreach ($quote->items()->with('portion.agreement')->get() as $item) {
            $portion = $item->portion;
            Commerce::ensure($portion->lot_id === $lot->id && $portion->agreement_id === $item->agreement_id && $portion->offer_version === $item->offer_version, 'quote', 'La composición cambió. Solicita una cotización nueva.');
            Commerce::ensure(bccomp($this->free($portion, $excludeReservation), $item->quantity, 3) >= 0, 'quote', 'Una aportación ya no tiene saldo suficiente. Solicita otra cotización; no se sustituirá sin tu aceptación.');
        }
    }
}
