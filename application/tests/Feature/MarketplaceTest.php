<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Producer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Unit;
use App\Models\User;
use App\Services\AggregationService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Product $product;

    private Location $region;

    private Location $province;

    private Location $district;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $this->region = Location::query()->where('name', 'Puno')->firstOrFail();
        $this->province = Location::factory()->create(['name' => 'San Román', 'type' => 'province', 'parent_id' => $this->region->id]);
        $this->district = Location::factory()->create(['name' => 'Cabana', 'type' => 'district', 'parent_id' => $this->province->id]);
        Http::preventStrayRequests();
    }

    private function offer(array $attributes = [], string $price = '5.000000'): Offer
    {
        return Offer::factory()->published($price)->create([
            'product_id' => $this->product->id,
            'variety_id' => $this->product->varieties()->value('id'),
            'quality_grade_id' => $this->product->qualityGrades()->value('id'),
            'base_unit_id' => $this->product->base_unit_id, 'unit_id' => $this->product->base_unit_id,
            'location_id' => $this->district->id, 'registered_by' => $this->staff->id,
            'notes' => 'Observación privada que nunca debe aparecer en el catálogo', ...$attributes,
        ]);
    }

    private function lot(Offer $offer, string $quantity = '40.000', bool $publish = true): Lot
    {
        $lot = app(AggregationService::class)->create($this->staff, [
            'source_key' => (string) Str::uuid(), 'reason' => 'Lote sintético para probar el catálogo',
            'portions' => [['offer_id' => $offer->id, 'quantity' => $quantity, 'offer_version' => $offer->version, 'agreement_id' => $offer->activeAgreement()->id]],
        ]);

        return $publish ? $this->transition($lot, 'published') : $lot;
    }

    private function transition(Lot $lot, string $result): Lot
    {
        return app(AggregationService::class)->transition($this->staff, $lot, ['version' => $lot->version, 'result' => $result, 'reason' => 'Cambio sintético de estado del lote']);
    }

    public function test_visitors_can_browse_only_published_eligible_lots(): void
    {
        $published = $this->lot($this->offer());
        $draft = $this->lot($this->offer(), publish: false);
        $this->get(route('marketplace.index'))->assertOk()->assertSee($published->code)->assertDontSee($draft->code);
        $this->get(route('marketplace.show', $published->code))->assertOk()->assertSee('Conoce el origen')->assertSee('San Román');
        $this->get(route('marketplace.show', $draft->code))->assertNotFound();
        $this->getJson(route('api.marketplace.show', $draft->code))->assertNotFound();
    }

    public function test_public_resource_is_an_explicit_projection_without_private_identity_or_terms(): void
    {
        $offer = $this->offer();
        $lot = $this->lot($offer);
        $response = $this->getJson(route('api.marketplace.show', $lot->code))->assertOk()
            ->assertJsonPath('data.code', $lot->code)
            ->assertJsonPath('data.available_quantity', '40.000')
            ->assertJsonPath('data.producer_count', 1)
            ->assertJsonPath('data.estimated_unit_price.is_quote', false)
            ->assertJsonPath('data.purchasing_enabled', false)
            ->assertJsonPath('data.traceability.physical_receipt_confirmed', false);
        $response->assertJsonPath('data.demo_checkout_enabled', true);
        $this->assertSame(['code', 'product', 'variety', 'quality', 'location', 'unit', 'available_quantity', 'producer_count', 'available_from', 'available_until', 'estimated_unit_price', 'traceability', 'purchasing_enabled', 'demo_checkout_enabled', 'checkout_url', 'url'], array_keys($response->json('data')));
        foreach ([$offer->producer->full_name, $offer->producer->dni, $offer->producer->phone, $offer->notes, $offer->activeAgreement()->terms, 'producer_unit_cost', 'net_unit_price', 'source_hash', 'agreement_id', 'offer_id', 'photo_path'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->get(route('marketplace.show', $lot->code))->assertDontSee($offer->producer->full_name)->assertDontSee($offer->notes);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_all_required_filters_match_the_actual_lot_composition(): void
    {
        $offer = $this->offer();
        $lot = $this->lot($offer);
        $filters = [
            'product_id' => $offer->product_id, 'variety_id' => $offer->variety_id, 'quality_grade_id' => $offer->quality_grade_id,
            'region_id' => $this->region->id, 'province_id' => $this->province->id,
            'base_unit_id' => $offer->base_unit_id, 'min_quantity' => '40.000',
            'min_price' => '5.000000', 'max_price' => '5.000000',
            'date' => now('America/Lima')->toDateString(), 'availability' => 'now',
        ];
        $this->getJson(route('api.marketplace.index', $filters))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $lot->code);
        $this->get(route('marketplace.index', $filters))->assertOk()->assertSee($lot->code);
        foreach ([
            ['product_id' => Product::query()->where('slug', 'papa')->value('id')],
            ['min_quantity' => '40.001'], ['min_price' => '5.000001', 'max_price' => '6'], ['min_price' => '4', 'max_price' => '4.999999'],
            ['date' => now('America/Lima')->addDays(10)->toDateString()], ['availability' => 'upcoming'],
        ] as $mismatch) {
            $this->getJson(route('api.marketplace.index', [...$filters, ...$mismatch]))->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_region_and_province_filters_use_ancestors_and_reject_wrong_location_types(): void
    {
        $this->lot($this->offer());
        $otherProvince = Location::factory()->create(['type' => 'province', 'parent_id' => $this->region->id]);
        $this->getJson(route('api.marketplace.index', ['province_id' => $otherProvince->id]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.marketplace.index', ['province_id' => $this->district->id]))->assertUnprocessable()->assertJsonValidationErrors('province_id');
        $this->getJson(route('api.marketplace.index', ['region_id' => $this->province->id]))->assertUnprocessable()->assertJsonValidationErrors('region_id');
    }

    public function test_suspension_expiry_and_catalog_deactivation_hide_lots_immediately(): void
    {
        $offer = $this->offer();
        $lot = $this->lot($offer);
        foreach ([$offer->producer, $offer->product, $offer->product->category, $offer->baseUnit, $offer->variety, $offer->qualityGrade, $this->region] as $source) {
            $field = $source instanceof Producer ? 'status' : 'active';
            $original = $source->getAttribute($field);
            $source->update([$field => $field === 'status' ? 'suspended' : false]);
            $this->getJson(route('api.marketplace.index'))->assertOk()->assertJsonCount(0, 'data');
            $this->get(route('marketplace.show', $lot->code))->assertNotFound();
            $source->update([$field => $original]);
        }
        DB::table('offer_price_agreements')->where('offer_id', $offer->id)->update(['valid_until' => now()->subSecond()]);
        $this->getJson(route('api.marketplace.show', $lot->code))->assertNotFound();
        $this->assertSame('published', $lot->fresh()->status);
        $this->assertSame('0.000', $lot->portions->first()->released_quantity);
    }

    public function test_one_ineligible_source_hides_the_whole_reviewed_composition_without_releasing_it(): void
    {
        $first = $this->offer();
        $second = $this->offer();
        $lot = app(AggregationService::class)->create($this->staff, [
            'source_key' => (string) Str::uuid(), 'reason' => 'Composición de dos fuentes sintéticas',
            'portions' => array_map(fn (Offer $offer): array => ['offer_id' => $offer->id, 'quantity' => '20', 'offer_version' => 1, 'agreement_id' => $offer->activeAgreement()->id], [$first, $second]),
        ]);
        $lot = $this->transition($lot, 'published');
        $second->producer->update(['status' => 'suspended']);
        $this->getJson(route('api.marketplace.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('20.000', $lot->portions->first()->capacity());
        $this->assertSame('published', $lot->fresh()->status);
    }

    public function test_new_or_foreign_agreements_and_offer_versions_cannot_silently_change_public_pricing(): void
    {
        $offer = $this->offer();
        $lot = $this->lot($offer);
        OfferPriceAgreement::factory()->create(['offer_id' => $offer->id, 'offer_version' => 1, 'version' => 2]);
        $this->getJson(route('api.marketplace.show', $lot->code))->assertNotFound();
        $second = $this->offer();
        $secondLot = $this->lot($second);
        DB::table('offers')->where('id', $second->id)->update(['version' => 2]);
        $this->getJson(route('api.marketplace.show', $secondLot->code))->assertNotFound();
    }

    public function test_pausing_cancelling_and_elapsed_windows_are_not_visible(): void
    {
        $lot = $this->lot($this->offer());
        $paused = $this->transition($lot, 'paused');
        $this->getJson(route('api.marketplace.show', $lot->code))->assertNotFound();
        $published = $this->transition($paused, 'published');
        $this->getJson(route('api.marketplace.show', $lot->code))->assertOk();
        $this->transition($published, 'cancelled');
        $this->getJson(route('api.marketplace.show', $lot->code))->assertNotFound();
        $expired = $this->lot($this->offer());
        DB::table('lots')->where('id', $expired->id)->update(['available_from' => now('America/Lima')->subDays(2)->toDateString(), 'available_until' => now('America/Lima')->subDay()->toDateString()]);
        $this->getJson(route('api.marketplace.show', $expired->code))->assertNotFound();
    }

    public function test_upcoming_availability_is_distinct_from_current_stock(): void
    {
        $lot = $this->lot($this->offer(['available_from' => now('America/Lima')->addDays(2)->toDateString()]));
        $this->getJson(route('api.marketplace.index', ['availability' => 'upcoming']))->assertOk()->assertJsonPath('data.0.code', $lot->code);
        $this->getJson(route('api.marketplace.index', ['availability' => 'now']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.marketplace.index', ['date' => now('America/Lima')->toDateString()]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_partial_quantity_estimation_uses_exact_decimals_without_creating_a_purchase(): void
    {
        $lot = $this->lot($this->offer([], '5.000001'), '80');
        $this->get(route('marketplace.show', ['code' => $lot->code, 'quantity' => '50.125']))
            ->assertOk()->assertSee('S/ 250.63 – 250.63')->assertSee('no es una cotización')
            ->assertSee('Cotizar para comprar o reservar')->assertSee(route('commerce.quotes.create', $lot), false);
        $this->assertDatabaseCount('lot_offers', 1);
        $this->assertSame('80.000', $lot->portions->first()->capacity());
        Http::assertNothingSent();
    }

    public function test_quantity_errors_do_not_render_a_misleading_total(): void
    {
        $lot = $this->lot($this->offer());
        foreach (['0', '40.001', '1e3', '-2', '0.0001', ['bad']] as $quantity) {
            $this->get(route('marketplace.show', ['code' => $lot->code, 'quantity' => $quantity]))
                ->assertOk()->assertSee('Indica una cantidad')->assertDontSee('S/ 200.00 – 200.00');
        }
        $this->assertDatabaseCount('lot_offers', 1);
    }

    public function test_sorting_and_pagination_preserve_filters(): void
    {
        $offer = $this->offer(['quantity' => '1000.000', 'normalized_quantity' => '1000.000']);
        for ($i = 0; $i < 13; $i++) {
            $this->lot($offer, (string) (10 + $i));
        }
        $response = $this->getJson(route('api.marketplace.index', ['min_quantity' => '10', 'sort' => 'quantity_desc']))->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.total', 13)->assertJsonPath('data.0.available_quantity', '22.000');
        $this->assertStringContainsString('min_quantity=10', $response->json('links.next'));
        $this->getJson($response->json('links.next'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.available_quantity', '10.000');
        $this->get(route('marketplace.index', ['sort' => 'quantity_desc']))->assertOk()
            ->assertViewHas('lots', fn ($lots): bool => $lots->total() === 13 && $lots->count() === 12);
    }

    public function test_producer_count_is_distinct_when_one_producer_has_several_offers(): void
    {
        $first = $this->offer();
        $second = $this->offer(['producer_id' => $first->producer_id]);
        $lot = app(AggregationService::class)->create($this->staff, [
            'source_key' => (string) Str::uuid(), 'reason' => 'Dos ofertas del mismo productor sintético',
            'portions' => array_map(fn (Offer $offer): array => ['offer_id' => $offer->id, 'quantity' => '20', 'offer_version' => 1, 'agreement_id' => $offer->activeAgreement()->id], [$first, $second]),
        ]);
        $lot = $this->transition($lot, 'published');
        $this->getJson(route('api.marketplace.show', $lot->code))->assertOk()->assertJsonPath('data.producer_count', 1)->assertJsonPath('data.available_quantity', '40.000');
    }

    public function test_invalid_filters_are_rejected_and_empty_results_have_a_clear_state(): void
    {
        $this->get(route('marketplace.index'))->assertOk()->assertSee('No encontramos lotes');
        foreach ([
            ['min_price' => '10', 'max_price' => '5'],
            ['min_quantity' => '-1'], ['max_price' => 'NaN'],
            ['date' => '2026-02-30'], ['page' => '0'], ['sort' => 'private_data'],
        ] as $filters) {
            $this->getJson(route('api.marketplace.index', $filters))->assertUnprocessable();
        }
    }

    public function test_buyer_profiles_can_consult_but_no_purchase_or_reservation_endpoint_is_enabled(): void
    {
        $lot = $this->lot($this->offer());
        $this->actingAs(User::factory()->withRole('buyer')->create());
        $this->get(route('marketplace.show', $lot->code))->assertOk();
        $this->postJson('/api/v1/marketplace/lots/'.$lot->code.'/purchase', ['quantity' => '20'])->assertNotFound();
        $this->postJson('/api/v1/marketplace/lots/'.$lot->code.'/reserve', ['quantity' => '20'])->assertNotFound();
        $this->assertDatabaseCount('lot_offers', 1);
    }

    public function test_counted_product_estimates_reject_fractional_units(): void
    {
        $unit = Unit::query()->where('code', 'unidad')->firstOrFail();
        $this->product = Product::factory()->create(['base_unit_id' => $unit->id]);
        ProductVariety::factory()->create(['product_id' => $this->product->id]);
        QualityGrade::factory()->create(['product_id' => $this->product->id]);
        $lot = $this->lot($this->offer());
        $this->get(route('marketplace.show', ['code' => $lot->code, 'quantity' => '1.5']))->assertOk()->assertSee('unidades enteras')->assertSee('Indica una cantidad');
        $this->get(route('marketplace.show', ['code' => $lot->code, 'quantity' => '2']))->assertOk()->assertSee('S/ 10.00 – 10.00');
        $this->getJson(route('api.marketplace.show', $lot->code))->assertJsonPath('data.unit.code', 'unidad');
    }

    public function test_reduced_source_capacity_cannot_leave_overstated_public_stock(): void
    {
        $offer = $this->offer();
        $lot = $this->lot($offer, '60');
        DB::table('offers')->where('id', $offer->id)->update(['withdrawn_quantity' => '30.000']);
        $this->getJson(route('api.marketplace.show', $lot->code))->assertNotFound();
        $this->getJson(route('api.marketplace.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('60.000', $lot->portions->first()->capacity());
    }

    public function test_product_search_is_case_insensitive_and_can_match_a_variety(): void
    {
        $variety = $this->product->varieties()->firstOrFail();
        $variety->update(['name' => 'Blanca comercial']);
        $lot = $this->lot($this->offer());

        foreach (['  QUINUA  ', 'blanca'] as $search) {
            $this->getJson(route('api.marketplace.index', ['q' => $search]))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $lot->code);
        }

        $this->getJson(route('api.marketplace.index', ['q' => 'Papa']))->assertOk()->assertJsonCount(0, 'data');
        $this->product->update(['active' => false]);
        $this->getJson(route('api.marketplace.index', ['q' => 'Quinua']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_wildcards_are_literal_and_invalid_searches_are_rejected(): void
    {
        $this->lot($this->offer());
        foreach (['%', '_', '!'] as $search) {
            $this->getJson(route('api.marketplace.index', ['q' => $search]))->assertOk()->assertJsonCount(0, 'data');
        }
        foreach ([str_repeat('q', 81), ['quinua']] as $search) {
            $this->getJson(route('api.marketplace.index', ['q' => $search]))->assertUnprocessable()->assertJsonValidationErrors('q');
        }
    }

    public function test_product_navigation_counts_only_eligible_published_lots_regardless_of_current_search(): void
    {
        $this->lot($this->offer());
        $this->lot($this->offer(), publish: false);

        $this->get(route('marketplace.index', ['q' => 'Papa']))->assertOk()
            ->assertViewHas('availableLotCount', 1)
            ->assertViewHas('lots', fn ($lots): bool => $lots->total() === 0)
            ->assertViewHas('categoryProducts', fn ($products): bool => $products->firstWhere('slug', 'quinua')->available_lots_count === 1
                && $products->firstWhere('slug', 'papa')->available_lots_count === 0);
    }
}
