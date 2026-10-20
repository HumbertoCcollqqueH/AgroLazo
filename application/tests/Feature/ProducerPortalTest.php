<?php

namespace Tests\Feature;

use App\Models\CollectionEvent;
use App\Models\CollectionEventItem;
use App\Models\CollectionReceipt;
use App\Models\Conversation;
use App\Models\CoordinationThread;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OperationalNotice;
use App\Models\OrderAllocation;
use App\Models\Producer;
use App\Models\ProducerPayout;
use App\Models\ProducerSettlement;
use App\Models\Product;
use App\Models\User;
use App\Services\AutomatedMarketPriceProvider;
use App\Services\LocationLookup;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageDelivery;
use App\Services\Messaging\ProducerPriceFlow;
use App\Services\OfferRegistry;
use App\Services\OperationalNotifier;
use App\Services\ProducerPortal;
use App\Services\RuralPlaces;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProducerPortalTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.internal_communication' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        Http::preventStrayRequests();
    }

    private function owner(string $status = 'verified'): User
    {
        $user = User::factory()->withRole('producer')->create();
        $producer = Producer::factory()->create(['status' => $status]);
        $producer->user_id = $user->id;
        $producer->save();

        return $user;
    }

    private function offerData(): array
    {
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();

        return ['source_key' => (string) Str::uuid(), 'product_id' => $product->id, 'unit_id' => $product->base_unit_id,
            'variety_id' => $product->varieties()->value('id'), 'quality_grade_id' => $product->qualityGrades()->value('id'),
            'location_id' => Location::query()->where('name', 'Puno')->value('id'), 'quantity' => '80',
            'available_from' => now('America/Lima')->toDateString(), 'available_until' => now('America/Lima')->addDays(15)->toDateString(), 'confirmation' => '1'];
    }

    public function test_self_registration_assigns_only_producer_and_creates_pending_profile(): void
    {
        $this->get(route('producer.register'))->assertOk()->assertSee('Soy productor');
        $this->post(route('producer.register'), ['name' => 'Productor Andino', 'email' => 'productor@example.test', 'password' => 'CultivosSeguros2026', 'password_confirmation' => 'CultivosSeguros2026', 'accept' => '1', 'role' => 'admin'])->assertRedirect(route('producer.profile'));
        $user = User::query()->where('email', 'productor@example.test')->firstOrFail();
        $this->assertSame(['producer'], $user->roles->pluck('code')->all());
        $this->post(route('producer.profile.save'), ['dni' => '87654321', 'phone' => '987654321', 'type' => 'individual',
            'product_ids' => [Product::query()->first()->id], 'location_id' => Location::query()->first()->id,
            'latitude' => '-15.500000', 'longitude' => '-70.100000', 'profile_consent' => '1', 'location_consent' => '1'])->assertSessionHasNoErrors()->assertRedirect(route('producer.home'));
        $this->assertSame('pending_verification', $user->fresh()->producer->status);
        $this->assertFalse($user->hasPermission('manage-offers'));
        $this->get(route('producer.home'))->assertOk()->assertSee('Estamos revisando');
        Http::assertNothingSent();
    }

    public function test_pending_producer_cannot_submit_and_cannot_claim_existing_identity(): void
    {
        $owner = $this->owner('pending_verification');
        $this->actingAs($owner)->post(route('producer.offers.store'), $this->offerData())->assertSessionHasErrors('producer');
        $other = User::factory()->withRole('producer')->create();
        $this->actingAs($other)->post(route('producer.profile.save'), ['dni' => $owner->producer->dni, 'phone' => $owner->producer->phone, 'type' => 'individual', 'product_ids' => [Product::query()->first()->id], 'profile_consent' => '1'])->assertSessionHasErrors();
        $this->assertNull($other->fresh()->producer);
    }

    public function test_own_offer_is_idempotent_and_ownership_is_never_taken_from_input(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $data = [...$this->offerData(), 'producer_id' => $other->producer->id];
        $this->actingAs($owner)->post(route('producer.offers.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('producer.offers.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('offers', 1);
        $offer = Offer::query()->firstOrFail();
        $this->assertSame($owner->producer->id, $offer->producer_id);
        $this->assertSame('pending_verification', $offer->status);
        $this->get(route('producer.offers.show', $offer))->assertOk()->assertSee('Precio pendiente');
        $this->get(route('offers.show', $offer))->assertForbidden();
        $this->actingAs($other)->get(route('producer.offers.show', $offer))->assertNotFound();
        $this->get(route('producer.home'))->assertDontSee('Oferta #'.$offer->id);
    }

    public function test_accepting_current_price_publishes_once_and_stale_or_foreign_decisions_fail(): void
    {
        $owner = $this->owner();
        $operator = User::factory()->withRole('coordinator')->create();
        $data = $this->offerData();
        $offer = app(OfferRegistry::class)->createOwn($owner, $data);
        app(OfferRegistry::class)->transition($operator, $offer, ['version' => 1, 'result' => 'verified', 'reason' => 'Datos revisados por coordinación.']);
        $agreement = app(OfferRegistry::class)->proposeAgreement($operator, $offer, ['version' => 1, 'gross_unit_price' => '4', 'producer_unit_cost' => '0', 'terms' => 'Precio vigente aceptable; transporte a cargo de AgroLazo.', 'valid_until' => $data['available_until']]);
        $url = route('producer.agreements.decide', [$offer, $agreement]);
        $this->actingAs($this->owner())->post($url, ['version' => 1, 'decision' => 'accepted'])->assertNotFound();
        $this->actingAs($owner)->post($url, ['version' => 2, 'decision' => 'accepted'])->assertSessionHasErrors('version');
        $this->post($url, ['version' => 1, 'decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->post($url, ['version' => 1, 'decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->assertSame('published', $offer->fresh()->status);
        $this->assertSame($owner->id, $agreement->fresh()->recorded_by);
        $this->assertDatabaseCount('lots', 1);
        $this->get(route('marketplace.show', Lot::query()->first()->code))->assertOk();
        Http::assertNothingSent();
    }

    public function test_private_conversations_are_owned_encrypted_and_reply_is_idempotent(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $operator = User::factory()->withRole('coordinator')->create();
        $data = ['subject' => 'Recojo de mi cosecha', 'body' => 'La entrada de mi parcela está junto al puente.', 'submission_key' => (string) Str::uuid()];
        $this->actingAs($owner)->post(route('coordination.store'), $data)->assertRedirect();
        $this->post(route('coordination.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('coordination_threads', 1);
        $thread = CoordinationThread::query()->firstOrFail();
        $this->assertNotSame($data['body'], DB::table('coordination_messages')->value('body'));
        $this->actingAs($other)->get(route('coordination.show', $thread))->assertNotFound();
        $reply = ['body' => 'Confirmamos el acceso por el puente.', 'submission_key' => (string) Str::uuid()];
        $this->post(route('coordination.reply', $thread), $reply)->assertNotFound();
        $this->actingAs($operator)->post(route('coordination.reply', $thread), $reply)->assertSessionHasNoErrors();
        $this->post(route('coordination.reply', $thread), $reply)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('coordination_messages', 2);
        $this->actingAs($owner)->get(route('coordination.show', $thread))->assertOk()->assertSee($reply['body']);
        $this->get(route('notifications.index'))->assertOk()->assertSee('Nueva respuesta');
        Http::assertNothingSent();
    }

    public function test_producer_notices_stay_inside_platform_without_whatsapp_consent(): void
    {
        $owner = $this->owner();
        $notice = OperationalNotice::factory()->create(['producer_id' => $owner->producer->id, 'user_id' => null, 'body' => 'Tu oferta se compró. Recojo pendiente de programación.']);
        app(OperationalNotifier::class)->deliver($notice->id);
        app(OperationalNotifier::class)->deliver($notice->id);
        $this->assertSame('notified', $notice->fresh()->status);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertDatabaseCount('messages', 0);
        $message = new Message(['provider' => 'evolution']);
        $this->assertSame('internal_channel_only', app(MessageDelivery::class)->blockedReason($message));
        Http::assertNothingSent();
    }

    public function test_admin_can_link_an_existing_unclaimed_record_but_not_replace_ownership(): void
    {
        $user = User::factory()->withRole('producer')->create();
        $producer = Producer::factory()->verified()->create();
        $data = ['email' => $user->email, 'reason' => 'Identidad presencial cotejada con titular.', 'identity_confirmed' => 1];
        $this->actingAs($user)->post(route('producers.link-account', $producer), $data)->assertForbidden();
        $this->actingAs(User::factory()->withRole('admin')->create())->post(route('producers.link-account', $producer), $data)->assertSessionHasNoErrors();
        $this->assertSame($user->id, $producer->fresh()->user_id);
        $this->post(route('producers.link-account', $producer), $data)->assertSessionHasErrors('email');
    }

    public function test_all_roles_have_internal_inbox_and_producer_screens_render(): void
    {
        foreach (['admin', 'coordinator', 'buyer', 'producer'] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->get(route('coordination.index'))->assertOk()->assertSee('Conversaciones');
            if (in_array($role, ['admin', 'coordinator'], true)) {
                $this->get(route('dashboard'))->assertOk()->assertSee(route('coordination.index'));
            }
        }
        $owner = $this->owner();
        $this->actingAs($owner);
        foreach (['producer.home', 'producer.profile', 'producer.sales', 'producer.offers.create'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('producer.offers.create', ['product_id' => Product::query()->first()->id]))->assertOk()->assertSee('Confirmar y enviar');
        $this->get(route('dashboard'))->assertRedirect(route('producer.home'));
    }

    public function test_owner_can_correct_observed_offer_but_cannot_edit_an_approved_offer(): void
    {
        $owner = $this->owner();
        $operator = User::factory()->withRole('coordinator')->create();
        $offer = app(OfferRegistry::class)->createOwn($owner, $this->offerData());
        app(OfferRegistry::class)->transition($operator, $offer, ['version' => 1, 'result' => 'needs_information', 'reason' => 'Confirma la cantidad de la cosecha.']);
        $this->actingAs($owner)->get(route('producer.offers.edit', $offer))->assertOk()->assertSee('80.000');
        $data = $this->offerData();
        unset($data['source_key']);
        $this->patch(route('producer.offers.update', $offer), [...$data, 'quantity' => '95', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('pending_verification', $offer->fresh()->status);
        $this->assertSame('95.000', $offer->fresh()->quantity);
        $this->assertSame(2, $offer->fresh()->version);
        app(OfferRegistry::class)->transition($operator, $offer->fresh(), ['version' => 2, 'result' => 'verified', 'reason' => 'Cantidad revisada por el equipo.']);
        $this->patch(route('producer.offers.update', $offer), [...$data, 'version' => 2])->assertSessionHasErrors('status');
        $this->actingAs($this->owner())->get(route('producer.offers.edit', $offer))->assertNotFound();
    }

    public function test_photo_upload_and_read_are_private_to_the_owner_and_operator(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $offer = app(OfferRegistry::class)->createOwn($owner, $this->offerData());
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jzZkAAAAASUVORK5CYII=');
        $this->actingAs($owner)->post(route('producer.offers.photos', $offer), ['photo' => UploadedFile::fake()->createWithContent('quinua.png', $png), 'caption' => 'Cosecha de quinua'])->assertSessionHasNoErrors();
        $photo = $offer->photos()->firstOrFail();
        $this->get(route('producer.offers.photo', [$offer, $photo]))->assertOk();
        $this->actingAs($this->owner())->get(route('producer.offers.photo', [$offer, $photo]))->assertNotFound();
        $this->post(route('producer.offers.photos', $offer), ['photo' => UploadedFile::fake()->createWithContent('otro.png', $png)])->assertNotFound();
    }

    public function test_internal_price_proposal_uses_fresh_reference_and_never_sends_whatsapp(): void
    {
        $owner = $this->owner();
        $operator = User::factory()->withRole('coordinator')->create();
        $offer = app(OfferRegistry::class)->createOwn($owner, $this->offerData());
        $offer = app(OfferRegistry::class)->transition($operator, $offer, ['version' => 1, 'result' => 'verified', 'reason' => 'Producto clasificado por coordinación.']);
        $this->mock(AutomatedMarketPriceProvider::class, function ($mock): void {
            $mock->shouldReceive('forOffer')->once()->andReturn(['currency' => 'PEN', 'unit_price' => '5', 'observed_on' => now('America/Lima')->toDateString(), 'product_label' => 'Quinua', 'market' => 'Referencia vigente', 'source' => 'Catálogo de referencia']);
        });
        app(ProducerPriceFlow::class)->queue($offer);
        app(ProducerPriceFlow::class)->queue($offer);
        $this->assertSame(1, $offer->agreements()->count());
        $this->assertSame('5.000000', $offer->agreements()->first()->net_unit_price);
        $this->assertSame(2, $owner->notifications()->count());
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_notification_read_and_live_updates_never_cross_accounts(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        ProducerPortal::notify($owner, 'Aviso privado del productor.', route('producer.sales'), 'private-notice');
        $notice = $owner->notifications()->firstOrFail();
        $this->actingAs($other)->post(route('notifications.read', $notice->id))->assertNotFound();
        $this->getJson(route('coordination.updates'))->assertOk()->assertJsonPath('unread', 0);
        $this->actingAs($owner)->getJson(route('coordination.updates'))->assertOk()->assertJsonPath('unread', 1);
        $this->post(route('notifications.read', $notice->id))->assertRedirect();
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_producer_can_lookup_the_location_of_their_farm(): void
    {
        $this->mock(LocationLookup::class, function ($mock): void {
            $mock->shouldReceive('normalize')->once()->with([])->andReturn([]);
            $mock->shouldReceive('reverse')->once()->with(-15.5, -70.1)->andReturn(['district' => 'Juliaca']);
        });
        $this->mock(RuralPlaces::class, function ($mock): void {
            $mock->shouldReceive('nearby')->once()->andReturn([]);
        });
        $this->actingAs($this->owner())->postJson(route('locations.reverse'), ['latitude' => -15.5, 'longitude' => -70.1])
            ->assertOk()->assertJsonPath('district', 'Juliaca');
    }

    public function test_legacy_external_messages_do_not_change_workflows_in_internal_mode(): void
    {
        $conversation = Conversation::factory()->create(['provider' => 'evolution', 'state' => 'NEW']);
        $message = $conversation->messages()->create(['direction' => 'inbound', 'provider' => 'evolution', 'account' => $conversation->account,
            'body' => 'VENDER', 'status' => 'received', 'received_at' => now(), 'deduplication_key' => (string) Str::uuid()]);
        app(ConversationEngine::class)->process($conversation->id);
        $this->assertSame('NEW', $conversation->fresh()->state);
        $this->assertSame('internal_channel_only', $message->fresh()->metadata['ignored_reason']);
        $this->assertSame(0, $conversation->messages()->where('direction', 'outbound')->count());
        Http::assertNothingSent();
    }

    public function test_only_operators_can_start_a_conversation_with_another_account(): void
    {
        $owner = $this->owner();
        $data = ['recipient_email' => $owner->email, 'subject' => 'Coordinar tu cosecha', 'body' => 'Confirma el acceso al lugar de recojo.', 'submission_key' => (string) Str::uuid()];
        $this->actingAs($this->owner())->post(route('coordination.store'), $data)->assertForbidden();
        $this->actingAs(User::factory()->withRole('coordinator')->create())->post(route('coordination.store'), $data)->assertRedirect();
        $this->post(route('coordination.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('coordination_threads', 1);
        $thread = CoordinationThread::query()->firstOrFail();
        $this->assertSame($owner->id, $thread->user_id);
        $this->actingAs($owner)->get(route('coordination.show', $thread))->assertOk()->assertSee($data['body']);
        $this->assertSame(1, $owner->notifications()->count());
    }

    public function test_producer_sees_only_own_collection_receipts_and_payouts(): void
    {
        $owner = User::factory()->withRole('producer')->create();
        $allocation = OrderAllocation::factory()->create();
        $allocation->portion->offer->producer->forceFill(['user_id' => $owner->id])->save();
        $order = $allocation->orderItem->order;
        $event = CollectionEvent::factory()->create(['order_id' => $order->id]);
        CollectionEventItem::factory()->create(['collection_event_id' => $event->id, 'order_allocation_id' => $allocation->id,
            'pickup_at' => now()->addDay(), 'pickup_address' => 'Entrada propia junto al puente']);
        CollectionReceipt::factory()->create(['collection_event_id' => $event->id, 'order_allocation_id' => $allocation->id, 'notes' => 'Recepción propia sin diferencias']);
        $settlement = ProducerSettlement::factory()->create(['producer_id' => $owner->producer->id, 'order_id' => $order->id]);
        ProducerPayout::factory()->create(['producer_settlement_id' => $settlement->id]);
        $this->actingAs($owner)->get(route('producer.sales'))->assertOk()->assertSee('Entrada propia junto al puente')
            ->assertSee('Recepción propia sin diferencias')->assertSee('Por desembolsar')->assertSee($order->code);
        $this->actingAs($this->owner())->get(route('producer.sales'))->assertOk()
            ->assertDontSee('Entrada propia junto al puente')->assertDontSee('Recepción propia sin diferencias')->assertDontSee($order->code);
    }
}
