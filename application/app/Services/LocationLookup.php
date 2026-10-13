<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LocationLookup
{
    public function reverse(float $latitude, float $longitude): array
    {
        $endpoint = (string) config('logistics.geocoder_url');
        abort_if($endpoint === '', 503, 'La consulta de direcciones no está disponible. Puedes completar los campos manualmente.');
        $key = 'location-lookup:v2:'.hash_hmac('sha256', $endpoint.sprintf(':%.6f:%.6f', $latitude, $longitude), (string) config('app.key'));
        if ($cached = Cache::get($key)) {
            $feature = json_decode(Crypt::decryptString($cached), true, flags: JSON_THROW_ON_ERROR);
        } else {
            abort_unless(Cache::add('location-lookup:provider-slot', true, now()->addSeconds(2)), 429, 'Hay otra consulta en curso. Vuelve a intentarlo en unos segundos.');
            $response = Http::acceptJson()->withUserAgent('AgroLazo/1.0')->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false])->get($endpoint, ['lat' => $latitude, 'lon' => $longitude, 'limit' => 1, 'radius' => 0.2]);
            abort_unless($response->successful(), 503, 'No se pudo consultar la dirección. Conserva el punto y completa los datos manualmente.');
            $feature = $response->json('features.0') ?? [];
            $feature = is_array($feature) ? $feature : [];
            Cache::put($key, Crypt::encryptString(json_encode($feature, JSON_THROW_ON_ERROR)), now()->addDay());
        }

        return $this->normalize($feature);
    }

    public function normalize(array $feature): array
    {
        $p = $feature['properties'] ?? [];
        $p = is_array($p) ? $p : [];
        $empty = ['department' => '', 'province' => '', 'district' => '', 'community' => '', 'street' => '', 'location_id' => null];
        if (strtoupper((string) ($p['countrycode'] ?? '')) !== 'PE') {
            return $empty;
        }
        $text = fn (string $key): string => is_string($p[$key] ?? null) ? Str::limit(trim($p[$key]), 150, '') : '';
        $normal = fn (string $value): string => Str::lower(Str::ascii(trim($value)));
        $department = Location::query()->where('type', 'department')->where('active', true)->get()
            ->first(fn (Location $location): bool => $normal($location->name) === $normal($text('state')) && $location->isUsable());
        $province = $department?->children()->where('type', 'province')->where('active', true)->get()
            ->first(fn (Location $location): bool => $normal($location->name) === $normal($text('county')));
        $districts = $province?->children()->where('type', 'district')->where('active', true)->get()
            ->filter(fn (Location $location): bool => in_array($normal($location->name), array_filter([$normal($text('city')), $normal($text('district'))]), true));
        $district = $districts?->count() === 1 ? $districts->first() : null;
        $street = $text('street');
        if ($street === '' && ($p['type'] ?? '') === 'street') {
            $street = $text('name');
        }
        if ($street !== '' && $text('housenumber') !== '') {
            $street .= ' '.$text('housenumber');
        }

        return ['department' => $department?->name ?? $text('state'), 'province' => $province?->name ?? $text('county'),
            'district' => $district?->name ?? '', 'community' => $text('locality') ?: (($p['osm_key'] ?? '') === 'place' && in_array($p['osm_value'] ?? '', ['village', 'hamlet', 'isolated_dwelling', 'locality'], true) ? $text('name') : ($district?->name !== $text('district') ? $text('district') : '')),
            'street' => $street, 'location_id' => $district?->id ?? $province?->id ?? $department?->id];
    }
}
