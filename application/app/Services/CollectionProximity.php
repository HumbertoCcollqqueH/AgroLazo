<?php

namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Collection;

class CollectionProximity
{
    public function distance(array $a, array $b): float
    {
        $h = sin(deg2rad($a[0] - $b[0]) / 2) ** 2
            + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin(deg2rad($a[1] - $b[1]) / 2) ** 2;

        return 6371 * 2 * asin(sqrt(min(1, max(0, $h))));
    }

    public function coordinates(Offer $offer): ?array
    {
        $producer = $offer->producer;
        $location = $producer->currentLocation;
        $consent = $producer->consents()->where('purpose', 'location_planning')->latest('recorded_at')->latest('id')->first();
        if ($consent?->decision !== 'granted' || $location === null || $location->location_id !== $offer->location_id || $location->latitude === null || $location->longitude === null) {
            return null;
        }

        return [(float) $location->latitude, (float) $location->longitude];
    }

    /** Every pair must be close: a chain of nearby farms is not a nearby group. */
    public function inspect(Collection $offers): array
    {
        $coordinates = [];
        $missing = [];
        foreach ($offers->unique(fn (Offer $offer): string => $offer->producer_id.':'.$offer->location_id) as $offer) {
            $point = $this->coordinates($offer);
            if ($point === null) {
                $missing[] = $offer->producer_id;
            } else {
                $coordinates[] = $point;
            }
        }
        $maximum = 0.0;
        foreach ($coordinates as $i => $a) {
            foreach (array_slice($coordinates, $i + 1) as $b) {
                $maximum = max($maximum, $this->distance($a, $b));
            }
        }
        $multiple = $offers->pluck('producer_id')->unique()->count() > 1;
        $limit = max(1, (float) config('logistics.max_producer_distance_km', 25));
        $allowed = $maximum <= $limit && (! $multiple || $missing === [] || ! config('logistics.require_coordinates', true));

        return ['allowed' => $allowed, 'coordinates' => $coordinates, 'missing_producer_ids' => array_values(array_unique($missing)),
            'max_distance_km' => $maximum, 'limit_km' => $limit,
            'reason' => $maximum > $limit
                ? 'Los productores superan la separación máxima de '.$limit.' km. Mantén sus productos en lotes separados.'
                : 'Para agrupar productores distintos, registra su punto exacto de recojo en la localidad de la oferta y su autorización para planificar el acopio.'];
    }
}
