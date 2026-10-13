<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\ProducerConsent;
use App\Models\ProducerLocation;
use App\Services\CollectionProximity;
use App\Services\CollectionService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class CollectionProximityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64), 'logistics.require_coordinates' => true, 'logistics.max_producer_distance_km' => 25]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
    }

    private function locate(Offer $offer, string $latitude): void
    {
        ProducerLocation::factory()->create(['producer_id' => $offer->producer_id, 'location_id' => $offer->location_id, 'latitude' => $latitude, 'longitude' => '-70.000000']);
        ProducerConsent::factory()->create(['producer_id' => $offer->producer_id, 'purpose' => 'location_planning']);
    }

    public function test_all_pairs_must_be_close_even_when_neighbours_form_a_chain(): void
    {
        $offers = Offer::factory()->count(3)->create();
        foreach (['-15.000000', '-15.180000', '-15.360000'] as $i => $latitude) {
            $this->locate($offers[$i], $latitude);
        }
        $service = app(CollectionProximity::class);
        $this->assertTrue($service->inspect($offers->take(2))['allowed']);
        $result = $service->inspect($offers);
        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(39, $result['max_distance_km']);
    }

    public function test_missing_revoked_or_other_locality_coordinates_cannot_group_producers(): void
    {
        $offers = Offer::factory()->count(2)->create();
        $service = app(CollectionProximity::class);
        $this->assertTrue($service->inspect($offers->take(1))['allowed']);
        $this->assertFalse($service->inspect($offers)['allowed']);
        foreach ($offers as $offer) {
            $this->locate($offer, '-15.000000');
            $offer->unsetRelation('producer');
        }
        $this->assertTrue($service->inspect($offers)['allowed']);
        ProducerConsent::factory()->declined()->create(['producer_id' => $offers[0]->producer_id, 'purpose' => 'location_planning', 'recorded_at' => now()->addSecond()]);
        $this->assertFalse($service->inspect($offers)['allowed']);
        $offers[1]->location_id = 999999;
        $this->assertNull($service->coordinates($offers[1]));
    }

    public function test_far_collection_point_is_not_recommended_and_cannot_be_scheduled(): void
    {
        $scenario = new CommerceScenario;
        $offer = $scenario->order->items->sole()->allocations->sole()->portion->offer;
        $this->locate($offer, '-15.000000');
        DB::table('collection_points')->where('id', $scenario->point->id)->update(['latitude' => '-19.000000', 'longitude' => '-70.000000']);
        $suggestion = app(CollectionService::class)->suggest($scenario->staff, $scenario->order);
        $this->assertNull($suggestion['recommended_point_id']);
        $this->assertGreaterThan(400, $suggestion['points']->first()['max_distance_km']);
        $this->expectException(ValidationException::class);
        $scenario->schedule();
    }

    public function test_close_point_is_recommended_and_schedule_accepts_it(): void
    {
        $scenario = new CommerceScenario;
        $this->locate($scenario->order->items->sole()->allocations->sole()->portion->offer, '-15.000000');
        DB::table('collection_points')->where('id', $scenario->point->id)->update(['latitude' => '-15.010000', 'longitude' => '-70.000000']);
        $suggestion = app(CollectionService::class)->suggest($scenario->staff, $scenario->order);
        $this->assertSame($scenario->point->id, $suggestion['recommended_point_id']);
        $this->assertSame('scheduled', $scenario->schedule()->status);
    }
}
