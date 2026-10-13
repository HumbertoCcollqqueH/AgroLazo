<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Unit;
use App\Models\User;
use App\Services\AggregationService;
use App\Services\LotAvailability;
use App\Services\OfferRegistry;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class LotManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->product = Product::query()->where('slug', 'quinua')->firstOrFail();
        Http::preventStrayRequests();
    }

    private function offer(array $attributes = [], string $price = '5.000000'): Offer
    {
        return Offer::factory()->published($price)->create([
            'product_id' => $this->product->id, 'base_unit_id' => $this->product->base_unit_id,
            'unit_id' => $this->product->base_unit_id,
            'variety_id' => $this->product->varieties()->value('id'),
            'quality_grade_id' => $this->product->qualityGrades()->value('id'),
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
            'registered_by' => $this->staff->id, ...$attributes,
        ]);
    }

    private function payload(array $sources, array $extra = []): array
    {
        return [
            'source_key' => (string) Str::uuid(),
            'portions' => array_map(fn (array $entry): array => [
                'offer_id' => $entry[0]->id, 'quantity' => $entry[1], 'offer_version' => $entry[0]->version,
                'agreement_id' => $entry[0]->activeAgreement()->id,
            ], $sources),
            'reason' => 'Composición sintética revisada por el coordinador', ...$extra,
        ];
    }

    private function create(array $sources, array $extra = []): Lot
    {
        return app(AggregationService::class)->create($this->staff, $this->payload($sources, $extra));
    }

    private function transition(Lot $lot, string $result): Lot
    {
        return app(AggregationService::class)->transition($this->staff, $lot, ['version' => $lot->version, 'result' => $result, 'reason' => 'Decisión sintética documentada']);
    }

    private function rejected(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Se esperaba rechazo de la operación.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_private_routes_and_writes_enforce_roles(): void
    {
        $lot = $this->create([[$this->offer(), '40']]);
        $this->get(route('lots.index'))->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->withRole('buyer')->create());
        foreach (['index', 'create', 'show'] as $action) {
            $this->get(route('lots.'.$action, $lot))->assertForbidden();
        }
        $this->postJson(route('lots.store'), [])->assertForbidden();
        $this->postJson(route('lots.transition', $lot), [])->assertForbidden();
        $this->staff->forceFill(['active' => false])->save();
        $this->actingAs($this->staff)->get(route('lots.index'))->assertRedirectToRoute('login');
        $this->assertDatabaseCount('lots', 1);
    }

    public function test_exact_partial_portions_preserve_agreements_and_private_history(): void
    {
        $first = $this->offer(['quantity' => '80.125', 'normalized_quantity' => '80.125']);
        $second = $this->offer();
        $lot = $this->create([[$first, '40.125'], [$second, '10']]);
        $this->assertSame('draft', $lot->status);
        $this->assertSame('50.125', (string) app(LotAvailability::class)->eligibleLots()->whereKey($lot->id)->firstOrFail()->available_quantity);
        $this->assertSame('40.000', app(LotAvailability::class)->ungroupedQuantity($first));
        $this->assertSame('70.000', app(LotAvailability::class)->ungroupedQuantity($second));
        $this->assertSame($first->activeAgreement()->id, $lot->portions->first()->agreement_id);
        $raw = DB::table('lot_histories')->where('lot_id', $lot->id)->first();
        $this->assertStringNotContainsString('Composición sintética', $raw->reason);
        $this->assertCount(2, $lot->histories->first()->changes['portions']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lot.created']);
        Http::assertNothingSent();
    }

    public function test_repeating_a_command_is_idempotent_and_changed_composition_is_rejected(): void
    {
        $offer = $this->offer();
        $payload = $this->payload([[$offer, '40.000']]);
        $first = app(AggregationService::class)->create($this->staff, $payload);
        $payload['portions'][0]['quantity'] = '40';
        $this->assertSame($first->id, app(AggregationService::class)->create($this->staff, $payload)->id);
        $this->assertDatabaseCount('lot_offers', 1);
        $this->assertDatabaseCount('lot_histories', 1);
        $payload['portions'][0]['quantity'] = '41';
        $this->rejected(fn () => app(AggregationService::class)->create($this->staff, $payload), 'source_key');
    }

    public function test_two_lots_cannot_claim_more_than_the_offer_and_full_allocation_is_not_available(): void
    {
        $offer = $this->offer();
        $this->create([[$offer, '70']]);
        $this->rejected(fn () => $this->create([[$offer, '20']]), 'portions.0.quantity');
        $this->assertDatabaseCount('lots', 1);
        $this->create([[$offer, '10']]);
        $this->assertSame('0.000', app(LotAvailability::class)->ungroupedQuantity($offer));
        $this->assertFalse($offer->isAvailableForAggregation());
    }

    public function test_duplicate_zero_negative_and_excess_precision_portions_are_rejected(): void
    {
        $offer = $this->offer();
        $this->rejected(fn () => $this->create([[$offer, '20'], [$offer, '20']]), 'portions.0.offer_id');
        foreach (['0', '-1', '0.0001', '1e3', 'NaN'] as $quantity) {
            $this->rejected(fn () => $this->create([[$offer, $quantity]]), 'portions.0.quantity');
        }
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_suggestion_is_read_only_and_preserves_a_common_window_for_every_source(): void
    {
        $today = now('America/Lima');
        $anchor = $this->offer(['available_until' => $today->copy()->addDays(5)->toDateString()]);
        $early = $this->offer(['available_until' => $today->copy()->addDays(2)->toDateString()]);
        $late = $this->offer(['available_from' => $today->copy()->addDays(4)->toDateString()]);
        $otherPrice = $this->offer([], '6.000000');
        $suggestion = app(AggregationService::class)->suggest($this->staff, $anchor);
        $this->assertSame([$anchor->id, $early->id], $suggestion->pluck('id')->all());
        $this->assertNotContains($late->id, $suggestion->pluck('id'));
        $this->assertNotContains($otherPrice->id, $suggestion->pluck('id'));
        $this->assertDatabaseCount('lots', 0);
        $this->assertSame('80.000', app(LotAvailability::class)->ungroupedQuantity($anchor));
    }

    public function test_manual_grouping_rejects_incompatible_classification_locality_and_dates(): void
    {
        $anchor = $this->offer(['available_until' => now('America/Lima')->addDays(2)->toDateString()]);
        $otherVariety = ProductVariety::factory()->create(['product_id' => $this->product->id]);
        $otherQuality = QualityGrade::factory()->create(['product_id' => $this->product->id]);
        $otherLocation = Location::factory()->create(['name' => 'País sintético distinto']);
        $otherProduct = Product::query()->where('slug', 'papa')->firstOrFail();
        foreach ([
            ['variety_id' => $otherVariety->id],
            ['quality_grade_id' => $otherQuality->id],
            ['location_id' => $otherLocation->id],
            ['available_from' => now('America/Lima')->addDays(4)->toDateString()],
            ['product_id' => $otherProduct->id, 'variety_id' => $otherProduct->varieties()->value('id'), 'quality_grade_id' => $otherProduct->qualityGrades()->value('id')],
        ] as $attributes) {
            $different = $this->offer($attributes);
            $this->rejected(fn () => $this->create([[$anchor, '20'], [$different, '20']]), 'portions');
        }
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_price_variation_needs_explicit_review_and_keeps_original_prices(): void
    {
        $first = $this->offer();
        $second = $this->offer([], '7.000001');
        $this->rejected(fn () => $this->create([[$first, '20'], [$second, '20']]), 'allow_price_variation');
        $lot = $this->create([[$first, '20'], [$second, '20']], ['allow_price_variation' => true]);
        $view = app(LotAvailability::class)->eligibleLots()->whereKey($lot->id)->firstOrFail();
        $this->assertSame('5.000000', $view->unit_price_min);
        $this->assertSame('7.000001', $view->unit_price_max);
        $suggestion = app(AggregationService::class)->suggest($this->staff, $first, true);
        $this->assertCount(2, $suggestion);
    }

    public function test_stale_versions_and_changed_latest_agreements_are_rejected(): void
    {
        $offer = $this->offer();
        $payload = $this->payload([[$offer, '20']]);
        $payload['portions'][0]['offer_version'] = 2;
        $this->rejected(fn () => app(AggregationService::class)->create($this->staff, $payload), 'portions');
        $payload['portions'][0]['offer_version'] = 1;
        OfferPriceAgreement::factory()->create(['offer_id' => $offer->id, 'offer_version' => 1, 'version' => 2]);
        $this->rejected(fn () => app(AggregationService::class)->create($this->staff, $payload), 'portions');
    }

    public function test_publication_rechecks_sources_and_stale_lot_versions(): void
    {
        $offer = $this->offer();
        $lot = $this->create([[$offer, '40']]);
        $offer->producer->update(['status' => 'suspended']);
        $this->rejected(fn () => $this->transition($lot, 'published'), 'result');
        $offer->producer->update(['status' => 'verified']);
        $published = $this->transition($lot, 'published');
        $this->assertSame('published', $published->status);
        $this->assertSame(2, $published->version);
        $this->rejected(fn () => $this->transition($lot, 'cancelled'), 'version');
        $this->assertSame('published', $published->fresh()->status);
    }

    public function test_pause_preserves_capacity_and_cancellation_releases_it_without_erasing_history(): void
    {
        $offer = $this->offer();
        $lot = $this->transition($this->create([[$offer, '40']]), 'published');
        $paused = $this->transition($lot, 'paused');
        $this->assertSame('40.000', app(LotAvailability::class)->ungroupedQuantity($offer));
        $this->assertFalse(app(LotAvailability::class)->marketplace()->whereKey($lot->id)->exists());
        $cancelled = $this->transition($paused, 'cancelled');
        $this->assertSame('80.000', app(LotAvailability::class)->ungroupedQuantity($offer));
        $this->assertSame('40.000', $cancelled->portions->first()->released_quantity);
        $this->assertDatabaseCount('lot_offers', 1);
        $this->assertDatabaseCount('lot_histories', 4);
        $release = $cancelled->histories->first()->changes['released_portions'][0];
        $this->assertSame('0.000', $release['before']);
        $this->assertSame('40.000', $release['after']);
        $this->rejected(fn () => $this->transition($cancelled, 'published'), 'result');
    }

    public function test_active_portions_block_offer_withdrawal_but_released_history_does_not(): void
    {
        $offer = $this->offer();
        $lot = $this->create([[$offer, '40']]);
        $data = ['version' => $offer->version, 'result' => 'cancelled', 'reason' => 'Cancelación de oferta sintética'];
        $this->rejected(fn () => app(OfferRegistry::class)->transition($this->staff, $offer, $data), 'status');
        $this->transition($lot, 'cancelled');
        $this->assertSame('cancelled', app(OfferRegistry::class)->transition($this->staff, $offer, $data)->status);
        $this->assertDatabaseCount('lot_offers', 1);
    }

    public function test_retired_quantity_does_not_return_to_the_market_when_cancelling(): void
    {
        $offer = $this->offer();
        $lot = $this->create([[$offer, '40']]);
        $lot->portions->first()->update(['retired_quantity' => '10.000']);
        $this->transition($lot, 'cancelled');
        $this->assertSame('70.000', app(LotAvailability::class)->ungroupedQuantity($offer));
        $this->assertSame('30.000', $lot->portions->first()->fresh()->released_quantity);
    }

    public function test_expiration_keeps_portions_and_is_idempotent(): void
    {
        $offer = $this->offer();
        $lot = $this->create([[$offer, '40']]);
        DB::table('lots')->where('id', $lot->id)->update(['available_from' => now('America/Lima')->subDays(2)->toDateString(), 'available_until' => now('America/Lima')->subDay()->toDateString()]);
        $this->assertSame(1, app(AggregationService::class)->expire());
        $this->assertSame(0, app(AggregationService::class)->expire());
        $this->assertSame('expired', $lot->fresh()->status);
        $this->assertSame('40.000', app(LotAvailability::class)->ungroupedQuantity($offer));
        $this->assertDatabaseCount('lot_histories', 2);
    }

    public function test_form_submission_supports_manual_selection_and_partial_quantities(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->staff)->get(route('lots.create'))->assertOk()->assertSee('80.000')->assertSee('Sugerir compatibles');
        $this->post(route('lots.store'), [
            'source_key' => (string) Str::uuid(), 'selected' => [(string) $offer->id],
            'quantities' => [$offer->id => '25.125'], 'versions' => [$offer->id => $offer->version],
            'agreements' => [$offer->id => $offer->activeAgreement()->id], 'reason' => 'Composición parcial de prueba',
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('lots.show', Lot::query()->sole());
        $this->assertSame('25.125', LotOffer::query()->sole()->quantity);
        $this->get(route('lots.show', Lot::query()->sole()))->assertOk()->assertSee('Composición y origen privados');
    }

    public function test_factory_preserves_origins_and_the_history_is_immutable(): void
    {
        $lot = $this->create([[$this->offer(), '40']]);
        $this->expectException(LogicException::class);
        $lot->histories->first()->update(['reason' => 'Intento de sustituir la evidencia']);
    }

    public function test_database_rejects_portion_over_release(): void
    {
        $lot = $this->create([[$this->offer(), '40']]);
        $this->expectException(QueryException::class);
        $lot->portions->first()->update(['released_quantity' => '40.001']);
    }

    public function test_stale_actor_cannot_create_after_access_is_revoked(): void
    {
        $offer = $this->offer();
        $payload = $this->payload([[$offer, '40']]);
        $staleActor = User::findOrFail($this->staff->id);
        $this->staff->forceFill(['active' => false])->save();
        $this->expectException(AuthorizationException::class);
        app(AggregationService::class)->create($staleActor, $payload);
    }

    public function test_counted_products_require_whole_units_and_keep_their_base_unit(): void
    {
        $unit = Unit::query()->where('code', 'unidad')->firstOrFail();
        $this->product = Product::factory()->create(['base_unit_id' => $unit->id]);
        ProductVariety::factory()->create(['product_id' => $this->product->id]);
        QualityGrade::factory()->create(['product_id' => $this->product->id]);
        $offer = $this->offer();
        $this->rejected(fn () => $this->create([[$offer, '1.5']]), 'portions.0.quantity');
        $lot = $this->create([[$offer, '2']]);
        $this->assertSame($unit->id, $lot->base_unit_id);
        $this->assertSame('2.000', $lot->portions->first()->quantity);
    }
}
