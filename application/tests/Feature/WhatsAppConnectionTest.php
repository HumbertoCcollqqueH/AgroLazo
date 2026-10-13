<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessage;
use App\Jobs\PrepareWhatsAppConnection;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\Producer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\EvolutionConnectionManager;
use App\Services\Messaging\MessageDelivery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppConnectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1ioAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['messaging.evolution.url' => 'http://evolution:8080', 'messaging.evolution.api_key' => 'test-api-key',
            'messaging.evolution.webhook_secret' => 'test-webhook-secret', 'messaging.evolution.instance' => 'agroconecta',
            'messaging.registered_contacts_only' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        Bus::fake();
    }

    private function connection(array $attributes = []): WhatsAppConnection
    {
        return WhatsAppConnection::query()->create([...['id' => 1, 'integration' => 'WHATSAPP-BAILEYS', 'instance_name' => 'agroconecta-qr',
            'status' => 'connected', 'automation_enabled' => true, 'notice_text' => 'Aviso de prueba: usaremos tu contacto para registrar ofertas agrícolas.',
            'notice_version' => 'qr-test-v1', 'notice_approved' => true, 'revision' => 1, 'updated_by' => User::factory()->withRole('admin')->create()->id], ...$attributes]);
    }

    private function event(string $event, array $data, string $secret = 'test-webhook-secret', string $instance = 'agroconecta-qr'): TestResponse
    {
        return $this->postJson('/api/v1/webhooks/whatsapp/evolution', compact('event', 'data', 'instance'), ['X-Evolution-Secret' => $secret]);
    }

    private function inbound(string $phone = '51900000801', string $id = 'BAILEYS-001'): array
    {
        return ['key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $phone.'@s.whatsapp.net'], 'message' => ['conversation' => 'HOLA'], 'messageTimestamp' => now()->timestamp];
    }

    public function test_lid_only_messages_resume_a_registered_conversation_using_authenticated_provider_identity(): void
    {
        $this->connection();
        Producer::factory()->verified()->create(['phone' => '+51900000801']);
        Http::fake(['*/chat/findMessages/*' => Http::response(['messages' => ['records' => [
            ['key' => ['remoteJid' => '123456789012345@lid', 'remoteJidAlt' => '51900000801@s.whatsapp.net', 'fromMe' => true]],
        ]]])]);
        foreach (['Entra discord', '2', 'estado', 'hola'] as $index => $text) {
            $payload = $this->inbound('51900000801', 'LID-RECOVERY-'.$index);
            $payload['key']['remoteJid'] = '123456789012345@lid';
            $payload['message']['conversation'] = $text;
            $this->event('messages.upsert', $payload)->assertOk();
        }
        $this->assertSame(4, Message::query()->where('direction', 'inbound')->count());
        $this->assertDatabaseCount('conversations', 1);
        Http::assertSentCount(1);
        $this->event('messages.upsert', $payload)->assertOk();
        $this->assertSame(4, Message::query()->where('direction', 'inbound')->count());
    }

    public function test_ambiguous_lid_identity_never_selects_a_phone(): void
    {
        $this->connection();
        Http::fake(['*/chat/findMessages/*' => Http::response(['messages' => ['records' => [
            ['key' => ['remoteJid' => '999999999999999@lid', 'remoteJidAlt' => '51900000801@s.whatsapp.net']],
            ['key' => ['remoteJid' => '999999999999999@lid', 'remoteJidAlt' => '51900000802@s.whatsapp.net']],
        ]]])]);
        $this->assertNull(app(EvolutionConnectionManager::class)->resolveSender(['remoteJid' => '999999999999999@lid']));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_only_administrators_can_read_qr_or_change_connection(): void
    {
        $this->connection(['status' => 'awaiting_scan']);
        app(EvolutionConnectionManager::class)->storeQr('agroconecta-qr', self::PNG);
        foreach (['buyer', 'coordinator'] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create());
            $this->get(route('whatsapp.connection'))->assertForbidden();
            $this->getJson(route('whatsapp.status'))->assertForbidden();
            $this->post(route('whatsapp.prepare'), ['integration' => 'WHATSAPP-BAILEYS'])->assertForbidden();
            $this->post(route('whatsapp.automation'), ['enabled' => 0])->assertForbidden();
        }
        $this->actingAs(User::factory()->withRole('admin')->create());
        $this->getJson(route('whatsapp.status'))->assertOk()->assertJsonPath('qr', self::PNG)->assertDontSee('test-api-key')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertNotSame(self::PNG, Cache::get('whatsapp:qr:1'));
        $this->travel(31)->seconds();
        $this->getJson(route('whatsapp.status'))->assertJsonPath('qr', null);
    }

    public function test_setup_is_queued_without_sending_messages_and_keeps_automation_off(): void
    {
        $this->actingAs(User::factory()->withRole('admin')->create())
            ->post(route('whatsapp.prepare'), ['integration' => 'WHATSAPP-BAILEYS'])->assertRedirectToRoute('whatsapp.connection');
        $this->assertDatabaseHas('whatsapp_connections', ['id' => 1, 'instance_name' => 'agroconecta-qr', 'status' => 'preparing', 'automation_enabled' => false]);
        Bus::assertDispatched(PrepareWhatsAppConnection::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_qr_provider_contract_configures_authenticated_callback_and_caches_qr(): void
    {
        $connection = $this->connection(['status' => 'preparing', 'automation_enabled' => false]);
        Http::fake(['*/instance/fetchInstances*' => Http::response([]), '*/instance/create' => Http::response(['instance' => ['instanceName' => 'agroconecta-qr']]),
            '*/webhook/set/agroconecta-qr' => Http::response(['enabled' => true]), '*/instance/connect/agroconecta-qr' => Http::response(['base64' => self::PNG])]);
        (new PrepareWhatsAppConnection(1))->handle(app(EvolutionConnectionManager::class));
        $this->assertSame('awaiting_scan', $connection->fresh()->status);
        $this->assertSame(self::PNG, app(EvolutionConnectionManager::class)->status()['qr']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/instance/create') && $r['integration'] === 'WHATSAPP-BAILEYS' && $r['qrcode'] === false && $r['syncFullHistory'] === false && ! isset($r['token']));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/webhook/set/') && $r['webhook']['headers']['X-Evolution-Secret'] === 'test-webhook-secret' && $r['webhook']['headers']['Host'] === parse_url(config('app.url'), PHP_URL_HOST));
        $this->event('connection.update', ['state' => 'open'])->assertOk();
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertNull(app(EvolutionConnectionManager::class)->status()['qr']);
    }

    public function test_wrong_credentials_instances_and_unsafe_qr_are_rejected(): void
    {
        $this->connection();
        $this->event('connection.update', ['state' => 'open'], 'wrong')->assertForbidden();
        $this->event('connection.update', ['state' => 'open'], instance: 'other')->assertUnprocessable();
        $this->event('qrcode.updated', ['qrcode' => ['base64' => 'data:image/svg+xml,<svg onload="alert(1)"></svg>']])->assertOk();
        $this->assertNull(app(EvolutionConnectionManager::class)->status()['qr']);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_unknown_groups_own_messages_unresolved_lids_and_old_events_are_silent(): void
    {
        Http::fake(['*/chat/findMessages/*' => Http::response(['messages' => ['records' => []]])]);
        $this->connection();
        $this->event('messages.upsert', $this->inbound())->assertOk();
        Producer::factory()->verified()->create(['phone' => '+51900000801']);
        foreach ([['key' => ['remoteJid' => '12345@g.us']], ['key' => ['remoteJid' => '51900000801@lid']], ['key' => ['fromMe' => true]], ['messageTimestamp' => now()->subDays(2)->timestamp]] as $change) {
            $this->event('messages.upsert', array_replace_recursive($this->inbound(), $change))->assertOk();
        }
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        Http::assertSentCount(1);
    }

    public function test_registered_qr_contact_gets_one_consent_reply_and_receipts_do_not_regress(): void
    {
        $this->connection();
        Producer::factory()->verified()->create(['phone' => '+51900000801']);
        $this->event('messages.upsert', $this->inbound())->assertOk();
        $this->event('messages.upsert', $this->inbound())->assertOk();
        $inbound = Message::query()->sole();
        app(ConversationEngine::class)->process($inbound->conversation_id);
        $outbound = Message::query()->where('direction', 'outbound')->sole();
        $this->assertStringContainsString('Aviso de prueba:', $outbound->body);
        $this->assertStringContainsString('ACEPTO', $outbound->body);
        Http::fake(['*/message/sendText/agroconecta-qr' => Http::response(['key' => ['id' => 'BAILEYS-OUT-1']])]);
        app(MessageDelivery::class)->deliver($outbound->id);
        $this->assertSame('accepted', $outbound->fresh()->status);
        Http::assertSentCount(1);
        $this->event('messages.update', ['keyId' => 'BAILEYS-OUT-1', 'fromMe' => true, 'status' => 'READ'])->assertOk();
        $this->event('messages.update', ['keyId' => 'BAILEYS-OUT-1', 'fromMe' => true, 'status' => 'SERVER_ACK'])->assertOk();
        $this->assertSame('read', $outbound->fresh()->status);
        $this->assertSame('qr:agroconecta-qr', $inbound->account);
    }

    public function test_disabling_automation_keeps_incoming_messages_without_replying_and_blocks_pending_delivery(): void
    {
        $connection = $this->connection();
        Producer::factory()->verified()->create(['phone' => '+51900000801']);
        $this->event('messages.upsert', $this->inbound())->assertOk();
        $inbound = Message::query()->sole();
        app(ConversationEngine::class)->process($inbound->conversation_id);
        $outbound = Message::query()->where('direction', 'outbound')->sole();
        $this->actingAs(User::factory()->withRole('admin')->create())->post(route('whatsapp.automation'), ['enabled' => 0])->assertRedirect();
        $this->assertFalse($connection->fresh()->automation_enabled);
        $this->event('messages.upsert', $this->inbound(id: 'BAILEYS-002'))->assertOk();
        app(MessageDelivery::class)->deliver($outbound->id);
        $this->assertSame('live_disabled', $outbound->fresh()->error_code);
        $this->assertDatabaseCount('messages', 3);
        $paused = Message::query()->where('external_id', 'BAILEYS-002')->sole();
        $this->assertSame('automation_disabled', $paused->metadata['ignored_reason']);
        $this->assertNotNull($paused->processed_at);
        $this->assertDatabaseMissing('outbox_events', ['event_key' => 'process:'.$paused->id]);
        $connection->update(['automation_enabled' => true]);
        app(ConversationEngine::class)->process($paused->conversation_id);
        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
        Http::assertNothingSent();
    }

    public function test_activation_requires_connected_state_and_reviewed_notice(): void
    {
        $connection = $this->connection(['status' => 'disconnected', 'automation_enabled' => false]);
        $this->actingAs(User::factory()->withRole('admin')->create());
        $this->post(route('whatsapp.automation'), ['enabled' => 1, 'notice_text' => $connection->notice_text])->assertSessionHasErrors('notice_approved');
        $input = ['enabled' => 1, 'notice_text' => $connection->notice_text, 'notice_approved' => 1];
        $this->post(route('whatsapp.automation'), $input)->assertSessionHasErrors('enabled');
        $connection->update(['status' => 'connected']);
        $this->post(route('whatsapp.automation'), $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($connection->fresh()->automation_enabled);
    }

    public function test_activation_validation_returns_to_the_form_after_json_status_polling(): void
    {
        Http::fake();
        $connection = $this->connection(['automation_enabled' => false, 'notice_approved' => false]);
        $this->actingAs(User::factory()->withRole('admin')->create());
        $page = $this->get(route('whatsapp.connection'))->assertOk()->assertViewIs('messaging.connection');
        $this->withCookie(config('session.cookie'), $page->getCookie(config('session.cookie'))->getValue());
        $this->get(route('whatsapp.status'), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('status', 'connected')->assertJsonPath('automation_enabled', false)
            ->assertSessionHas('_previous.url', route('whatsapp.status'));

        $notice = 'Usaremos el contacto registrado para gestionar tus ofertas y pedidos agrícolas.';
        $this->post(route('whatsapp.automation'), ['enabled' => 1, 'notice_text' => $notice], ['Referer' => route('whatsapp.status')])
            ->assertRedirectToRoute('whatsapp.connection')
            ->assertSessionHasErrors('notice_approved')
            ->assertSessionHasInput('notice_text', $notice);
        $error = session('errors')->first('notice_approved');
        $this->assertNotEmpty($error);
        $this->get(route('whatsapp.connection'))->assertOk()->assertViewIs('messaging.connection')
            ->assertSeeText('Revisa los siguientes datos')->assertSeeText($error)->assertSeeText($notice);
        $this->assertFalse($connection->fresh()->automation_enabled);
        $this->assertFalse($connection->fresh()->notice_approved);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_disconnected_activation_returns_its_error_to_the_form_after_json_status_polling(): void
    {
        Http::fake();
        $connection = $this->connection(['status' => 'disconnected', 'automation_enabled' => false]);
        $this->actingAs(User::factory()->withRole('admin')->create());
        $page = $this->get(route('whatsapp.connection'))->assertOk()->assertViewIs('messaging.connection');
        $this->withCookie(config('session.cookie'), $page->getCookie(config('session.cookie'))->getValue());
        $this->get(route('whatsapp.status'), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('status', 'disconnected')
            ->assertSessionHas('_previous.url', route('whatsapp.status'));

        $this->post(route('whatsapp.automation'), ['enabled' => 1, 'notice_text' => $connection->notice_text, 'notice_approved' => 1], ['Referer' => route('whatsapp.status')])
            ->assertRedirectToRoute('whatsapp.connection')->assertSessionHasErrors('enabled');
        $this->get(route('whatsapp.connection'))->assertOk()->assertViewIs('messaging.connection')
            ->assertSeeText('Completa la conexión antes de activar las respuestas.');
        $this->assertFalse($connection->fresh()->automation_enabled);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_valid_activation_returns_to_the_form_after_json_status_polling_without_sending_messages(): void
    {
        Http::fake();
        $connection = $this->connection(['automation_enabled' => false, 'notice_approved' => false]);
        $this->actingAs(User::factory()->withRole('admin')->create());
        $page = $this->get(route('whatsapp.connection'))->assertOk()->assertViewIs('messaging.connection');
        $this->withCookie(config('session.cookie'), $page->getCookie(config('session.cookie'))->getValue());
        $this->get(route('whatsapp.status'), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('automation_enabled', false)
            ->assertSessionHas('_previous.url', route('whatsapp.status'));

        $this->post(route('whatsapp.automation'), ['enabled' => 1, 'notice_text' => $connection->notice_text, 'notice_approved' => 1], ['Referer' => route('whatsapp.status')])
            ->assertRedirectToRoute('whatsapp.connection')->assertSessionHasNoErrors();
        $this->get(route('whatsapp.connection'))->assertOk()->assertViewIs('messaging.connection')
            ->assertSeeText('Respuestas activadas para los productores registrados que escriban al número conectado.')
            ->assertSeeText('Respuestas: activadas');
        $this->assertTrue($connection->fresh()->automation_enabled);
        $this->assertTrue($connection->fresh()->notice_approved);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_opening_status_in_a_browser_returns_to_the_configuration_page(): void
    {
        $this->connection();
        $this->actingAs(User::factory()->withRole('admin')->create());
        $this->get(route('whatsapp.status'), ['Accept' => 'text/html'])->assertRedirectToRoute('whatsapp.connection');
        $this->getJson(route('whatsapp.status'))->assertOk()->assertJsonPath('status', 'connected');
        Http::assertNothingSent();
    }

    public function test_ajax_status_polling_preserves_the_previous_configuration_url(): void
    {
        $this->connection();
        $this->actingAs(User::factory()->withRole('admin')->create());
        $page = $this->get(route('whatsapp.connection'))->assertOk();
        $this->withCookie(config('session.cookie'), $page->getCookie(config('session.cookie'))->getValue());
        $this->get(route('whatsapp.status'), ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('status', 'connected')
            ->assertSessionHas('_previous.url', route('whatsapp.connection'));
        Http::assertNothingSent();
    }

    public function test_invalid_setup_returns_to_the_configuration_even_with_a_status_referrer(): void
    {
        $connection = $this->connection(['automation_enabled' => false]);
        $this->actingAs(User::factory()->withRole('admin')->create());
        $this->post(route('whatsapp.prepare'), ['integration' => 'invalid'], ['Referer' => route('whatsapp.status')])
            ->assertRedirectToRoute('whatsapp.connection')->assertSessionHasErrors('integration');
        $this->assertSame('connected', $connection->fresh()->status);
        Bus::assertNotDispatched(PrepareWhatsAppConnection::class);
        Http::assertNothingSent();
    }

    public function test_delayed_qr_and_connecting_callbacks_do_not_disconnect_a_linked_phone(): void
    {
        $connection = $this->connection(['status' => 'awaiting_scan']);
        $this->event('qrcode.updated', ['qrcode' => ['base64' => self::PNG]])->assertOk();
        $this->event('connection.update', ['state' => 'open'])->assertOk();
        $this->event('qrcode.updated', ['qrcode' => ['base64' => self::PNG]])->assertOk();
        $this->event('connection.update', ['state' => 'connecting'])->assertOk();
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertNull(app(EvolutionConnectionManager::class)->status()['qr']);
        $this->assertNull(Cache::get('whatsapp:qr:1'));

        $connection->update(['status' => 'preparing', 'revision' => 2]);
        app(EvolutionConnectionManager::class)->storeQr('agroconecta-qr', self::PNG, 1);
        $this->assertSame('preparing', $connection->fresh()->status);
        $this->assertNull(Cache::get('whatsapp:qr:2'));
        Http::assertNothingSent();
    }

    public function test_reconnection_requeues_an_unsent_notice_once_and_preserves_existing_outbox(): void
    {
        $connection = $this->connection();
        Producer::factory()->verified()->create(['phone' => '+51900000801']);
        $this->event('messages.upsert', $this->inbound())->assertOk();
        $inbound = Message::query()->sole();
        app(ConversationEngine::class)->process($inbound->conversation_id);
        $outbound = Message::query()->where('direction', 'outbound')->sole();
        $originalEvent = OutboxEvent::query()->where('event_key', 'deliver:'.$outbound->id)->sole();
        $originalEvent->update(['status' => 'dispatched', 'lease_until' => now()->addMinutes(5)]);
        $this->event('connection.update', ['state' => 'close'])->assertOk();
        (new DeliverMessage($originalEvent->id, $outbound->id))->handle(app(MessageDelivery::class));
        $this->assertSame('whatsapp_disconnected', $outbound->fresh()->error_code);
        $this->assertSame(0, $outbound->attempts()->count());
        $this->assertSame('done', $originalEvent->fresh()->status);
        Http::assertNothingSent();

        $this->event('connection.update', ['state' => 'open'])->assertOk();
        $this->event('connection.update', ['state' => 'open'])->assertOk();
        $retry = OutboxEvent::query()->where('event_key', 'like', 'reconnect:%')->sole();
        $this->assertSame('queued', $outbound->fresh()->status);
        $this->assertSame('pending', $retry->status);
        $this->assertSame(['message_id' => $outbound->id], $retry->payload);
        $this->assertSame('done', $originalEvent->fresh()->status);
        $this->assertNull($outbound->fresh()->lease_until);

        Http::fake(['*/message/sendText/agroconecta-qr' => Http::response(['key' => ['id' => 'RECOVERED-NOTICE']])]);
        (new DeliverMessage($retry->id, $outbound->id))->handle(app(MessageDelivery::class));
        app(MessageDelivery::class)->deliver($outbound->id);
        $this->assertSame('accepted', $outbound->fresh()->status);
        $this->assertSame('done', $retry->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], $connection->notice_text));
    }

    public function test_reconnection_never_requeues_uncertain_sending_or_accepted_attempts(): void
    {
        $this->connection();
        Producer::factory()->verified()->create(['phone' => '+51900000801']);
        $this->event('messages.upsert', $this->inbound())->assertOk();
        $inbound = Message::query()->sole();
        app(ConversationEngine::class)->process($inbound->conversation_id);
        $original = Message::query()->where('direction', 'outbound')->sole();
        $original->update(['status' => 'uncertain', 'error_code' => 'transport_uncertain']);
        $original->attempts()->create(['token' => '00000000-0000-4000-8000-000000000001', 'outcome' => 'uncertain', 'error_code' => 'transport_uncertain', 'started_at' => now(), 'finished_at' => now()]);
        $sending = Message::factory()->create(['conversation_id' => $inbound->conversation_id, 'provider' => 'evolution', 'account' => $inbound->account,
            'direction' => 'outbound', 'external_id' => null, 'status' => 'sending', 'lease_until' => now()->addMinute()]);
        $blocked = Message::factory()->create(['conversation_id' => $inbound->conversation_id, 'provider' => 'evolution', 'account' => $inbound->account,
            'direction' => 'outbound', 'external_id' => null, 'status' => 'blocked', 'error_code' => 'whatsapp_disconnected']);
        $blocked->attempts()->create(['token' => '00000000-0000-4000-8000-000000000002', 'outcome' => 'accepted', 'started_at' => now(), 'finished_at' => now()]);
        $limited = Message::factory()->create(['conversation_id' => $inbound->conversation_id, 'provider' => 'evolution', 'account' => $inbound->account,
            'direction' => 'outbound', 'external_id' => null, 'status' => 'blocked', 'error_code' => 'whatsapp_disconnected']);
        $limited->attempts()->create(['token' => '00000000-0000-4000-8000-000000000003', 'outcome' => 'queued', 'error_code' => 'rate_limited', 'started_at' => now(), 'finished_at' => now()]);
        $events = OutboxEvent::query()->count();
        $this->event('connection.update', ['state' => 'open'])->assertOk();
        $this->assertSame('uncertain', $original->fresh()->status);
        $this->assertSame('sending', $sending->fresh()->status);
        $this->assertTrue($sending->lease_until->equalTo($sending->fresh()->lease_until));
        $this->assertSame('blocked', $blocked->fresh()->status);
        $this->assertSame('queued', $limited->fresh()->status);
        $this->assertDatabaseCount('outbox_events', $events + 1);
        $this->assertSame(['message_id' => $limited->id], OutboxEvent::query()->where('event_key', 'like', 'reconnect:%')->sole()->payload);
        Http::assertNothingSent();
    }

    public function test_pausing_before_notice_delivery_requires_the_full_notice_after_reactivation(): void
    {
        $connection = $this->connection();
        Producer::factory()->verified()->create(['phone' => '+51900000801']);
        $this->event('messages.upsert', $this->inbound())->assertOk();
        $inbound = Message::query()->sole();
        app(ConversationEngine::class)->process($inbound->conversation_id);
        $notice = Message::query()->where('direction', 'outbound')->sole();
        $this->actingAs(User::factory()->withRole('admin')->create());
        $this->post(route('whatsapp.automation'), ['enabled' => 0])->assertRedirect();
        app(MessageDelivery::class)->deliver($notice->id);
        $this->assertSame('blocked', $notice->fresh()->status);
        $this->assertSame('NEW', $inbound->conversation->fresh()->state);
        $this->post(route('whatsapp.automation'), ['enabled' => 1, 'notice_text' => $connection->notice_text, 'notice_approved' => 1])->assertRedirect();
        $this->event('messages.upsert', array_replace_recursive($this->inbound(id: 'ACCEPT-BEFORE-NOTICE'), ['message' => ['conversation' => 'ACEPTO']]))->assertOk();
        app(ConversationEngine::class)->process($inbound->conversation_id);
        $newNotice = Message::query()->where('direction', 'outbound')->latest('id')->firstOrFail();
        $this->assertNotSame($notice->id, $newNotice->id);
        $this->assertStringContainsString($connection->notice_text, $newNotice->body);
        $this->assertSame('pending', $inbound->conversation->fresh()->consent);
        $this->assertDatabaseCount('producer_consents', 0);
        Http::assertNothingSent();
    }
}
