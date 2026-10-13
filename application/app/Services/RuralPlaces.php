<?php

namespace App\Services;

use Illuminate\Support\Str;

class RuralPlaces
{
    public function nearby(float $latitude, float $longitude, string $query = ''): array
    {
        $path = resource_path('data/puno-populated-places.json');
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $this->rank($data['places'], $latitude, $longitude, $query);
    }

    /** Reference points are candidates, not administrative boundaries or pickup coordinates. */
    public function rank(array $places, float $latitude, float $longitude, string $query = ''): array
    {
        $needle = Str::lower(Str::ascii(trim($query)));
        $limit = $needle === '' ? 10.0 : 25.0;
        $distance = app(CollectionProximity::class);
        $candidates = [];
        foreach ($places as $place) {
            if (abs($place['latitude'] - $latitude) > $limit / 110 || abs($place['longitude'] - $longitude) > $limit / 100) {
                continue;
            }
            if ($needle !== '' && ! str_contains(Str::lower(Str::ascii($place['name'].' '.$place['district'])), $needle)) {
                continue;
            }
            $km = $distance->distance([$latitude, $longitude], [$place['latitude'], $place['longitude']]);
            if ($km <= $limit) {
                $candidates[] = [...$place, 'distance_km' => $km];
            }
        }
        usort($candidates, fn (array $a, array $b): int => ($a['distance_km'] <=> $b['distance_km']) ?: ($a['id'] <=> $b['id']));

        return array_map(function (array $place): array {
            $normalized = app(LocationLookup::class)->normalize(['properties' => [
                'countrycode' => 'PE', 'state' => $place['department'], 'county' => $place['province'], 'city' => $place['district'],
            ]]);

            return [...$normalized, 'id' => $place['id'], 'community' => $place['name'],
                'district' => $normalized['district'] ?: Str::title(Str::lower($place['district'])),
                'distance_km' => round($place['distance_km'], 2), 'source' => 'INEI · servicio público OEFA'];
        }, array_slice($candidates, 0, 5));
    }
}
