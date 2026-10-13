<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationLookup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class LocationAutomationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        Cache::forget('location-lookup:provider-slot');
        $this->withoutVite();
    }

    private function feature(): array
    {
        return ['properties' => ['countrycode' => 'PE', 'state' => 'Puno', 'county' => 'San Román', 'city' => 'Juliaca', 'district' => 'Taparachi', 'locality' => 'Rinconada', 'type' => 'street', 'name' => 'Calle Hipolito Unanue']];
    }

    private function district(): Location
    {
        $country = Location::factory()->create(['name' => 'Perú', 'type' => 'country', 'parent_id' => null]);
        $department = Location::factory()->create(['name' => 'Puno', 'type' => 'department', 'parent_id' => $country->id]);
        $province = Location::factory()->create(['name' => 'San Román', 'type' => 'province', 'parent_id' => $department->id]);

        return Location::factory()->create(['name' => 'Juliaca', 'type' => 'district', 'parent_id' => $province->id]);
    }

    public function test_neighbourhood_is_not_confused_with_peruvian_district_and_catalogue_hierarchy_is_used(): void
    {
        $district = $this->district();
        $result = app(LocationLookup::class)->normalize($this->feature());
        $this->assertSame('Juliaca', $result['district']);
        $this->assertSame('Rinconada', $result['community']);
        $this->assertSame('San Román', $result['province']);
        $this->assertSame($district->id, $result['location_id']);
        $this->assertSame('Calle Hipolito Unanue', $result['street']);
        $otherDistrict = Location::factory()->create(['name' => 'Taparachi', 'type' => 'district', 'parent_id' => $district->parent_id]);
        $this->assertSame('', app(LocationLookup::class)->normalize($this->feature())['district']);
        $otherDistrict->update(['active' => false]);
        $unknown = $this->feature();
        $unknown['properties']['county'] = 'Otra provincia';
        $this->assertSame('', app(LocationLookup::class)->normalize($unknown)['district']);
        $district->update(['active' => false]);
        $this->assertSame('', app(LocationLookup::class)->normalize($this->feature())['district']);
    }

    public function test_lookup_requires_authentication_validates_coordinates_and_caches_provider_response(): void
    {
        $district = $this->district();
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [$this->feature()]])]);
        $url = route('locations.reverse');
        $data = ['latitude' => -15.4997, 'longitude' => -70.1333];
        $this->postJson($url, $data)->assertUnauthorized();
        $this->actingAs(User::factory()->withRole('buyer')->create());
        $this->postJson($url, ['latitude' => 91, 'longitude' => -70])->assertUnprocessable();
        $this->postJson($url, $data)->assertOk()->assertJsonPath('location_id', $district->id);
        $this->postJson($url, $data)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ! isset($request['name']) && ! isset($request['phone']) && $request['radius'] === 0.2);
    }

    public function test_empty_or_failed_lookup_does_not_invent_an_address(): void
    {
        $this->actingAs(User::factory()->withRole('buyer')->create());
        Http::fake(['*' => Http::sequence()->push(['features' => []])->push([], 503)]);
        $this->postJson(route('locations.reverse'), ['latitude' => -15.7, 'longitude' => -70.7])->assertOk()->assertJsonPath('street', '')->assertJsonPath('location_id', null);
        Cache::forget('location-lookup:provider-slot');
        $this->postJson(route('locations.reverse'), ['latitude' => -15.8, 'longitude' => -70.8])->assertOk()->assertJsonPath('lookup_status', 'unavailable')->assertJsonPath('street', '');
    }

    public function test_saved_buyer_address_is_private_reused_in_quotes_and_does_not_change_existing_orders(): void
    {
        $scenario = new CommerceScenario;
        $original = $scenario->order->delivery_address;
        $address = [...CommerceScenario::address(), 'street' => 'Restaurante en avenida Central 456'];
        $url = route('account.delivery-address');
        $this->actingAs($scenario->buyer)->patch($url, ['delivery_address' => $address])->assertRedirect(route('account.edit'));
        $buyer = $scenario->buyer->buyer->fresh();
        $this->assertSame($address, $buyer->delivery_address);
        $this->assertArrayNotHasKey('delivery_address', $buyer->toArray());
        $this->assertStringNotContainsString('avenida Central', DB::table('buyers')->where('id', $buyer->id)->value('delivery_address'));
        $this->get(route('commerce.quotes.create', $scenario->order->quote->lot))->assertOk()->assertSee('Restaurante en avenida Central 456');
        $this->assertSame($original, $scenario->order->fresh()->delivery_address);
        $this->get(route('account.edit'))->assertOk()->assertSee('Mi lugar habitual de entrega');
        $this->patch($url, ['clear_address' => true])->assertRedirect();
        $this->assertNull($buyer->fresh()->delivery_address);
        $this->assertSame($original, $scenario->order->fresh()->delivery_address);
    }

    public function test_buyer_can_only_update_own_address_and_invalid_address_is_rejected(): void
    {
        $user = User::factory()->withRole('buyer')->create();
        $buyer = Buyer::factory()->create(['user_id' => $user->id]);
        $other = Buyer::factory()->create();
        $url = route('account.delivery-address');
        $this->actingAs($user)->patchJson($url, ['delivery_address' => ['latitude' => 999]])->assertUnprocessable();
        $this->patch($url, ['buyer_id' => $other->id, 'delivery_address' => CommerceScenario::address()])->assertRedirect();
        $this->assertNull($other->fresh()->delivery_address);
        $this->assertNotNull($buyer->fresh()->delivery_address);
        $this->actingAs(User::factory()->withRole('coordinator')->create())->patchJson($url, ['delivery_address' => CommerceScenario::address()])->assertForbidden();
    }
}
