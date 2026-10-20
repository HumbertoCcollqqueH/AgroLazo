<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Services\AutomatedMarketPriceProvider;
use App\Services\AutomaticLotGrouping;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SimplifiedOfferReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $operator;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.internal_communication' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->operator = User::factory()->withRole('coordinator')->create();
        $this->owner = User::factory()->withRole('producer')->create();
        Http::preventStrayRequests();
    }

    private function offer(): Offer
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $offer = Offer::factory()->create([
            'registered_by' => $this->operator->id, 'product_id' => $product->id,
            'variety_id' => $product->varieties()->value('id'), 'quality_grade_id' => $product->qualityGrades()->value('id'),
            'base_unit_id' => $product->base_unit_id, 'unit_id' => $product->base_unit_id,
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
        ]);
        $offer->producer->forceFill(['user_id' => $this->owner->id])->save();

        return $offer;
    }

    private function review(Offer $offer, array $overrides = []): array
    {
        return [
            'version' => $offer->version, 'result' => 'verified', 'price_mode' => 'manual',
            'reason' => 'Datos y condiciones revisados por coordinación.',
            'gross_unit_price' => '4.50', 'producer_unit_cost' => '0',
            'valid_until' => $offer->available_until->toDateString(),
            'terms' => 'Precio por kilogramo sin descuentos al productor. AgroLazo cubre el transporte.',
            ...$overrides,
        ];
    }

    public function test_one_review_sends_the_price_and_owner_acceptance_publishes_without_another_operator_action(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->operator)->get(route('offers.show', $offer))->assertOk()->assertSee('Aprobar y enviar precio');
        $this->post(route('offers.transition', $offer), $this->review($offer))->assertSessionHasNoErrors();
        $agreement = $offer->agreements()->sole();
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertSame('proposed', $agreement->status);
        $this->assertSame('4.500000', $agreement->net_unit_price);
        $this->assertDatabaseCount('lots', 0);
        $this->actingAs($this->owner)->get(route('producer.offers.show', $offer))->assertOk()->assertSee('Solo falta tu confirmación.')->assertSee('360.00');
        $url = route('producer.agreements.decide', [$offer, $agreement]);
        $this->post($url, ['version' => 1, 'decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->post($url, ['version' => 1, 'decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->assertSame('published', $offer->fresh()->status);
        $this->assertDatabaseCount('lots', 1);
        $this->assertDatabaseCount('lot_offers', 1);
        $this->assertSame($this->owner->id, $agreement->fresh()->recorded_by);
        $this->get(route('producer.offers.show', $offer))->assertOk()->assertSee('Tu oferta está publicada.')->assertDontSee('Aceptar precio y publicar');
        $this->get(route('marketplace.show', Lot::query()->sole()->code))->assertOk();
        Http::assertNothingSent();
    }

    public function test_assisted_acceptance_also_publishes_once_and_never_republishes_a_paused_lot(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->operator)->post(route('offers.transition', $offer), $this->review($offer))->assertSessionHasNoErrors();
        $agreement = $offer->agreements()->sole();
        $url = route('offers.decide', [$offer, $agreement]);
        $data = ['version' => 1, 'decision' => 'accepted', 'evidence' => 'Aceptación expresa del precio en la conversación interna #25.'];
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame('published', $offer->fresh()->status);
        $this->assertDatabaseCount('lots', 1);
        $lot = Lot::query()->sole();
        $lot->update(['status' => 'paused']);
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame('paused', $lot->fresh()->status);
        $this->assertDatabaseCount('lots', 1);
        $this->assertSame($this->operator->id, $agreement->fresh()->recorded_by);
        Http::assertNothingSent();
    }

    public function test_review_uses_a_current_market_reference_without_creating_a_sale_or_acceptance(): void
    {
        $offer = $this->offer();
        $this->mock(AutomatedMarketPriceProvider::class, function ($mock): void {
            $mock->shouldReceive('forOffer')->once()->andReturn([
                'currency' => 'PEN', 'unit_price' => '6.250000', 'observed_on' => now('America/Lima')->toDateString(),
                'market' => 'Mercado local', 'source' => 'Referencia registrada por el equipo', 'product_label' => 'Quinua',
            ]);
        });
        $this->actingAs($this->operator)->post(route('offers.transition', $offer), $this->review($offer, ['price_mode' => 'automatic']))->assertSessionHasNoErrors();
        $agreement = $offer->agreements()->sole();
        $this->assertSame('6.250000', $agreement->gross_unit_price);
        $this->assertSame('proposed', $agreement->status);
        $this->assertNull($agreement->accepted_at);
        $this->assertDatabaseCount('lots', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_missing_reference_keeps_reviewed_data_and_explains_how_to_complete_price(): void
    {
        $offer = $this->offer();
        $this->mock(AutomatedMarketPriceProvider::class, fn ($mock) => $mock->shouldReceive('forOffer')->once()->andReturnNull());
        $this->actingAs($this->operator)->post(route('offers.transition', $offer), $this->review($offer, ['price_mode' => 'automatic']))
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($message) => str_contains($message, 'No encontramos una referencia'));
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertDatabaseCount('offer_price_agreements', 0);
        $this->assertDatabaseCount('lots', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_manual_price_rolls_back_the_entire_review_and_notifications(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->operator)->post(route('offers.transition', $offer), $this->review($offer, ['gross_unit_price' => '0']))->assertSessionHasErrors('producer_unit_cost');
        $this->assertSame('pending_verification', $offer->fresh()->status);
        $this->assertDatabaseCount('offer_histories', 0);
        $this->assertDatabaseCount('offer_price_agreements', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_price_cannot_expire_before_future_production_becomes_available(): void
    {
        $offer = $this->offer();
        $offer->update(['available_from' => now('America/Lima')->addDays(10)->toDateString(), 'available_until' => now('America/Lima')->addDays(20)->toDateString()]);
        $this->actingAs($this->operator)->post(route('offers.transition', $offer), $this->review($offer, ['valid_until' => now('America/Lima')->addDays(7)->toDateString()]))->assertSessionHasErrors('valid_until');
        $this->assertSame('pending_verification', $offer->fresh()->status);
        $this->assertDatabaseCount('offer_price_agreements', 0);
    }

    public function test_failed_publication_keeps_the_price_unaccepted_for_a_safe_retry(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->operator)->post(route('offers.transition', $offer), $this->review($offer))->assertSessionHasNoErrors();
        $agreement = $offer->agreements()->sole();
        $this->mock(AutomaticLotGrouping::class, fn ($mock) => $mock->shouldReceive('publish')->once()->andThrow(ValidationException::withMessages(['quantity' => 'La cantidad necesita revisión.'])));
        $this->actingAs($this->owner)->post(route('producer.agreements.decide', [$offer, $agreement]), ['version' => 1, 'decision' => 'accepted'])->assertSessionHasErrors('quantity');
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertSame('proposed', $agreement->fresh()->status);
        $this->assertNull($agreement->fresh()->accepted_at);
        $this->assertDatabaseCount('lots', 0);
        $this->assertDatabaseMissing('offer_histories', ['action' => 'agreement_accepted']);
    }

    public function test_rejection_keeps_offer_unpublished_and_operator_can_propose_again_without_reverification(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->operator)->post(route('offers.transition', $offer), $this->review($offer))->assertSessionHasNoErrors();
        $agreement = $offer->agreements()->sole();
        $this->actingAs($this->owner)->post(route('producer.agreements.decide', [$offer, $agreement]), ['version' => 1, 'decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertSame('rejected', $agreement->fresh()->status);
        $this->assertDatabaseCount('lots', 0);
        $this->actingAs($this->operator)->post(route('offers.propose', $offer), $this->review($offer, ['gross_unit_price' => '5']))->assertSessionHasNoErrors();
        $this->assertSame('proposed', $offer->agreements()->latest('version')->first()->status);
        $this->assertDatabaseCount('offer_price_agreements', 2);
        $this->assertSame(1, $offer->histories()->where('action', 'status_changed')->count());
    }

    public function test_review_permissions_versions_and_assisted_evidence_remain_required(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->owner)->postJson(route('offers.transition', $offer), $this->review($offer))->assertForbidden();
        $this->actingAs($this->operator)->postJson(route('offers.transition', $offer), $this->review($offer, ['version' => 2]))->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->post(route('offers.transition', $offer), $this->review($offer))->assertSessionHasNoErrors();
        $agreement = $offer->agreements()->sole();
        $this->postJson(route('offers.decide', [$offer, $agreement]), ['version' => 1, 'decision' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->actingAs($this->owner)->postJson(route('producer.agreements.decide', [$offer, $agreement]), ['version' => 2, 'decision' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('proposed', $agreement->fresh()->status);
        $this->assertDatabaseCount('lots', 0);
    }
}
