<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Unit;
use App\Models\User;
use App\Services\AggregationService;
use App\Services\OfferPublication;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfferPublicationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        Http::preventStrayRequests();
    }

    private function offer(array $attributes = [], bool $accepted = true): Offer
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $factory = $accepted ? Offer::factory()->published() : Offer::factory();

        return $factory->create([
            'product_id' => $product->id, 'variety_id' => $product->varieties()->value('id'),
            'quality_grade_id' => $product->qualityGrades()->value('id'),
            'base_unit_id' => $product->base_unit_id, 'unit_id' => $product->base_unit_id,
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
            'registered_by' => $this->staff->id, 'status' => 'verified', ...$attributes,
        ]);
    }

    private function data(Offer $offer, array $overrides = []): array
    {
        return [
            'source_key' => (string) Str::uuid(), 'version' => $offer->version,
            'agreement_id' => $offer->agreements()->latest('version')->value('id'),
            'reason' => 'Publicación revisada de una oferta sintética', ...$overrides,
        ];
    }

    private function assertPublicationRejected(Offer $offer, array $data, string $field): void
    {
        try {
            app(OfferPublication::class)->publish($this->staff, $offer, $data);
            $this->fail('La publicación debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_coordinator_can_publish_an_accepted_verified_offer_for_buyers(): void
    {
        $offer = $this->offer();
        $lot = app(OfferPublication::class)->publish($this->staff, $offer, $this->data($offer));

        $this->assertSame('published', $offer->fresh()->status);
        $this->assertSame('published', $lot->status);
        $this->assertDatabaseCount('lots', 1);
        $this->assertDatabaseHas('lot_offers', ['lot_id' => $lot->id, 'offer_id' => $offer->id, 'quantity' => $offer->normalized_quantity]);
        $this->actingAs(User::factory()->withRole('buyer')->create());
        $this->get(route('marketplace.index'))->assertOk()->assertSee($lot->code);
        $this->get(route('dashboard'))->assertOk()->assertSee('Lotes disponibles')->assertSee($lot->code)->assertSee('Ver lote y cotizar');
        $this->get(route('marketplace.show', $lot->code))->assertOk()->assertSee('Quinua');
        $this->getJson(route('api.marketplace.index'))->assertOk()->assertJsonFragment(['code' => $lot->code]);
        $this->getJson(route('api.marketplace.show', $lot->code))->assertOk()->assertJsonPath('data.available_quantity', '80.000');
        Http::assertNothingSent();
    }

    public function test_publication_from_offer_page_publishes_for_buyers_once(): void
    {
        $offer = $this->offer();
        $data = $this->data($offer);
        $this->actingAs($this->staff);
        $this->get(route('offers.show', $offer))->assertOk()
            ->assertSee('Precio aceptado: publicación pendiente')
            ->assertSee('Publicar para compradores');
        $this->post(route('offers.publish', $offer), $data)->assertRedirect(route('offers.show', $offer));
        $this->post(route('offers.publish', $offer), $data)->assertRedirect(route('offers.show', $offer));
        $this->assertDatabaseCount('lots', 1);
        $lot = Lot::query()->sole();
        $this->get(route('offers.show', $offer))->assertOk()
            ->assertSee('Visible para compradores')
            ->assertSee(route('marketplace.show', $lot->code), false)
            ->assertDontSee('Publicar para compradores');
    }

    public function test_offer_page_explains_missing_price_and_pending_acceptance_without_publication_button(): void
    {
        $offer = $this->offer(accepted: false);
        $this->actingAs($this->staff);
        $this->get(route('offers.show', $offer))->assertOk()
            ->assertSee('Pendiente de precio de mercado')
            ->assertDontSee('Publicar para compradores');
        OfferPriceAgreement::factory()->create([
            'offer_id' => $offer->id, 'offer_version' => $offer->version,
            'status' => 'proposed', 'valid_until' => now()->addDay(),
        ]);
        $this->get(route('offers.show', $offer))->assertOk()
            ->assertSee('Esperando la confirmación del productor')
            ->assertDontSee('Publicar para compradores');
    }

    public function test_http_publication_rejects_buyers_and_invalid_request_data(): void
    {
        $offer = $this->offer();
        $data = $this->data($offer);
        $this->actingAs(User::factory()->withRole('buyer')->create())
            ->postJson(route('offers.publish', $offer), $data)->assertForbidden();
        $this->actingAs($this->staff)
            ->postJson(route('offers.publish', $offer), [...$data, 'source_key' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['source_key']);
        $this->assertDatabaseCount('lots', 0);
        $this->assertSame('verified', $offer->fresh()->status);
    }

    public function test_buyer_and_inactive_coordinator_cannot_publish(): void
    {
        $offer = $this->offer();
        foreach ([User::factory()->withRole('buyer')->create(), User::factory()->withRole('coordinator')->inactive()->create()] as $actor) {
            try {
                app(OfferPublication::class)->publish($actor, $offer, $this->data($offer));
                $this->fail('El usuario no debe poder publicar.');
            } catch (AuthorizationException) {
                $this->assertSame('verified', $offer->fresh()->status);
                $this->assertDatabaseCount('lots', 0);
            }
        }
    }

    public function test_both_offer_and_lot_permissions_are_required(): void
    {
        $offer = $this->offer();
        $role = $this->staff->roles()->firstOrFail();
        foreach (['manage-offers', 'manage-lots'] as $code) {
            $permission = $role->permissions()->where('code', $code)->firstOrFail();
            $role->permissions()->detach($permission->id);
            try {
                app(OfferPublication::class)->publish($this->staff, $offer, $this->data($offer));
                $this->fail('La publicación requiere ambos permisos.');
            } catch (AuthorizationException) {
                $this->assertSame('verified', $offer->fresh()->status);
                $this->assertDatabaseCount('lots', 0);
            } finally {
                $role->permissions()->attach($permission->id);
            }
        }
    }

    public function test_missing_price_or_acceptance_cannot_publish_or_change_the_offer(): void
    {
        $offer = $this->offer(accepted: false);
        $this->assertPublicationRejected($offer, $this->data($offer, ['agreement_id' => 1]), 'agreement_id');
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertDatabaseCount('lots', 0);

        OfferPriceAgreement::factory()->create(['offer_id' => $offer->id, 'offer_version' => $offer->version, 'status' => 'proposed']);
        $this->assertPublicationRejected($offer, $this->data($offer), 'agreement_id');
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_expired_agreement_cannot_publish(): void
    {
        $offer = $this->offer();
        $offer->agreements()->update(['valid_until' => now()->subSecond()]);
        $this->assertPublicationRejected($offer, $this->data($offer), 'agreement_id');
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_stale_offer_version_and_replaced_agreement_are_rejected(): void
    {
        $offer = $this->offer();
        $this->assertPublicationRejected($offer, $this->data($offer, ['version' => 2]), 'version');
        $data = $this->data($offer);
        OfferPriceAgreement::factory()->create([
            'offer_id' => $offer->id, 'offer_version' => $offer->version, 'version' => 2,
            'status' => 'accepted', 'accepted_at' => now(), 'recorded_by' => $this->staff->id,
        ]);
        $this->assertPublicationRejected($offer, $data, 'agreement_id');
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_unverified_offer_and_suspended_producer_are_rejected(): void
    {
        $offer = $this->offer(['status' => 'pending_verification']);
        $this->assertPublicationRejected($offer, $this->data($offer), 'status');
        $offer->update(['status' => 'verified']);
        $offer->producer->update(['status' => 'suspended']);
        $this->assertPublicationRejected($offer, $this->data($offer), 'producer_id');
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_retry_returns_the_same_published_lot_without_consuming_more_quantity(): void
    {
        $offer = $this->offer();
        $data = $this->data($offer);
        $first = app(OfferPublication::class)->publish($this->staff, $offer, $data);
        $retry = app(OfferPublication::class)->publish($this->staff, $offer, $data);

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('lots', 1);
        $this->assertDatabaseCount('lot_offers', 1);
        $this->assertSame(2, $retry->histories()->count());
        $this->assertPublicationRejected($offer, $this->data($offer), 'quantity');
        $this->assertDatabaseCount('lots', 1);
    }

    public function test_retry_does_not_republish_paused_or_cancelled_lots(): void
    {
        $offer = $this->offer();
        $data = $this->data($offer);
        $lot = app(OfferPublication::class)->publish($this->staff, $offer, $data);
        foreach (['paused', 'cancelled'] as $status) {
            $lot = app(AggregationService::class)->transition($this->staff, $lot, [
                'version' => $lot->version, 'result' => $status, 'reason' => 'Cambio revisado del lote sintético',
            ]);
            $this->assertPublicationRejected($offer, $data, 'source_key');
            $this->assertSame($status, $lot->fresh()->status);
            $this->assertDatabaseCount('lots', 1);
        }
    }

    public function test_source_key_cannot_be_reused_for_another_offer_or_actor(): void
    {
        $offer = $this->offer();
        $data = $this->data($offer);
        app(OfferPublication::class)->publish($this->staff, $offer, $data);
        $other = $this->offer();
        $this->assertPublicationRejected($other, $this->data($other, ['source_key' => $data['source_key']]), 'source_key');
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->assertPublicationRejected($offer, $data, 'source_key');
        $this->assertDatabaseCount('lots', 1);
    }

    public function test_existing_allocations_are_preserved_and_only_free_quantity_is_published(): void
    {
        $offer = $this->offer(['status' => 'published']);
        $draft = app(AggregationService::class)->create($this->staff, [
            'source_key' => (string) Str::uuid(), 'reason' => 'Separación previa de cantidad sintética',
            'portions' => [['offer_id' => $offer->id, 'offer_version' => $offer->version, 'agreement_id' => $offer->activeAgreement()->id, 'quantity' => '30.000']],
        ]);
        $lot = app(OfferPublication::class)->publish($this->staff, $offer, $this->data($offer));

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseHas('lot_offers', ['lot_id' => $draft->id, 'quantity' => '30.000']);
        $this->assertDatabaseHas('lot_offers', ['lot_id' => $lot->id, 'quantity' => '50.000']);
        $this->assertPublicationRejected($offer, $this->data($offer), 'quantity');
        $this->assertDatabaseCount('lots', 2);
    }

    public function test_failed_lot_creation_rolls_back_offer_publication(): void
    {
        $product = Product::factory()->create(['base_unit_id' => Unit::query()->where('code', 'unidad')->value('id')]);
        $offer = $this->offer([
            'product_id' => $product->id, 'base_unit_id' => $product->base_unit_id, 'unit_id' => $product->base_unit_id,
            'variety_id' => ProductVariety::factory()->create(['product_id' => $product->id])->id,
            'quality_grade_id' => QualityGrade::factory()->create(['product_id' => $product->id])->id,
            'quantity' => '80.500', 'normalized_quantity' => '80.500',
        ]);
        $this->assertPublicationRejected($offer, $this->data($offer), 'portions.0.quantity');

        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertSame(0, $offer->histories()->count());
        $this->assertDatabaseCount('lots', 0);
    }
}
