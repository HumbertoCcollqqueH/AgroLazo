<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocationLookup;
use App\Services\RuralPlaces;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RuralPlacesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        Cache::forget('location-lookup:provider-slot');
    }

    public function test_local_source_finds_a_real_rural_settlement_without_an_external_request(): void
    {
        $this->actingAs(User::factory()->withRole('coordinator')->create());
        $response = $this->postJson(route('locations.reverse'), ['latitude' => -15.351147834, 'longitude' => -70.120439309, 'rural_query' => 'Sucapaya']);
        $response->assertOk()->assertJsonPath('nearby_places.0.community', 'Sucapaya Central')->assertJsonPath('nearby_places.0.distance_km', 0)->assertJsonPath('community', '')->assertJsonPath('street', '');
        Http::assertNothingSent();
    }

    public function test_places_are_ranked_by_distance_and_distant_places_are_not_presented_as_neighbours(): void
    {
        $rows = collect([
            ['id' => 1, 'latitude' => -15.01, 'name' => 'Sector Ñuñoa'],
            ['id' => 2, 'latitude' => -15.04, 'name' => 'Sector Ñuñoa'],
            ['id' => 3, 'latitude' => -19, 'name' => 'Sector Ñuñoa'],
            ['id' => 4, 'latitude' => -15.18, 'name' => 'Sector Ñuñoa'],
        ])->map(fn ($p) => [...$p, 'longitude' => -70, 'department' => 'PUNO', 'province' => 'SAN ROMAN', 'district' => 'JULIACA'])->all();
        $service = app(RuralPlaces::class);
        $near = $service->rank($rows, -15, -70);
        $this->assertSame([1, 2], array_column($near, 'id'));
        $this->assertGreaterThan(1, $near[0]['distance_km']);
        $this->assertArrayNotHasKey('latitude', $near[0]);
        $this->assertSame('', $near[0]['street']);
        $this->assertSame([1, 2, 4], array_column($service->rank($rows, -15, -70, 'nunoa'), 'id'));
        $this->assertSame([], $service->rank($rows, -15, -70, 'No existe'));
    }

    public function test_rural_candidates_remain_available_when_the_street_provider_fails(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->withRole('coordinator')->create());
        $this->postJson(route('locations.reverse'), ['latitude' => -15.351147834, 'longitude' => -70.120439309])->assertOk()
            ->assertJsonPath('lookup_status', 'unavailable')->assertJsonPath('nearby_places.0.community', 'Sucapaya Central')
            ->assertJsonPath('community', '')->assertJsonPath('location_id', null);
    }

    public function test_remote_village_names_are_retained_without_inventing_a_street(): void
    {
        $result = app(LocationLookup::class)->normalize(['properties' => ['countrycode' => 'PE', 'osm_key' => 'place', 'osm_value' => 'hamlet', 'name' => 'Comunidad Andina']]);
        $this->assertSame('Comunidad Andina', $result['community']);
        $this->assertSame('', $result['street']);
    }

    public function test_source_has_unique_reference_points_and_explicit_provenance(): void
    {
        $data = json_decode(file_get_contents(resource_path('data/puno-populated-places.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(10000, count($data['places']));
        $this->assertSame(count($data['places']), count(array_unique(array_column($data['places'], 'id'))));
        $this->assertStringContainsString('oefa.gob.pe', $data['source_url']);
    }
}
