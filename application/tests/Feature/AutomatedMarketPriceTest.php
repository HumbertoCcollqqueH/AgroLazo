<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\MarketPrice;
use App\Models\Offer;
use App\Models\Product;
use App\Models\QualityGrade;
use App\Models\Unit;
use App\Models\User;
use App\Services\AutomatedMarketPriceProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomatedMarketPriceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'America/Lima'));
        config(['producers.lookup_key' => str_repeat('a', 64), 'market-pricing.enabled' => true]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        Http::preventStrayRequests();
    }

    private function payload(): array
    {
        return ['grafico2_precioMayorista_quinua' => [
            'labels' => ['14/09/26', '15/09/26', '22/09/26', '24/09/26'],
            'quinua_blanca' => [9.40, 9.42, 9.48, null],
            'quinua_negra' => [9.88, null, null, null],
            'quinua_roja' => [null, 9.85, null, null],
        ]];
    }

    private function offer(string $variety = 'blanca'): Offer
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $classification = $product->varieties()->firstOrCreate(['code' => $variety], ['name' => ucfirst($variety), 'active' => true]);

        return Offer::factory()->create([
            'product_id' => $product->id, 'variety_id' => $classification->id,
            'quality_grade_id' => $product->qualityGrades()->where('code', 'unspecified')->value('id'),
            'base_unit_id' => $product->base_unit_id, 'unit_id' => $product->base_unit_id,
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
            'status' => 'verified',
        ]);
    }

    private function cachePrices(): void
    {
        Cache::put(config('market-pricing.cache_key'), [
            'checked_at' => now()->toIso8601String(),
            'prices' => app(AutomatedMarketPriceProvider::class)->parse($this->payload()),
        ]);
    }

    public function test_last_non_null_observation_keeps_its_real_date(): void
    {
        $prices = app(AutomatedMarketPriceProvider::class)->parse($this->payload());
        $this->assertSame('9.480000', $prices['blanca']['unit_price']);
        $this->assertSame('2026-09-22', $prices['blanca']['observed_on']);
        $this->assertSame('2026-09-14', $prices['negra']['observed_on']);
        $this->assertSame('PEN', $prices['blanca']['currency']);
    }

    public function test_invalid_future_zero_and_non_numeric_observations_are_not_used(): void
    {
        $payload = ['grafico2_precioMayorista_quinua' => [
            'labels' => ['22/09/26', '27/09/26', '31/09/26', '25/09/26', '26/09/26'],
            'quinua_blanca' => [9.48, 100, 90, 0, 'unknown'],
            'quinua_negra' => [null, null, null, null, null],
            'quinua_roja' => [null, null, null, null, null],
        ]];
        $prices = app(AutomatedMarketPriceProvider::class)->parse($payload);
        $this->assertCount(1, $prices);
        $this->assertSame('9.480000', $prices['blanca']['unit_price']);
        $this->assertSame('2026-09-22', $prices['blanca']['observed_on']);
    }

    public function test_malformed_series_is_rejected_instead_of_mixing_date_and_price(): void
    {
        $payload = $this->payload();
        $payload['grafico2_precioMayorista_quinua']['quinua_blanca'] = [9.48];
        $prices = app(AutomatedMarketPriceProvider::class)->parse($payload);
        $this->assertArrayNotHasKey('blanca', $prices);
        $this->assertSame('2026-09-14', $prices['negra']['observed_on']);
    }

    public function test_offer_lookup_is_cached_and_refuses_stale_wrong_unit_or_unspecified_variety(): void
    {
        $this->cachePrices();
        $provider = app(AutomatedMarketPriceProvider::class);
        $offer = $this->offer();
        $this->assertSame('9.480000', $provider->forOffer($offer)['unit_price']);
        $this->assertNull($provider->forOffer($this->offer('negra')));
        $this->assertNull($provider->forOffer($this->offer('unspecified')));
        $offer->base_unit_id = Unit::query()->where('code', 't')->value('id');
        $offer->unsetRelation('baseUnit');
        $this->assertNull($provider->forOffer($offer));
        Http::assertNothingSent();
    }

    public function test_different_declared_quality_requires_its_own_reference(): void
    {
        $this->cachePrices();
        $offer = $this->offer();
        $offer->quality_grade_id = QualityGrade::factory()->create(['product_id' => $offer->product_id, 'code' => 'extra'])->id;
        $this->assertNull(app(AutomatedMarketPriceProvider::class)->forOffer($offer));
    }

    public function test_real_exact_manual_observation_can_cover_another_product_but_synthetic_cannot(): void
    {
        $offer = $this->offer('unspecified');
        $fields = [...$offer->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id']),
            'unit_id' => $offer->base_unit_id, 'unit_price' => '5.250000'];
        MarketPrice::factory()->create([...$fields, 'synthetic' => true]);
        $this->assertNull(app(AutomatedMarketPriceProvider::class)->forOffer($offer));
        MarketPrice::factory()->create([...$fields, 'synthetic' => false]);
        $reference = app(AutomatedMarketPriceProvider::class)->forOffer($offer);
        $this->assertSame('5.250000', $reference['unit_price']);
        $this->assertSame('manual', $reference['provider']);
    }

    public function test_failed_refresh_preserves_previous_data_without_refreshing_observation_age(): void
    {
        $this->cachePrices();
        Http::fake(['*' => Http::response([], 503)]);
        try {
            app(AutomatedMarketPriceProvider::class)->refresh();
            $this->fail('La actualización debía fallar.');
        } catch (\RuntimeException) {
            $this->assertSame('2026-09-22', app(AutomatedMarketPriceProvider::class)->snapshot()['prices']['blanca']['observed_on']);
        }
        $this->travel(8)->days();
        $this->assertNull(app(AutomatedMarketPriceProvider::class)->forOffer($this->offer()));
        $this->assertNotNull(app(AutomatedMarketPriceProvider::class)->status()['error']);
    }

    public function test_disabled_automation_never_proposes_a_price(): void
    {
        $this->cachePrices();
        config(['market-pricing.enabled' => false]);
        $this->assertNull(app(AutomatedMarketPriceProvider::class)->forOffer($this->offer()));
        $this->assertSame([], app(AutomatedMarketPriceProvider::class)->classificationChoices($this->offer('unspecified')));
    }

    public function test_operator_sees_source_date_and_stale_status_in_pricing_page(): void
    {
        $this->cachePrices();
        $this->actingAs(User::factory()->withRole('admin')->create())
            ->get(route('commerce.pricing'))->assertOk()->assertSee('Precio automático al productor')
            ->assertSee('22/09/2026')->assertSee('Antigua: no se usa para proponer precios');
    }
}
