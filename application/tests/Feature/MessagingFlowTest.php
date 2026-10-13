<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessage;
use App\Jobs\ProcessConversation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OutboxEvent;
use App\Models\Producer;
use App\Models\Product;
use App\Models\User;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageDelivery;
use App\Services\Messaging\MessageIngress;
use App\Services\Messaging\OfferParser;
use App\Services\Messaging\OutboxDispatcher;
use App\Services\Messaging\SmsEncoding;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessagingFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $phone = '+51900000003';

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.registered_contacts_only' => false]);
        config(['producers.lookup_key' => str_repeat('a', 64), 'messaging.live_enabled' => false, 'messaging.simulator_enabled' => true]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $this->staff = User::factory()->withRole('coordinator')->create();
        Http::preventStrayRequests();
    }

    private function say(string $body, ?string $id = null, string $channel = 'whatsapp'): Conversation
    {
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', $channel, $id ?? (string) Str::uuid(), $this->phone, $body, CarbonImmutable::now());
        app(ConversationEngine::class)->process($message->conversation_id);

        return $message->conversation->fresh();
    }

    private function known(): Conversation
    {
        Producer::factory()->verified()->create(['phone' => $this->phone]);
        $this->say('HOLA');

        return $this->say('ACEPTO');
    }

    private function draft(): Conversation
    {
        $this->known();
        $this->say('QUINUA 80KG PUNO');
        $this->say('HOY');

        return $this->say(now('America/Lima')->addDay()->toDateString());
    }

    public function test_unknown_contact_onboards_without_requesting_or_storing_dni_in_chat(): void
    {
        $c = $this->say('HOLA');
        $this->assertSame('WAITING_CONSENT', $c->state);
        $this->say('ACEPTO');
        $this->say('Productora Sintética');
        $c = $this->say('2');
        $this->assertSame('WAITING_IDENTITY_VERIFICATION', $c->state);
        $this->assertSame('family', $c->context['type']);
        $this->assertDatabaseCount('producers', 0);
        $this->assertDatabaseHas('producer_consents', ['conversation_id' => $c->id, 'purpose' => 'simulator_messages', 'decision' => 'granted']);
        $this->actingAs($this->staff)->post(route('messaging.register', $c), ['full_name' => 'Productora Sintética', 'dni' => '00000003', 'type' => 'family'])->assertSessionHasNoErrors();
        $producer = Producer::query()->sole();
        $this->assertSame('pending_verification', $producer->status);
        $this->assertSame($producer->id, $c->fresh()->producer_id);
        $this->assertStringNotContainsString('00000003', $c->messages()->get()->pluck('body')->implode(' '));
        $this->get(route('producers.show', $producer))->assertOk();
        Http::assertNothingSent();
    }

    public function test_duplicate_message_and_job_do_not_duplicate_replies_or_consents(): void
    {
        $id = (string) Str::uuid();
        $c = $this->say('HOLA', $id);
        $this->say('HOLA', $id);
        app(ConversationEngine::class)->process($c->id);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseCount('producer_consents', 0);
    }

    public function test_verified_contact_confirms_one_intention_and_retries_cannot_create_second_offer(): void
    {
        $c = $this->draft();
        $this->assertSame('OFFER_REVIEW', $c->state);
        $token = $c->context['token'];
        $this->say('9');
        $this->assertDatabaseCount('offers', 0);
        $id = (string) Str::uuid();
        $c = $this->say('CONFIRMAR '.$token, $id);
        $this->say('CONFIRMAR '.$token, $id);
        $this->say('CONFIRMAR '.$token);
        $this->assertDatabaseCount('offers', 1);
        $offer = Offer::query()->sole();
        $this->assertSame('80.000', $offer->normalized_quantity);
        $this->assertSame('pending_verification', $offer->status);
        $this->assertSame('simulator', $offer->channel);
        $this->assertNull($offer->registered_by);
        $this->assertSame('READY', $c->state);
        $this->assertDatabaseCount('offer_histories', 1);
    }

    public function test_superseded_confirmation_cannot_confirm_new_draft(): void
    {
        $c = $this->draft();
        $token = $c->context['token'];
        $this->say('CORREGIR');
        $this->say('CONFIRMAR '.$token);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_expired_draft_and_suspended_producer_cannot_confirm(): void
    {
        $c = $this->draft();
        $token = $c->context['token'];
        $this->travel(8)->days();
        $this->say('CONFIRMAR '.$token);
        $this->assertDatabaseCount('offers', 0);
        $this->say('VENDER');
        $c->producer()->firstOrFail()->update(['status' => 'suspended']);
        $c = $this->say('VENDER');
        $this->assertSame('WAITING_IDENTITY_VERIFICATION', $c->state);
    }

    public function test_opt_out_cancels_pending_messages_and_requires_new_consent(): void
    {
        $c = $this->known();
        $c = $this->say('BAJA');
        $this->assertSame('declined', $c->consent);
        $this->assertSame(1, $c->messages()->where('status', 'queued')->count());
        $count = $c->messages()->where('direction', 'outbound')->count();
        $this->say('VENDER');
        $this->assertSame($count, $c->messages()->where('direction', 'outbound')->count());
        $this->assertDatabaseHas('producer_consents', ['conversation_id' => $c->id, 'decision' => 'declined']);
        $this->assertSame('WAITING_CONSENT', $this->say('ALTA')->state);
        $this->assertSame('READY', $this->say('ACEPTO')->state);
    }

    public function test_help_pauses_bot_and_staff_can_resume_with_a_private_note(): void
    {
        $c = $this->known();
        $c = $this->say('AYUDA');
        $this->assertTrue($c->human_assigned);
        $count = $c->messages()->where('direction', 'outbound')->count();
        $this->say('VENDER');
        $this->assertSame($count, $c->messages()->where('direction', 'outbound')->count());
        $this->actingAs($this->staff)->post(route('messaging.assist', $c), ['action' => 'resume', 'reason' => 'Atención de prueba finalizada'])->assertSessionHasNoErrors();
        $this->assertFalse($c->fresh()->human_assigned);
        $this->assertSame('cancelled', $c->messages()->latest('id')->first()->status);
    }

    public function test_channel_context_and_consent_are_separate(): void
    {
        $whatsapp = $this->known();
        $sms = $this->say('HOLA', channel: 'sms');
        $this->assertNotSame($whatsapp->id, $sms->id);
        $this->assertSame('pending', $sms->consent);
        $this->assertSame('granted', $whatsapp->fresh()->consent);
    }

    public function test_parser_refuses_ambiguous_numbers_and_resolves_only_catalog_values(): void
    {
        $parser = app(OfferParser::class);
        $parsed = $parser->parse('QUINUA 80KG CABANA');
        $this->assertSame('80', $parsed['fields']['quantity']);
        $this->assertArrayNotHasKey('location_id', $parsed['fields']);
        $this->assertNotEmpty($parser->parse('QUINUA 1.000kg PUNO')['errors']);
        $this->assertNotEmpty($parser->parse('QUINUA 80kg 10kg PUNO')['errors']);
        $this->assertSame('1.5', $parser->parse('QUINUA 1,5 toneladas PUNO')['fields']['quantity']);
        $this->assertNotEmpty($parser->parse('QUINUA -5kg PUNO')['errors']);
        $this->assertNull($parser->date('31/02/2027', CarbonImmutable::now()));
        $this->assertSame('2026-09-12', $parser->date('HOY', CarbonImmutable::parse('2026-09-13T02:00:00Z')));
    }

    public function test_parser_asks_which_product_when_different_crops_are_mentioned(): void
    {
        $parsed = app(OfferParser::class)->parse('tengo quinua y papa');
        $this->assertArrayNotHasKey('product_id', $parsed['fields']);
        $this->assertContains('Hay varios productos posibles; selecciona uno.', $parsed['errors']);
    }

    public function test_parser_prefers_a_specific_product_over_its_contained_generic_name(): void
    {
        $product = Product::factory()->create(['name' => 'Papa amarilla']);
        $parsed = app(OfferParser::class)->parse('tengo 80 kilos de papa amarilla');
        $this->assertSame($product->id, $parsed['fields']['product_id']);
        $this->assertSame([], $parsed['errors']);
    }

    public function test_received_body_phone_and_outbox_payload_are_private(): void
    {
        $this->assertSame('2026-09-20', MessageIngress::redact('2026-09-20'));
        $this->assertSame('[dato personal omitido]', MessageIngress::redact('1234-56-78'));
        $c = $this->say('Mi DNI es 12345678');
        $inbound = $c->messages()->where('direction', 'inbound')->sole();
        $this->assertStringNotContainsString('12345678', $inbound->body);
        $this->assertNotSame($inbound->body, DB::table('messages')->where('id', $inbound->id)->value('body'));
        $this->assertStringNotContainsString('51900000003', DB::table('conversations')->value('phone'));
        $this->assertStringNotContainsString('conversation_id', DB::table('outbox_events')->value('payload'));
        $this->assertArrayNotHasKey('body', $inbound->toArray());
    }

    public function test_queue_outage_keeps_durable_event_for_retry(): void
    {
        app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', (string) Str::uuid(), $this->phone, 'HOLA', CarbonImmutable::now());
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Redis offline'));
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatch());
        $event = OutboxEvent::query()->sole();
        $this->assertSame('pending', $event->status);
        $this->assertSame('queue_unavailable', $event->error_code);
        $this->assertNull($event->lease_until);
    }

    public function test_dispatch_and_duplicate_delivery_only_accept_once(): void
    {
        $c = $this->say('HOLA');
        Bus::fake();
        app(OutboxDispatcher::class)->dispatch();
        Bus::assertDispatched(ProcessConversation::class);
        Bus::assertDispatched(DeliverMessage::class);
        $out = $c->messages()->where('direction', 'outbound')->sole();
        app(MessageDelivery::class)->deliver($out->id);
        app(MessageDelivery::class)->deliver($out->id);
        $this->assertSame('accepted', $out->fresh()->status);
        $this->assertSame(1, $out->attempts()->count());
        Http::assertNothingSent();
    }

    public function test_receipts_are_idempotent_and_never_downgrade_read_status(): void
    {
        $c = $this->say('HOLA');
        $out = $c->messages()->where('direction', 'outbound')->sole();
        $at = CarbonImmutable::now();
        app(MessageIngress::class)->receipt('fake', $c->account, 'fake:'.$out->id, 'read', $at);
        app(MessageDelivery::class)->deliver($out->id);
        $this->assertSame('read', $out->fresh()->status);
        app(MessageIngress::class)->receipt('fake', $c->account, 'fake:'.$out->id, 'read', $at);
        app(MessageIngress::class)->receipt('fake', $c->account, 'fake:'.$out->id, 'failed', $at);
        app(MessageIngress::class)->receipt('fake', $c->account, 'fake:'.$out->id, 'sent', $at);
        $this->assertSame('read', $out->fresh()->status);
        $this->assertDatabaseCount('message_receipts', 3);
    }

    public function test_sms_encoding_handles_extension_characters_and_surrogate_pairs(): void
    {
        $encoding = app(SmsEncoding::class);
        $this->assertSame(1, $encoding->estimate(str_repeat('a', 160))['segments']);
        $this->assertSame(2, $encoding->estimate(str_repeat('a', 161))['segments']);
        $this->assertSame(2, $encoding->estimate(str_repeat('^', 81))['segments']);
        $this->assertSame('UCS-2', $encoding->estimate('Quinúa')['encoding']);
        $this->assertSame(2, $encoding->estimate(str_repeat('🙂', 36))['segments']);
        $this->assertSame(72, $encoding->estimate(str_repeat('🙂', 36))['units']);
    }

    public function test_simulator_views_are_private_and_simulation_never_calls_providers(): void
    {
        $this->get(route('messaging.index'))->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->withRole('buyer')->create())->get(route('messaging.simulator'))->assertForbidden();
        $this->postJson(route('messaging.simulate'), [])->assertForbidden();
        $this->actingAs($this->staff)->get(route('messaging.index'))->assertOk();
        $this->get(route('messaging.simulator'))->assertOk();
        $this->post(route('messaging.simulate'), ['phone' => $this->phone, 'channel' => 'whatsapp', 'body' => 'HOLA', 'external_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $c = Conversation::query()->sole();
        $this->get(route('messaging.show', $c))->assertOk()->assertSee('HOLA');
        Http::assertNothingSent();
        config(['messaging.simulator_enabled' => false]);
        $this->get(route('messaging.simulator'))->assertNotFound();
    }

    public function test_late_messages_cannot_advance_current_state_but_stop_still_revokes(): void
    {
        $c = $this->known();
        $message = app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', (string) Str::uuid(), $this->phone, 'VENDER', CarbonImmutable::now()->subDay());
        app(ConversationEngine::class)->process($c->id);
        $this->assertSame('READY', $c->fresh()->state);
        $this->assertSame('late_event', $message->fresh()->metadata['ignored_reason']);
    }

    public function test_staff_reply_is_authorized_idempotent_and_queued_without_network_calls(): void
    {
        $c = $this->known();
        $data = ['source_key' => (string) Str::uuid(), 'body' => 'El coordinador revisará la disponibilidad.'];
        $this->actingAs(User::factory()->withRole('buyer')->create())->postJson(route('messaging.reply', $c), $data)->assertForbidden();
        $this->actingAs($this->staff)->post(route('messaging.reply', $c), $data)->assertSessionHasNoErrors();
        $this->post(route('messaging.reply', $c), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, Message::query()->where('deduplication_key', 'staff:'.$data['source_key'])->count());
        $this->postJson(route('messaging.reply', $c), [...$data, 'body' => 'Otro texto para la misma referencia'])->assertUnprocessable();
        $this->get(route('messaging.show', $c))->assertOk()->assertSee('Respuesta del coordinador');
        Http::assertNothingSent();
    }

    public function test_duplicate_job_does_not_acknowledge_a_message_still_being_sent(): void
    {
        $c = $this->say('HOLA');
        $message = $c->messages()->where('direction', 'outbound')->sole();
        $message->update(['status' => 'sending', 'lease_until' => now()->addMinute()]);
        $event = OutboxEvent::query()->where('event_key', 'deliver:'.$message->id)->sole();
        $event->update(['status' => 'dispatched', 'lease_until' => now()->addMinutes(5)]);
        (new DeliverMessage($event->id, $message->id))->handle(app(MessageDelivery::class));
        $this->assertSame('dispatched', $event->fresh()->status);
        $this->travel(6)->minutes();
        (new DeliverMessage($event->id, $message->id))->handle(app(MessageDelivery::class));
        $this->assertSame('uncertain', $message->fresh()->status);
        $this->assertSame('done', $event->fresh()->status);
        Http::assertNothingSent();
    }
}
