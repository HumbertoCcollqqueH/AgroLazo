<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Location;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\PricingPolicy;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Services\AggregationService;
use App\Services\AllocationService;
use App\Services\CheckoutService;
use App\Services\LotAvailability;
use App\Services\ManualMarketPriceProvider;
use App\Services\Money;
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

class CommerceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected User $buyer;

    protected User $staff;

    protected User $admin;

    protected Lot $lot;

    protected PricingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $this->buyer->id]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->admin = User::factory()->withRole('admin')->create();
        $this->lot = $this->makeLot([['80.000', '5.000000']]);
        $this->policy = PricingPolicy::factory()->create(['base_unit_id' => $this->lot->base_unit_id, 'recorded_by' => $this->admin->id]);
    }

    protected function makeLot(array $sources): Lot
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $lot = null;
        foreach ($sources as [$quantity, $price]) {
            $offer = Offer::factory()->published($price, '0.100000')->create([
                'product_id' => $product->id, 'base_unit_id' => $product->base_unit_id, 'unit_id' => $product->base_unit_id,
                'variety_id' => $product->varieties()->value('id'), 'quality_grade_id' => $product->qualityGrades()->value('id'),
                'location_id' => Location::query()->where('name', 'Puno')->value('id'), 'quantity' => $quantity,
                'normalized_quantity' => $quantity,
            ]);
            if (! $lot) {
                $lot = Lot::factory()->withOffer($offer, $quantity)->create(['status' => 'published', 'published_at' => now(), 'allow_price_variation' => true]);
            } else {
                LotOffer::factory()->create(['lot_id' => $lot->id, 'offer_id' => $offer->id, 'agreement_id' => $offer->activeAgreement()->id, 'offer_version' => 1, 'quantity' => $quantity]);
            }
        }

        return $lot;
    }

    protected function quote(string $quantity = '60.000', array $extra = []): Quote
    {
        return app(PricingService::class)->issue($this->buyer, $this->lot, [
            'key' => (string) Str::uuid(), 'quantity' => $quantity, 'policy_id' => $this->policy->id,
            'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Retiro sintético coordinado en Puno', 'payment_method' => 'coordinated', ...$extra,
        ]);
    }

    protected function command(array $extra = []): array
    {
        return ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString(), ...$extra];
    }

    protected function rejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Se esperaba rechazo del campo '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_checkout_requires_a_future_available_delivery_date_and_preserves_it_on_retries(): void
    {
        $quote = $this->quote();
        foreach ([null, '2026-02-30', now('America/Lima')->toDateString(), $this->lot->available_until->addDay()->toDateString()] as $day) {
            $this->rejected(fn () => app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command(['requested_delivery_on' => $day])), 'requested_delivery_on');
        }
        $this->assertDatabaseCount('orders', 0);
        $data = $this->command();
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, $data);
        $this->assertSame($data['requested_delivery_on'], $order->fresh()->requested_delivery_on->toDateString());
        $this->assertTrue($order->is(app(CheckoutService::class)->confirm($this->buyer, $quote, $data)));
        $this->rejected(fn () => app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command(['requested_delivery_on' => now('America/Lima')->addDays(3)->toDateString()])), 'requested_delivery_on');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_transport_is_covered_even_when_an_old_policy_has_a_freight_charge(): void
    {
        $this->policy = PricingPolicy::factory()->create(['base_unit_id' => $this->lot->base_unit_id, 'logistics_minor' => 2500]);
        $quote = $this->quote();
        $this->assertSame(0, $quote->logistics_minor);
        $this->assertSame('platform', $quote->pricing_snapshot['transport_paid_by']);
        $this->assertSame('delivery', $quote->delivery_mode);
        $this->actingAs($this->buyer)->get(route('commerce.quotes.show', $quote))->assertOk()->assertSee('requested_delivery_on')->assertSee('Nosotros organizamos y cubrimos el transporte');
    }

    public function test_partial_quote_uses_exact_fifo_contributions_and_rounds_each_line(): void
    {
        $this->lot = $this->makeLot([['90.000', '4.800000'], ['80.000', '4.700000'], ['70.000', '5.000000'], ['120.000', '5.100000'], ['60.000', '5.200000']]);
        $quote = $this->quote('220.000');
        $this->assertSame(['90.000', '80.000', '50.000'], $quote->items->pluck('quantity')->all());
        $this->assertSame(105800, $quote->subtotal_minor);
        $this->assertSame(2200, $quote->fee_minor);
        $this->assertSame(100, $quote->discount_minor);
        $this->assertSame(107900, $quote->total_minor);
        $this->assertSame(103600, $quote->items->sum('producer_net_minor'));
        $this->assertSame('420.000', (string) app(LotAvailability::class)->marketplace()->whereKey($this->lot->id)->firstOrFail()->available_quantity);
        $this->assertDatabaseCount('reservation_items', 0);
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command());
        $this->assertSame(3, app(CheckoutService::class)->allocations($order)->count());
        $this->assertSame('200.000', (string) app(LotAvailability::class)->marketplace()->whereKey($this->lot->id)->firstOrFail()->available_quantity);
    }

    public function test_fractional_quantity_keeps_cent_reconciliation_and_weighted_price_is_informative(): void
    {
        $quote = $this->quote('25.125');
        $this->assertSame(12563, $quote->subtotal_minor);
        $this->assertSame(251, $quote->fee_minor);
        $this->assertSame(12814, $quote->total_minor);
        $this->assertSame(12312, $quote->items->sole()->producer_net_minor);
        $this->assertSame([1 => 34, 2 => 33, 3 => 33], Money::distribute(100, [1 => '1', 2 => '1', 3 => '1']));
    }

    public function test_quote_and_checkout_are_idempotent_and_reject_changed_payload(): void
    {
        $key = (string) Str::uuid();
        $quote = $this->quote('60.000', ['key' => $key]);
        $this->assertTrue($quote->is($this->quote('60.000', ['key' => $key])));
        $this->rejected(fn () => $this->quote('61.000', ['key' => $key]), 'key');
        $data = $this->command();
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, $data);
        $this->assertTrue($order->is(app(CheckoutService::class)->confirm($this->buyer, $quote, $data)));
        $this->assertTrue($order->is(app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command())));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('20.000', app(AllocationService::class)->free($this->lot->portions->sole()));
    }

    public function test_reservation_counts_once_when_converted_even_for_entire_lot(): void
    {
        $quote = $this->quote('80.000');
        $reservation = app(CheckoutService::class)->reserve($this->buyer, $quote, $this->command());
        $this->assertSame('0.000', app(AllocationService::class)->free($this->lot->portions->sole()));
        $this->assertFalse(app(LotAvailability::class)->marketplace()->whereKey($this->lot->id)->exists());
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command(['reservation_id' => $reservation->id]));
        $this->assertSame('converted', $reservation->fresh()->status);
        $this->assertSame('0.000', app(AllocationService::class)->free($this->lot->portions->sole()));
        $this->assertSame($reservation->id, $order->reservation_id);
    }

    public function test_stale_quote_does_not_silently_replace_its_sources(): void
    {
        $first = $this->quote();
        $second = $this->quote();
        app(CheckoutService::class)->confirm($this->buyer, $first, $this->command());
        $this->rejected(fn () => app(CheckoutService::class)->confirm($this->buyer, $second, $this->command()), 'quote');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_expired_reservation_releases_read_availability_without_scheduler_and_cannot_convert(): void
    {
        $quote = $this->quote();
        $reservation = app(CheckoutService::class)->reserve($this->buyer, $quote, $this->command());
        DB::table('reservations')->where('id', $reservation->id)->update(['expires_at' => now()->subSecond()]);
        $this->assertSame('80.000', app(AllocationService::class)->free($this->lot->portions->sole()));
        $this->rejected(fn () => app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command(['reservation_id' => $reservation->id])), 'reservation_id');
        $this->assertSame(1, app(CheckoutService::class)->expire());
        $this->assertSame('expired', $reservation->fresh()->status);
    }

    public function test_cancel_reservation_then_lot_preserves_history_and_returns_capacity(): void
    {
        $quote = $this->quote();
        $reservation = app(CheckoutService::class)->reserve($this->buyer, $quote, $this->command());
        $this->rejected(fn () => app(AggregationService::class)->transition($this->staff, $this->lot, ['version' => 1, 'result' => 'cancelled', 'reason' => 'Cancelación sintética revisada']), 'result');
        app(CheckoutService::class)->cancelReservation($this->buyer, $reservation, ['key' => (string) Str::uuid(), 'reason' => 'Ya no se requiere la reserva sintética']);
        app(AggregationService::class)->transition($this->staff, $this->lot, ['version' => 1, 'result' => 'cancelled', 'reason' => 'Cancelación sintética revisada']);
        $this->assertDatabaseCount('reservation_items', 1);
        $this->assertSame('80.000', $this->lot->portions()->sole()->released_quantity);
    }

    public function test_disabled_buyer_pending_profile_and_foreign_quote_are_forbidden(): void
    {
        $quote = $this->quote();
        $other = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $other->id]);
        $this->actingAs($other)->get(route('commerce.quotes.show', $quote))->assertForbidden();
        $this->actingAs($other)->postJson(route('commerce.confirm', $quote), $this->command())->assertForbidden();
        $this->buyer->buyer->forceFill(['status' => 'pending'])->save();
        $this->actingAs($this->buyer)->postJson(route('commerce.confirm', $quote), $this->command())->assertForbidden();
        $this->buyer->forceFill(['active' => false])->save();
        $this->actingAs($this->buyer)->get(route('commerce.index'))->assertRedirect();
    }

    public function test_quote_json_and_order_views_do_not_expose_private_source_identity_to_buyer(): void
    {
        $quote = $this->quote();
        $producer = $quote->items->sole()->portion->offer->producer;
        $response = $this->actingAs($this->buyer)->getJson(route('commerce.quotes.show', $quote))->assertOk()->assertJsonPath('data.retains_inventory', false);
        foreach ([$producer->full_name, $producer->dni, $producer->phone, 'producer_net_minor', 'terms_snapshot'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->get(route('commerce.quotes.show', $quote))->assertOk()->assertSee('Desglose de tu cotización')->assertDontSee($producer->full_name);
        $order = app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command());
        $this->get(route('commerce.orders.show', $order))->assertOk()->assertDontSee($producer->full_name);
        $this->actingAs($this->staff)->get(route('commerce.orders.show', $order))->assertOk()->assertSee($producer->full_name);
    }

    public function test_suspended_producer_changed_lot_and_expired_quote_block_confirmation(): void
    {
        $quote = $this->quote();
        $quote->items->sole()->portion->offer->producer->update(['status' => 'suspended']);
        $this->rejected(fn () => app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command()), 'lot');
        $quote->items->sole()->portion->offer->producer->update(['status' => 'verified']);
        $this->lot->update(['version' => 2]);
        $this->rejected(fn () => app(CheckoutService::class)->confirm($this->buyer, $quote, $this->command()), 'quote');
        DB::table('quotes')->where('id', $quote->id)->update(['issued_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);
        $this->rejected(fn () => app(CheckoutService::class)->reserve($this->buyer, $quote, $this->command()), 'quote');
    }

    public function test_quantity_validation_and_explicit_acceptance_are_required(): void
    {
        foreach (['0', '-1', 'NaN', '1.0001', '1e2', '81'] as $quantity) {
            $this->rejected(fn () => $this->quote($quantity), 'quantity');
        }
        $quote = $this->quote();
        $this->rejected(fn () => app(CheckoutService::class)->confirm($this->buyer, $quote, ['key' => (string) Str::uuid()]), 'accepted');
    }

    public function test_pricing_references_are_manual_sourced_compatible_and_historical(): void
    {
        $data = ['key' => (string) Str::uuid(), ...$this->lot->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id']), 'unit_id' => $this->lot->base_unit_id, 'unit_price' => '4.500000', 'market' => 'Mercado sintético', 'source' => 'Documento sintético de prueba, sin precio oficial', 'observed_on' => now('America/Lima')->toDateString(), 'synthetic' => true];
        app(ManualMarketPriceProvider::class)->record($this->admin, $data);
        app(ManualMarketPriceProvider::class)->record($this->admin, [...$data, 'key' => (string) Str::uuid(), 'unit_price' => '5.500000']);
        $quote = $this->quote();
        $this->assertSame('5.000000', $quote->reference_snapshot['median']);
        $this->assertFalse($quote->reference_snapshot['is_official']);
        $this->assertSame(30000, $quote->subtotal_minor);
        $this->actingAs($this->staff)->get(route('commerce.pricing'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('commerce.pricing'))->assertOk();
    }

    public function test_lowest_price_rule_and_tax_logistics_discounts_use_declared_policy(): void
    {
        $this->lot = $this->makeLot([['40.000', '6.000000'], ['40.000', '4.000000']]);
        $this->policy = PricingPolicy::factory()->create(['base_unit_id' => $this->lot->base_unit_id, 'allocation_rule' => 'lowest_price', 'fee_unit_price' => '1.000000', 'logistics_minor' => 2500, 'discount_threshold' => '50.000', 'discount_minor' => 500, 'tax_bps' => 1000]);
        $quote = $this->quote();
        $this->assertSame(['40.000', '20.000'], $quote->items->pluck('quantity')->all());
        $this->assertSame(28000, $quote->subtotal_minor);
        $this->assertSame(3350, $quote->tax_minor);
        $this->assertSame(36850, $quote->total_minor);
    }

    public function test_reduction_requires_buyer_acceptance_and_retires_missing_stock_atomically(): void
    {
        $order = app(CheckoutService::class)->confirm($this->buyer, $this->quote(), $this->command());
        $allocation = app(CheckoutService::class)->allocations($order)->sole();
        $adjustment = app(CheckoutService::class)->proposeReduction($this->staff, $order, ['key' => (string) Str::uuid(), 'version' => 1, 'allocation_id' => $allocation->id, 'quantity' => '10.000', 'retire' => true, 'reason' => 'Faltante físico sintético comprobado']);
        $this->assertSame('0.000', $allocation->fresh()->released_quantity);
        $this->actingAs($this->staff)->post(route('commerce.adjustments.accept', $adjustment), $this->command())->assertForbidden();
        app(CheckoutService::class)->acceptReduction($this->buyer, $adjustment, $this->command());
        $this->assertSame('10.000', $allocation->fresh()->released_quantity);
        $this->assertSame('10.000', $allocation->portion->fresh()->retired_quantity);
        $this->assertSame('20.000', app(AllocationService::class)->free($allocation->portion));
        $this->assertSame(25500, $order->fresh()->total_minor);
        $this->assertSame(30600, $order->original_total_minor);
        $this->rejected(fn () => app(CheckoutService::class)->acceptReduction($this->buyer, $adjustment, $this->command()), 'adjustment');
    }

    public function test_live_rules_cannot_be_used_when_real_commerce_is_disabled(): void
    {
        $this->policy = PricingPolicy::factory()->create(['base_unit_id' => $this->lot->base_unit_id, 'synthetic' => false]);
        $this->rejected(fn () => $this->quote(), 'policy_id');
        $this->assertDatabaseCount('quotes', 0);
    }
}
