<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\ProducerConsent;
use App\Models\ProducerLocation;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class LotLocationExperienceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        Http::preventStrayRequests();
    }

    private function lot(?string $latitude): Lot
    {
        $offer = Offer::factory()->published()->create(['location_id' => Location::where('type', 'department')->where('name', 'Puno')->value('id')]);
        if ($latitude !== null) {
            ProducerLocation::factory()->create(['producer_id' => $offer->producer_id, 'location_id' => $offer->location_id, 'latitude' => $latitude, 'longitude' => '-70.021900', 'address' => 'Parcela privada exclusiva 849']);
            ProducerConsent::factory()->create(['producer_id' => $offer->producer_id, 'purpose' => 'location_planning']);
        }

        return Lot::factory()->withOffer($offer)->create(['status' => 'published', 'published_at' => now()]);
    }

    public function test_saved_destination_prioritizes_nearest_lots_before_pagination_without_exposing_origins(): void
    {
        $far = $this->lot('-16.400000');
        $unknown = $this->lot(null);
        $near = $this->lot('-15.840123');
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id, 'delivery_address' => CommerceScenario::address()]);
        $response = $this->actingAs($buyer)->get(route('marketplace.index'))->assertOk();
        $this->assertSame([$near->id, $far->id, $unknown->id], $response->viewData('lots')->pluck('id')->all());
        $response->assertSee('Más cerca de mi destino')->assertDontSee('Parcela privada exclusiva 849')->assertDontSee('-15.840123');
        $this->getJson(route('api.marketplace.show', $near->code))->assertOk()->assertDontSee('buyer_distance_km')->assertDontSee('-15.840123');
    }

    public function test_revoked_location_is_not_used_for_recommendations(): void
    {
        $lot = $this->lot('-15.840123');
        $producer = $lot->portions->sole()->offer->producer;
        ProducerConsent::factory()->declined()->create(['producer_id' => $producer->id, 'purpose' => 'location_planning', 'recorded_at' => now()->addSecond()]);
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id, 'delivery_address' => CommerceScenario::address()]);
        $response = $this->actingAs($buyer)->get(route('marketplace.index', ['sort' => 'nearest']))->assertOk();
        $this->assertNull($response->viewData('lots')->first()->buyer_distance_km);
    }

    public function test_only_operators_can_see_private_origin_map_and_addresses(): void
    {
        $lot = $this->lot('-15.840123');
        foreach (['admin', 'coordinator'] as $role) {
            $operator = User::factory()->withRole($role)->create();
            $this->actingAs($operator)->get(route('lots.show', $lot))->assertOk()->assertSee('data-lot-origin-map', false)->assertSee('-15.840123')->assertSee('Parcela privada exclusiva 849');
        }
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id]);
        $this->actingAs($buyer)->get(route('lots.show', $lot))->assertForbidden();
        $this->get(route('marketplace.show', $lot->code))->assertOk()->assertDontSee('-15.840123')->assertDontSee('Parcela privada exclusiva 849');
    }

    public function test_map_keeps_origins_after_the_entire_lot_has_been_purchased(): void
    {
        $scenario = new CommerceScenario('80.000');
        $portion = $scenario->order->items->sole()->allocations->sole()->portion;
        $offer = $portion->offer;
        ProducerLocation::factory()->create(['producer_id' => $offer->producer_id, 'location_id' => $offer->location_id, 'latitude' => '-15.840123', 'longitude' => '-70.021900']);
        ProducerConsent::factory()->create(['producer_id' => $offer->producer_id, 'purpose' => 'location_planning']);
        $this->assertSame('0.000', $portion->availableQuantity());
        $this->actingAs($scenario->staff)->get(route('lots.show', $portion->lot_id))->assertOk()->assertSee('data-lot-origin-map', false)->assertSee('-15.840123');
    }
}
