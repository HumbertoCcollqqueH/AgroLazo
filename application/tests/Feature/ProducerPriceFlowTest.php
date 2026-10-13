<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Producer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\Unit;
use App\Models\User;
use App\Services\AutomatedMarketPriceProvider;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageIngress;
use App\Services\Messaging\ProducerPriceFlow;
use App\Services\OfferRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProducerPriceFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Producer $producer;

    private Product $product;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::now());
        config(['producers.lookup_key' => str_repeat('a', 64), 'market-pricing.enabled' => true, 'market-pricing.max_age_days' => 7]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        $this->producer = Producer::factory()->verified()->create();
        $this->product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $this->conversation = Conversation::factory()->create([
            'producer_id' => $this->producer->id, 'phone' => $this->producer->phone, 'phone_lookup' => $this->producer->phone_lookup,
            'state' => 'READY', 'consent' => 'granted', 'last_inbound_at' => now(),
        ]);
        Http::preventStrayRequests();
    }

    private function offer(array $attributes = []): Offer
    {
        $offer = Offer::factory()->create([
            'producer_id' => $this->producer->id, 'product_id' => $this->product->id,
            'variety_id' => $this->product->varieties()->where('code', 'unspecified')->value('id'),
            'quality_grade_id' => $this->product->qualityGrades()->value('id'),
            'unit_id' => $this->product->base_unit_id, 'base_unit_id' => $this->product->base_unit_id,
            'location_id' => Location::query()->where('name', 'Puno')->value('id'), 'quantity' => '20.000', 'normalized_quantity' => '20.000',
            'registered_by' => $this->staff->id, ...$attributes,
        ]);

        return app(OfferRegistry::class)->transition($this->staff, $offer, ['version' => $offer->version, 'result' => 'verified', 'reason' => 'Verificación del coordinador para prueba']);
    }

    private function reference(array $overrides = []): array
    {
        return ['unit_price' => '9.480000', 'currency' => 'PEN', 'market' => 'Mercados mayoristas · Agromercado / SISAP',
            'observed_on' => now('America/Lima')->subDays(4)->toDateString(), 'source' => 'https://www.agromercado.gob.pe/observatorio/quinua/',
            'provider' => 'agromercado', 'product_label' => 'Quinua blanca', ...$overrides];
    }

    private function prices(?array $reference = null, array $choices = [], bool $missing = false): void
    {
        $this->mock(AutomatedMarketPriceProvider::class, function (MockInterface $mock) use ($reference, $choices, $missing): void {
            $mock->shouldReceive('classificationChoices')->andReturn($choices);
            $mock->shouldReceive('forOffer')->andReturn($missing ? null : ($reference ?? $this->reference()));
        });
    }

    private function say(string $body, ?string $externalId = null, ?CarbonImmutable $receivedAt = null): Message
    {
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', $externalId ?? (string) Str::uuid(), $this->producer->phone, $body, $receivedAt ?? CarbonImmutable::now());
        $this->assertNotNull($message);
        app(ConversationEngine::class)->process($message->conversation_id);

        return $message;
    }

    public static function closedStatuses(): array
    {
        return [['cancelled', 'Cancelada'], ['rejected', 'Rechazada'], ['expired', 'Vencida']];
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_offers_leave_stale_price_steps_and_offer_a_fresh_start(string $status, string $label): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        $offer->update(['status' => $status]);
        foreach (['CONFIRMAR', 'ESTADO', '2', 'CONFIRMAR'] as $text) {
            $this->say($text);
            $reply = $this->conversation->messages()->where('direction', 'outbound')->latest('id')->first()->body;
            $this->assertStringContainsString($label, $reply);
            $this->assertStringContainsString('VENDER: empezar una nueva oferta', $reply);
            $this->assertStringContainsString('MENU:', $reply);
            $this->assertStringContainsString('AYUDA:', $reply);
            $this->assertStringNotContainsString('Después de verificar', $reply);
            $this->assertStringNotContainsString('La revisión no garantiza', $reply);
            $this->assertStringNotContainsString('Revisaremos las condiciones', $reply);
        }
        $this->assertSame('READY', $this->conversation->fresh()->state);
        $this->assertSame('proposed', OfferPriceAgreement::query()->sole()->status);
        $this->assertDatabaseCount('lots', 0);
        $this->say('VENDER');
        $this->assertNotSame('READY', $this->conversation->fresh()->state);
        $this->assertSame($status, $offer->fresh()->status);
    }

    public function test_status_explains_pending_information_and_expired_availability_without_promising_publication(): void
    {
        $offer = Offer::factory()->create(['producer_id' => $this->producer->id]);
        $flow = app(ProducerPriceFlow::class);
        $this->assertStringContainsString('Aún no está publicada', $flow->offerStatus($offer));
        $offer->update(['status' => 'needs_information']);
        $this->assertStringContainsString('completar tus datos', $flow->offerStatus($offer));
        $offer->update(['status' => 'published', 'available_from' => now()->subWeek()->toDateString(), 'available_until' => now()->subDay()->toDateString()]);
        $this->assertStringContainsString('ya no está disponible', $flow->offerStatus($offer));
        $this->assertStringNotContainsString('Publicación registrada', $flow->offerStatus($offer));
    }

    public function test_price_proposal_uses_normalized_quantity_and_does_not_publish_without_acceptance(): void
    {
        $this->prices();
        $offer = $this->offer();
        $message = app(ProducerPriceFlow::class)->queue($offer);

        $this->assertNotNull($message);
        $this->assertStringContainsString('20 kg × S/ 9.48 = S/ 189.60', $message->body);
        $this->assertStringContainsString(now('America/Lima')->subDays(4)->format('d/m/Y'), $message->body);
        $this->assertStringContainsString('referencia mayorista', $message->body);
        $this->assertStringContainsString('transporte a cargo de la plataforma', $message->body);
        $this->assertStringContainsString(now('America/Lima')->addDays(3)->format('d/m/Y'), $message->body);
        $this->assertSame('PRICE_REVIEW', $this->conversation->fresh()->state);
        $agreement = OfferPriceAgreement::query()->sole();
        $this->assertSame('proposed', $agreement->status);
        $this->assertSame('9.480000', $agreement->net_unit_price);
        $this->assertNull($agreement->evidence);
        $this->assertDatabaseCount('lots', 0);
        $this->assertSame($message->id, app(ProducerPriceFlow::class)->queue($offer)->id);
        $this->assertDatabaseCount('offer_price_agreements', 1);
        $this->assertDatabaseCount('messages', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('acceptedReplies')]
    public function test_acceptance_publishes_exactly_one_lot_visible_to_the_buyer(string $reply): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        $message = $this->say($reply, 'price-accepted');

        $agreement = OfferPriceAgreement::query()->sole();
        $this->assertSame('accepted', $agreement->status, $this->conversation->messages()->where('direction', 'outbound')->latest('id')->value('body'));
        $this->assertStringContainsString('Mensaje #'.$message->id, $agreement->evidence);
        $this->assertStringContainsString(hash('sha256', $reply), $agreement->evidence);
        $this->assertSame('published', $offer->fresh()->status);
        $lot = Lot::query()->sole();
        $this->assertSame('published', $lot->status);
        $this->assertSame('READY', $this->conversation->fresh()->state);
        $this->say($reply, 'price-accepted');
        $this->say('CONFIRMAR');
        $this->assertDatabaseCount('lots', 1);
        $this->assertDatabaseCount('lot_offers', 1);
        $this->assertDatabaseCount('offer_price_agreements', 1);
        $this->actingAs(User::factory()->withRole('buyer')->create())->get(route('marketplace.index'))->assertOk()->assertSee($lot->code);
        $this->getJson(route('api.marketplace.show', $lot->code))->assertOk()->assertJsonPath('data.available_quantity', '20.000');
        Http::assertNothingSent();
    }

    public static function acceptedReplies(): array
    {
        return [['1'], ['CONFIRMAR'], ['confirmar precio'], ['ACEPTO PRECIO']];
    }

    public function test_rejection_preserves_offer_and_does_not_propose_the_same_price_again(): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        $this->say('2');

        $this->assertSame('rejected', OfferPriceAgreement::query()->sole()->status);
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertTrue($this->conversation->fresh()->human_assigned);
        $this->assertDatabaseCount('lots', 0);
        $this->say('MENU');
        $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        $this->assertDatabaseCount('offer_price_agreements', 1);
    }

    public function test_confirmation_received_before_the_price_summary_does_not_accept_it(): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        $this->say('1', receivedAt: CarbonImmutable::now()->subMinute());

        $this->assertSame('proposed', OfferPriceAgreement::query()->sole()->status);
        $this->assertDatabaseCount('lots', 0);
        $this->say('1');
        $this->assertSame('accepted', OfferPriceAgreement::query()->sole()->status);
    }

    public function test_replaced_or_expired_agreement_cannot_be_accepted_by_old_reply(): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        app(OfferRegistry::class)->proposeAgreement($this->staff, $offer, [
            'version' => 1, 'gross_unit_price' => '11.000000', 'producer_unit_cost' => '0', 'terms' => 'Nuevas condiciones a revisar expresamente', 'valid_until' => now('America/Lima')->addDays(3)->toDateString(),
        ]);
        $this->say('1');

        $this->assertSame(0, OfferPriceAgreement::query()->where('status', 'accepted')->count());
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_baja_takes_precedence_over_price_and_prevents_future_proposals(): void
    {
        $this->prices();
        $offer = $this->offer();
        $proposal = app(ProducerPriceFlow::class)->queue($offer);
        $this->say('BAJA');

        $this->assertSame('OPTED_OUT', $this->conversation->fresh()->state);
        $this->assertSame('cancelled', $proposal->fresh()->status);
        $this->assertSame('proposed', OfferPriceAgreement::query()->sole()->status);
        $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_missing_or_old_market_reference_never_invents_a_proposal(): void
    {
        $offer = $this->offer();
        $this->prices(missing: true);
        $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        $this->prices($this->reference(['observed_on' => now('America/Lima')->subDays(20)->toDateString()]));
        $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        $this->assertDatabaseCount('offer_price_agreements', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_paused_draft_or_revoked_consent_are_not_interrupted(): void
    {
        $this->prices();
        $offer = $this->offer();
        foreach ([['human_assigned' => true], ['human_assigned' => false, 'state' => 'OFFER_QUANTITY'], ['state' => 'READY', 'consent' => 'declined']] as $state) {
            $this->conversation->update($state);
            $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        }
        $this->assertDatabaseCount('offer_price_agreements', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_verifier_permission_is_rechecked_before_proposal_and_acceptance(): void
    {
        $this->prices();
        $offer = $this->offer();
        $this->staff->forceFill(['active' => false])->save();
        $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        $this->staff->forceFill(['active' => true])->save();
        app(ProducerPriceFlow::class)->queue($offer);
        $this->staff->forceFill(['active' => false])->save();
        $this->say('1');
        $this->assertSame('proposed', OfferPriceAgreement::query()->sole()->status);
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_unspecified_variety_is_requested_once_then_price_follows_the_selected_variety(): void
    {
        $variety = ProductVariety::factory()->create(['product_id' => $this->product->id, 'code' => 'blanca', 'name' => 'Blanca']);
        $this->prices(choices: [['variety_id' => $variety->id, 'label' => 'Quinua blanca']]);
        $offer = $this->offer();
        $first = app(ProducerPriceFlow::class)->queue($offer);
        $this->assertSame('PRICE_CLASSIFICATION', $this->conversation->fresh()->state);
        $this->assertSame($first->id, app(ProducerPriceFlow::class)->queue($offer)->id);
        $this->assertDatabaseCount('offer_price_agreements', 0);
        foreach (['Entra discord', 'hola', 'ESTADO'] as $text) {
            $this->say($text);
            $this->assertSame('PRICE_CLASSIFICATION', $this->conversation->fresh()->state);
            $this->assertFalse($this->conversation->fresh()->human_assigned);
            $this->assertStringContainsString('Responde el número de tu variedad', $this->conversation->messages()->where('direction', 'outbound')->latest('id')->first()->body);
        }
        $this->say('1');

        $this->assertSame($variety->id, $offer->fresh()->variety_id);
        $this->assertSame(2, $offer->fresh()->version);
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertSame('PRICE_REVIEW', $this->conversation->fresh()->state);
        $this->assertSame('proposed', OfferPriceAgreement::query()->sole()->status);
        $this->assertDatabaseCount('lots', 0);
        $this->say('CONFIRMAR');
        $this->assertDatabaseCount('lots', 1);
    }

    public function test_selected_variety_without_a_recent_price_remains_verified_until_refresh(): void
    {
        $variety = ProductVariety::factory()->create(['product_id' => $this->product->id, 'code' => 'negra', 'name' => 'Negra']);
        $this->prices(choices: [['variety_id' => $variety->id, 'label' => 'Quinua negra']], missing: true);
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        $this->say('1');

        $this->assertSame('READY', $this->conversation->fresh()->state);
        $this->assertSame('verified', $offer->fresh()->status);
        $this->assertDatabaseCount('offer_price_agreements', 0);
        $this->assertDatabaseCount('lots', 0);
        $this->assertStringContainsString('precio suficientemente reciente', $this->conversation->messages()->where('direction', 'outbound')->latest('id')->firstOrFail()->body);
    }

    public function test_tonnes_are_priced_using_normalized_kilograms(): void
    {
        $this->prices();
        $tonne = Unit::query()->where('code', 't')->firstOrFail();
        $offer = $this->offer(['quantity' => '2.000', 'unit_id' => $tonne->id]);
        $message = app(ProducerPriceFlow::class)->queue($offer);

        $this->assertSame('2000.000', $offer->normalized_quantity);
        $this->assertStringContainsString('2000 kg × S/ 9.48 = S/ 18960.00', $message->body);
    }

    public function test_pending_price_can_be_paused_to_register_another_crop_and_resumed_explicitly(): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        $this->say('VENDER');
        $this->assertStringStartsWith('OFFER_', $this->conversation->fresh()->state);
        $this->assertArrayHasKey('deferred_price', $this->conversation->fresh()->context);
        $this->assertSame('proposed', OfferPriceAgreement::query()->sole()->status);
        $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        $this->say('CANCELAR');
        $this->assertSame('READY', $this->conversation->fresh()->state);
        $this->assertNull(app(ProducerPriceFlow::class)->queue($offer));
        $this->say('PRECIO');
        $this->assertSame('PRICE_REVIEW', $this->conversation->fresh()->state);
        $this->say('CONFIRMAR');
        $this->assertDatabaseCount('lots', 1);
    }

    public function test_expired_proposal_is_renewed_but_requires_a_new_confirmation(): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        OfferPriceAgreement::query()->update(['valid_until' => now()->subDay()]);
        $this->prices($this->reference(['unit_price' => '10.500000', 'observed_on' => now('America/Lima')->toDateString()]));
        $this->say('CONFIRMAR');
        $this->assertDatabaseCount('offer_price_agreements', 2);
        $this->assertSame(0, OfferPriceAgreement::query()->where('status', 'accepted')->count());
        $this->assertDatabaseCount('lots', 0);
        $this->say('CONFIRMAR');
        $this->assertSame('10.500000', OfferPriceAgreement::query()->where('status', 'accepted')->sole()->gross_unit_price);
        $this->assertDatabaseCount('lots', 1);
    }

    public function test_expiring_a_new_draft_preserves_the_previous_price_for_explicit_resumption(): void
    {
        $this->prices();
        $offer = $this->offer(['available_until' => now('America/Lima')->addDays(20)->toDateString()]);
        app(ProducerPriceFlow::class)->queue($offer);
        $this->say('VENDER');
        $this->travel(8)->days();
        $this->say('HOLA');
        $this->assertSame('READY', $this->conversation->fresh()->state);
        $this->assertArrayHasKey('deferred_price', $this->conversation->fresh()->context);
        $this->prices($this->reference(['observed_on' => now('America/Lima')->toDateString()]));
        $this->say('PRECIO');
        $this->assertSame('PRICE_REVIEW', $this->conversation->fresh()->state);
        $this->assertDatabaseCount('offer_price_agreements', 2);
        $this->assertDatabaseCount('lots', 0);
    }

    public function test_confirmation_after_offer_availability_ends_does_not_retry_or_accept_price(): void
    {
        $this->prices();
        $offer = $this->offer();
        app(ProducerPriceFlow::class)->queue($offer);
        $this->travel(9)->days();
        $this->say('CONFIRMAR');
        $this->assertSame('READY', $this->conversation->fresh()->state);
        $this->assertFalse($this->conversation->fresh()->human_assigned);
        $this->assertDatabaseCount('offer_price_agreements', 1);
        $this->assertDatabaseCount('lots', 0);
    }
}
