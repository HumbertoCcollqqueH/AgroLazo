<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\PricingPolicy;
use App\Models\Producer;
use App\Models\ProducerConsent;
use App\Models\ProducerLocation;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Quote;
use App\Models\User;
use App\Services\AggregationService;
use App\Services\AutomaticLotGrouping;
use App\Services\CheckoutService;
use App\Services\LotAvailability;
use App\Services\OfferPublication;
use App\Services\PricingService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class AutomaticLotGroupingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private User $buyer;

    private Product $product;

    public function test_automatic_publication_keeps_distant_producers_separate_even_in_same_locality(): void
    {
        config(['logistics.require_coordinates' => true]);
        $a = $this->offer('20.000');
        $b = $this->offer('40.000');
        foreach ([[$a, '-15.000000'], [$b, '-19.000000']] as [$offer, $latitude]) {
            ProducerLocation::factory()->create(['producer_id' => $offer->producer_id, 'location_id' => $offer->location_id, 'latitude' => $latitude, 'longitude' => '-70.000000']);
            ProducerConsent::factory()->create(['producer_id' => $offer->producer_id, 'purpose' => 'location_planning']);
        }
        $first = $this->publish($a);
        $second = $this->publish($b);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('published', $first->fresh()->status);
        $this->assertSame(1, $second->portions()->count());
    }

    public function test_automatic_publication_groups_nearby_authorized_producers(): void
    {
        config(['logistics.require_coordinates' => true]);
        $a = $this->offer('20.000');
        $b = $this->offer('40.000');
        foreach ([[$a, '-15.000000'], [$b, '-15.010000']] as [$offer, $latitude]) {
            ProducerLocation::factory()->create(['producer_id' => $offer->producer_id, 'location_id' => $offer->location_id, 'latitude' => $latitude, 'longitude' => '-70.000000']);
            ProducerConsent::factory()->create(['producer_id' => $offer->producer_id, 'purpose' => 'location_planning']);
        }
        $first = $this->publish($a);
        $second = $this->publish($b);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame(2, $second->portions()->count());
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $this->buyer->id]);
        $this->product = Product::query()->where('slug', 'quinua')->firstOrFail();
        Http::preventStrayRequests();
    }

    private function offer(string $quantity, array $attributes = [], string $price = '5.000000'): Offer
    {
        return Offer::factory()->published($price)->create([
            'status' => 'verified', 'registered_by' => $this->staff->id,
            'product_id' => $this->product->id, 'unit_id' => $this->product->base_unit_id, 'base_unit_id' => $this->product->base_unit_id,
            'variety_id' => $this->product->varieties()->orderBy('id')->value('id'), 'quality_grade_id' => $this->product->qualityGrades()->orderBy('id')->value('id'),
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
            'quantity' => $quantity, 'normalized_quantity' => $quantity, ...$attributes,
        ]);
    }

    private function data(Offer $offer): array
    {
        return ['source_key' => (string) Str::uuid(), 'version' => $offer->version,
            'agreement_id' => $offer->activeAgreement()->id, 'reason' => 'Publicación de producción sintética compatible'];
    }

    private function publish(Offer $offer): Lot
    {
        return app(OfferPublication::class)->publish($this->staff, $offer, $this->data($offer));
    }

    private function manualLot(Offer $offer): Lot
    {
        $offer->update(['status' => 'published']);
        $lot = app(AggregationService::class)->create($this->staff, [
            'source_key' => (string) Str::uuid(), 'reason' => 'Lote sintético previo para comprobar agrupación',
            'portions' => [['offer_id' => $offer->id, 'offer_version' => $offer->version,
                'agreement_id' => $offer->activeAgreement()->id, 'quantity' => $offer->normalized_quantity]],
        ]);

        return app(AggregationService::class)->transition($this->staff, $lot, [
            'version' => $lot->version, 'result' => 'published', 'reason' => 'Publicación sintética previa',
        ]);
    }

    private function quote(Lot $lot, string $quantity): Quote
    {
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id]);

        return app(PricingService::class)->issue($this->buyer, $lot, [
            'key' => (string) Str::uuid(), 'quantity' => $quantity, 'policy_id' => $policy->id,
            'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Retiro sintético coordinado en Puno', 'payment_method' => 'coordinated',
        ]);
    }

    private function available(Lot $lot): string
    {
        return (string) app(LotAvailability::class)->marketplace()->whereKey($lot->id)->value('availability.available_quantity');
    }

    public function test_generated_catalog_never_merges_into_real_producer_lots(): void
    {
        $generated = Producer::factory()->verified()->create(['seed_batch' => 'catalogo-2026-10-06']);
        $first = $this->publish($this->offer('40.000'));
        $second = $this->publish($this->offer('60.000', ['producer_id' => $generated->id]));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('40.000', $this->available($first));
        $this->assertSame('60.000', $this->available($second));
        $this->assertNull(app(AutomaticLotGrouping::class)->consolidate($this->staff, $first, true)['lot']);
        $this->assertNull(app(AutomaticLotGrouping::class)->consolidate($this->staff, $second, true)['lot']);
    }

    public function test_new_compatible_offer_combines_twenty_and_forty_for_a_sixty_kilogram_purchase(): void
    {
        $first = $this->offer('20.000');
        $firstLot = $this->publish($first);
        $original = $firstLot->portions()->sole();
        $second = $this->offer('40.000', ['producer_id' => $first->producer_id]);
        $lot = $this->publish($second);

        $this->assertSame('60.000', $this->available($lot));
        $this->assertSame(1, app(LotAvailability::class)->marketplace()->count());
        $this->assertSame('cancelled', $firstLot->fresh()->status);
        $this->assertSame('20.000', $original->fresh()->released_quantity);
        $this->assertSame('20.000', $original->fresh()->quantity);
        $this->assertSame([$first->id, $second->id], $lot->portions()->pluck('offer_id')->all());
        $this->assertSame([$first->activeAgreement()->id, $second->activeAgreement()->id], $lot->portions()->pluck('agreement_id')->all());
        $this->assertDatabaseHas('lot_histories', ['lot_id' => $firstLot->id, 'action' => 'available_balance_grouped']);
        $quote = $this->quote($lot, '60.000');
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]);
        $this->assertSame('60.000', $order->items()->sole()->quantity);
        $this->assertSame(2, $order->items()->sole()->allocations()->count());
        $this->assertSame(0, app(LotAvailability::class)->marketplace()->count());
        Http::assertNothingSent();
    }

    public function test_compatible_producers_share_a_commercial_lot_without_losing_their_sources(): void
    {
        $first = $this->offer('20.000');
        $this->publish($first);
        $second = $this->offer('40.000');
        $lot = $this->publish($second);

        $this->assertNotSame($first->producer_id, $second->producer_id);
        $this->assertSame('60.000', $this->available($lot));
        $this->assertSame(2, (int) app(LotAvailability::class)->marketplace()->whereKey($lot->id)->value('availability.producer_count'));
    }

    public function test_overlapping_windows_use_the_common_availability(): void
    {
        $this->publish($this->offer('20.000', ['available_until' => now('America/Lima')->addDays(2)->toDateString()]));
        $lot = $this->publish($this->offer('40.000', ['available_until' => now('America/Lima')->addDays(6)->toDateString()]));

        $this->assertSame('60.000', $this->available($lot));
        $this->assertSame(now('America/Lima')->addDays(2)->toDateString(), $lot->available_until->toDateString());
    }

    public function test_different_variety_quality_locality_price_and_disjoint_dates_stay_separate(): void
    {
        $firstLot = $this->publish($this->offer('20.000', ['available_until' => now('America/Lima')->addDay()->toDateString()]));
        foreach ([
            ['variety_id' => ProductVariety::factory()->create(['product_id' => $this->product->id])->id],
            ['quality_grade_id' => QualityGrade::factory()->create(['product_id' => $this->product->id])->id],
            ['location_id' => Location::factory()->create()->id],
            ['available_from' => now('America/Lima')->addDays(3)->toDateString()],
        ] as $attributes) {
            $lot = $this->publish($this->offer('40.000', $attributes));
            $this->assertSame('40.000', $this->available($lot));
            $this->assertSame('20.000', $this->available($firstLot));
        }
        $differentPrice = $this->publish($this->offer('40.000', price: '6.000000'));
        $this->assertSame('40.000', $this->available($differentPrice));
        $this->assertSame('20.000', $this->available($firstLot));
    }

    public function test_live_quotes_are_preserved_and_remain_purchasable(): void
    {
        $firstLot = $this->publish($this->offer('20.000'));
        $quote = $this->quote($firstLot, '20.000');
        $newLot = $this->publish($this->offer('40.000'));

        $this->assertSame('20.000', $this->available($firstLot));
        $this->assertSame('40.000', $this->available($newLot));
        $this->assertSame($quote->lot_version, $firstLot->fresh()->version);
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]);
        $this->assertSame('confirmed', $order->status);
    }

    public function test_reservations_remain_usable_and_are_never_moved(): void
    {
        $firstLot = $this->publish($this->offer('20.000'));
        $quote = $this->quote($firstLot, '10.000');
        $reservation = app(CheckoutService::class)->reserve($this->buyer, $quote, ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]);
        $newLot = $this->publish($this->offer('40.000'));

        $this->assertSame('10.000', $this->available($firstLot));
        $this->assertSame('40.000', $this->available($newLot));
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString(), 'reservation_id' => $reservation->id]);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('10.000', $order->items()->sole()->quantity);
    }

    public function test_fully_purchased_stock_does_not_reappear_in_the_group(): void
    {
        $soldLot = $this->publish($this->offer('40.000'));
        $quote = $this->quote($soldLot, '40.000');
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]);
        $before = $order->items()->sole()->allocations()->sole()->getAttributes();
        $lot = $this->publish($this->offer('20.000'));

        $this->assertSame('20.000', $this->available($lot));
        $this->assertSame($before, $order->items()->sole()->allocations()->sole()->getAttributes());
        $this->assertSame('0.000', $soldLot->portions()->sole()->released_quantity);
        $this->assertSame(1, app(LotAvailability::class)->marketplace()->count());
    }

    public function test_only_unsold_balance_of_a_partially_purchased_lot_is_grouped(): void
    {
        $source = $this->offer('40.000');
        $oldLot = $this->publish($source);
        $quote = $this->quote($oldLot, '30.000');
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]);
        $allocation = $order->items()->sole()->allocations()->sole();
        $originalAllocation = $allocation->getAttributes();
        $lot = $this->publish($this->offer('20.000'));

        $this->assertSame('30.000', $this->available($lot));
        $this->assertSame('10.000', $lot->portions()->where('offer_id', $source->id)->sole()->quantity);
        $this->assertSame('10.000', $oldLot->portions()->sole()->released_quantity);
        $this->assertSame('40.000', $oldLot->portions()->sole()->quantity);
        $this->assertSame('published', $oldLot->fresh()->status);
        $this->assertSame($originalAllocation, $allocation->fresh()->getAttributes());
        $this->assertSame($oldLot->id, $order->items()->sole()->lot_id);
    }

    public function test_retired_stock_is_never_returned_to_the_market(): void
    {
        $oldLot = $this->publish($this->offer('40.000'));
        $oldLot->portions()->sole()->update(['retired_quantity' => '10.000']);
        $lot = $this->publish($this->offer('20.000'));

        $this->assertSame('50.000', $this->available($lot));
        $this->assertSame('30.000', $oldLot->portions()->sole()->released_quantity);
        $this->assertSame('10.000', $oldLot->portions()->sole()->retired_quantity);
    }

    public function test_grouped_publication_retries_are_idempotent_and_cannot_impersonate_another_source(): void
    {
        $first = $this->offer('20.000');
        $this->publish($first);
        $second = $this->offer('40.000');
        $data = $this->data($second);
        $lot = app(OfferPublication::class)->publish($this->staff, $second, $data);
        $retry = app(OfferPublication::class)->publish($this->staff, $second, $data);

        $this->assertSame($lot->id, $retry->id);
        $this->assertSame('60.000', $this->available($lot));
        $this->assertDatabaseCount('lots', 2);
        $this->assertDatabaseCount('lot_offers', 3);
        $this->expectException(ValidationException::class);
        app(OfferPublication::class)->publish($this->staff, $first, [...$this->data($first), 'source_key' => $data['source_key']]);
    }

    public function test_existing_separate_lots_can_be_previewed_then_consolidated_without_duplicate_stock(): void
    {
        $first = $this->manualLot($this->offer('20.000'));
        $second = $this->manualLot($this->offer('40.000'));
        $preview = app(AutomaticLotGrouping::class)->consolidate($this->staff, $first);

        $this->assertEqualsCanonicalizing([$first->code, $second->code], $preview['lots']);
        $this->assertSame('60.000', $preview['quantity']);
        $this->assertNull($preview['lot']);
        $this->assertSame('20.000', $this->available($first));
        $this->assertDatabaseCount('lots', 2);
        $applied = app(AutomaticLotGrouping::class)->consolidate($this->staff, $first, true);
        $this->assertSame('60.000', $this->available($applied['lot']));
        $this->assertSame(1, app(LotAvailability::class)->marketplace()->count());
        $this->assertSame([], app(AutomaticLotGrouping::class)->consolidate($this->staff, $first, true)['lots']);
        $this->assertDatabaseCount('lots', 3);
    }

    public function test_the_command_defaults_to_preview_and_requires_an_authorized_operator(): void
    {
        $first = $this->manualLot($this->offer('20.000'));
        $this->manualLot($this->offer('40.000'));
        $this->artisan('lots:consolidate', ['--actor' => $this->buyer->id])->assertFailed();
        $this->artisan('lots:consolidate', ['--actor' => $this->staff->id])->expectsOutput('Grupos propuestos: 1.')->assertSuccessful();
        $this->assertDatabaseCount('lots', 2);
        $this->artisan('lots:consolidate', ['--actor' => $this->staff->id, '--lot' => $first->id, '--apply' => true])
            ->expectsOutput('Grupos publicados: 1.')->assertSuccessful();
        $this->assertSame(1, app(LotAvailability::class)->marketplace()->count());
    }

    public function test_expired_quotes_do_not_permanently_prevent_consolidation(): void
    {
        $first = $this->manualLot($this->offer('20.000'));
        $quote = $this->quote($first, '20.000');
        $second = $this->manualLot($this->offer('40.000'));
        $this->assertSame([], app(AutomaticLotGrouping::class)->consolidate($this->staff, $first)['lots']);
        DB::table('quotes')->where('id', $quote->id)->update(['issued_at' => now()->subMinutes(21), 'expires_at' => now()->subSecond()]);
        $result = app(AutomaticLotGrouping::class)->consolidate($this->staff, $second, true);

        $this->assertSame('60.000', $this->available($result['lot']));
    }

    public function test_automatic_recovery_groups_after_quote_expiry_and_respects_operator_access(): void
    {
        $first = $this->manualLot($this->offer('20.000'));
        $quote = $this->quote($first, '10.000');
        $this->manualLot($this->offer('40.000'));
        $this->artisan('lots:consolidate', ['--automatic' => true])->assertSuccessful();
        $this->assertDatabaseCount('lots', 2);

        DB::table('quotes')->where('id', $quote->id)->update(['issued_at' => now()->subMinutes(21), 'expires_at' => now()->subSecond()]);
        $this->staff->forceFill(['active' => false])->save();
        $this->artisan('lots:consolidate', ['--automatic' => true])->assertSuccessful();
        $this->assertDatabaseCount('lots', 2);

        $this->staff->forceFill(['active' => true])->save();
        $this->artisan('lots:consolidate', ['--automatic' => true])->expectsOutput('Grupos publicados: 1.')->assertSuccessful();
        $this->assertDatabaseCount('lots', 3);
        $this->assertSame('60.000', (string) app(LotAvailability::class)->marketplace()->sole()->available_quantity);
    }
}
