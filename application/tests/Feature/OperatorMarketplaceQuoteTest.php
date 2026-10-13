<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\PricingPolicy;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PricingService;
use App\Services\PurchaseQuantity;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class OperatorMarketplaceQuoteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $buyer;

    private Lot $lot;

    private PricingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['commerce.minimum_purchase_kg' => 25, 'commerce.show_test_labels' => false]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $this->buyer->id]);
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $offer = Offer::factory()->published()->create([
            'product_id' => $product->id, 'base_unit_id' => $product->base_unit_id, 'unit_id' => $product->base_unit_id,
            'variety_id' => $product->varieties()->value('id'), 'quality_grade_id' => $product->qualityGrades()->value('id'),
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
        ]);
        $this->lot = Lot::factory()->withOffer($offer, '80.000')->create(['status' => 'published', 'published_at' => now()]);
        $this->policy = PricingPolicy::factory()->create(['base_unit_id' => $this->lot->base_unit_id]);
    }

    private function payload(string $quantity): array
    {
        return CommerceScenario::key(['quantity' => $quantity, 'policy_id' => $this->policy->id, 'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Entrega en la dirección del comprador', 'payment_method' => 'coordinated']);
    }

    public function test_operators_see_management_actions_and_quote_entry_redirects_without_creating_a_buyer(): void
    {
        foreach (['admin' => 'Vista de administración', 'coordinator' => 'Vista de coordinación'] as $role => $label) {
            $operator = User::factory()->withRole($role)->create();
            $this->actingAs($operator)->get(route('marketplace.show', $this->lot->code))
                ->assertOk()->assertSee($label)->assertSee('Gestionar lote y ver productores')
                ->assertDontSee('Cotizar para comprar o reservar')->assertSee('Gestionar pedidos');
            $this->get(route('commerce.quotes.create', $this->lot))->assertRedirectToRoute('lots.show', $this->lot)->assertSessionHas('status');
            $this->assertNull($operator->buyer);
            $this->postJson(route('commerce.quotes.store', $this->lot), $this->payload('25'))->assertForbidden();
        }
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_buyer_quote_shows_current_lot_summary_minimum_and_maximum_without_private_producer_data(): void
    {
        $response = $this->actingAs($this->buyer)->get(route('commerce.quotes.create', $this->lot))->assertOk()
            ->assertSee('Resumen del lote')->assertSee($this->lot->code)->assertSee('80 kg')->assertSee('Compra desde 25 kg')
            ->assertSee('min="25.000"', false)->assertSee('max="80.000"', false)->assertSee('value="25"', false)
            ->assertDontSee($this->lot->portions->sole()->offer->producer->full_name);
        $this->assertStringContainsString('Usar todo lo disponible: 80 kg', $response->getContent());
        $this->get(route('commerce.quotes.create', ['lot' => $this->lot, 'quantity' => '40.5']))->assertOk()->assertViewHas('quantity', '40.5');
        foreach (['1', '999', ['25']] as $invalid) {
            $this->get(route('commerce.quotes.create', ['lot' => $this->lot, 'quantity' => $invalid]))->assertOk()->assertViewHas('quantity', '25');
        }
    }

    public function test_json_quotes_enforce_minimum_and_actual_inventory_and_allow_partial_purchase(): void
    {
        $this->actingAs($this->buyer);
        foreach (['1', '24.999', '80.001'] as $quantity) {
            $this->postJson(route('commerce.quotes.store', $this->lot), $this->payload($quantity))
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->assertDatabaseCount('quotes', 0);
        $this->postJson(route('commerce.quotes.store', $this->lot), $this->payload('25'))->assertCreated();
        $this->assertDatabaseHas('quotes', ['lot_id' => $this->lot->id, 'quantity' => '25.000']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_remaining_stock_below_minimum_has_no_buy_cta_and_no_quote_form(): void
    {
        $quote = app(PricingService::class)->issue($this->buyer, $this->lot, $this->payload('60'));
        app(CheckoutService::class)->confirm($this->buyer, $quote, CommerceScenario::key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDay()->toDateString()]));
        $this->actingAs($this->buyer)->get(route('marketplace.show', $this->lot->code))->assertOk()
            ->assertSee('menor que la compra mínima de 25 kg')->assertDontSee('Cotizar para comprar o reservar');
        $this->get(route('commerce.quotes.create', $this->lot))->assertOk()->assertSee('20 kg')
            ->assertSee('menor que la compra mínima de 25 kg')->assertDontSee('Calcular cotización');
    }

    public function test_old_small_quotes_cannot_be_reserved_or_confirmed_after_the_minimum_changes(): void
    {
        config(['commerce.minimum_purchase_kg' => 0]);
        $quote = app(PricingService::class)->issue($this->buyer, $this->lot, $this->payload('1'));
        config(['commerce.minimum_purchase_kg' => 25]);
        $this->actingAs($this->buyer)->postJson(route('commerce.reserve', $quote), CommerceScenario::key(['accepted' => '1']))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson(route('commerce.confirm', $quote), CommerceScenario::key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->get(route('commerce.quotes.show', $quote))->assertOk()->assertSee('por debajo de la compra mínima actual')->assertDontSee('Confirmar compra');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_minimum_is_converted_between_mass_units_and_count_is_not_treated_as_kilograms(): void
    {
        foreach (['kg' => '25.000', 'g' => '25000.000', 't' => '0.025', 'unidad' => '1.000'] as $code => $minimum) {
            $unit = Unit::query()->where('code', $code)->firstOrFail();
            $this->assertSame($minimum, PurchaseQuantity::minimum($unit));
            $this->assertTrue(PurchaseQuantity::allows($unit, $minimum));
            $this->assertFalse(PurchaseQuantity::allows($unit, bcsub($minimum, '0.001', 3)));
        }
        $this->assertNull(PurchaseQuantity::minimum(Unit::factory()->create()));
    }

    public function test_marketplace_estimation_rejects_quantities_below_minimum(): void
    {
        $this->get(route('marketplace.show', ['code' => $this->lot->code, 'quantity' => '1']))->assertOk()->assertSee('La compra mínima es 25 kg.')->assertViewHas('estimate', null);
        $this->get(route('marketplace.show', ['code' => $this->lot->code, 'quantity' => '25']))->assertOk()->assertViewHas('estimate', ['minimum' => '125.00', 'maximum' => '125.00']);
    }
}
