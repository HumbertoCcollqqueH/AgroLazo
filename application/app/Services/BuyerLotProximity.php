<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BuyerLotProximity
{
    public function destination(?User $user): ?array
    {
        if (! $user?->can('view-buyer-area')) {
            return null;
        }
        $address = $user->buyer?->delivery_address;
        if (! is_array($address) || ! is_numeric($address['latitude'] ?? null) || ! is_numeric($address['longitude'] ?? null)) {
            return null;
        }
        $lat = (float) $address['latitude'];
        $lng = (float) $address['longitude'];

        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }

    /** Rank complete origins by their furthest farm, without projecting private coordinates. */
    public function apply(Builder $query, array $destination, bool $sort): Builder
    {
        $distance = '6371 * 2 * ASIN(SQRT(LEAST(1.0, GREATEST(0.0, POWER(SIN(RADIANS(pl.latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(pl.latitude)) * POWER(SIN(RADIANS(pl.longitude - ?) / 2), 2)))))';
        $origins = DB::table('lot_offers as lp')
            ->join('offers as origin', 'origin.id', '=', 'lp.offer_id')
            ->leftJoin('producer_locations as pl', function ($join): void {
                $join->on('pl.producer_id', '=', 'origin.producer_id')->on('pl.location_id', '=', 'origin.location_id')
                    ->whereNull('pl.valid_until')->whereRaw("(SELECT pc.decision FROM producer_consents pc WHERE pc.producer_id = origin.producer_id AND pc.purpose = 'location_planning' ORDER BY pc.recorded_at DESC, pc.id DESC LIMIT 1) = 'granted'");
            })
            ->whereRaw(AllocationService::availableSql('lp').' > 0')
            ->groupBy('lp.lot_id')->select('lp.lot_id')
            ->selectRaw('CASE WHEN COUNT(pl.latitude) = COUNT(*) AND COUNT(pl.longitude) = COUNT(*) THEN MAX('.$distance.') ELSE NULL END AS distance_km', [$destination[0], $destination[0], $destination[1]]);
        $query->leftJoinSub($origins, 'buyer_proximity', 'buyer_proximity.lot_id', '=', 'lots.id')
            ->addSelect('buyer_proximity.distance_km as buyer_distance_km');
        if ($sort) {
            $query->reorder()->orderByRaw('buyer_proximity.distance_km ASC NULLS LAST')->orderByDesc('lots.published_at')->orderBy('lots.id');
        }

        return $query;
    }
}
