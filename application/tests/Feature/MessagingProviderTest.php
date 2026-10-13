<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Services\Messaging\MessageDelivery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class MessagingProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $sid = 'AC11111111111111111111111111111111';

    private string $messageSid = 'SM22222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.registered_contacts_only' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'producers.lookup_key' => str_repeat('a', 64),
            'messaging.live_enabled' => false, 'messaging.notice_approved' => true,
            'messaging.whatsapp.enabled' => true, 'messaging.whatsapp.app_secret' => 'test-meta-secret',
            'messaging.whatsapp.verify_token' => 'test-verify', 'messaging.whatsapp.phone_number_id' => '123456',
            'messaging.whatsapp.business_account_id' => '654321',
            'messaging.evolution.url' => 'http://evolution:8080', 'messaging.evolution.api_key' => 'test-evolution',
            'messaging.evolution.instance' => 'agroconecta', 'messaging.evolution.webhook_secret' => 'test-callback',
            'messaging.twilio.enabled' => true, 'messaging.twilio.two_way_verified' => true,
            'messaging.twilio.auth_token' => 'test-twilio', 'messaging.twilio.account_sid' => $this->sid,
            'messaging.twilio.from' => '+15005550006',
            'messaging.twilio.inbound_url' => 'https://agroconecta.test/api/v1/webhooks/sms/inbound',
            'messaging.twilio.status_url' => 'https://agroconecta.test/api/v1/webhooks/sms/status',
        ]);
        Http::preventStrayRequests();
    }

    private function meta(array $messages = [], array $statuses = []): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '654321', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123456'], 'messages' => $messages, 'statuses' => $statuses]]]]]];
    }

    private function incoming(string $id = 'wamid.in-1', string $text = 'HOLA'): array
    {
        return ['id' => $id, 'from' => '51900000003', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]];
    }

    private function postMeta(array $data, ?string $signature = null): TestResponse
    {
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', '/api/v1/webhooks/whatsapp/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $raw, 'test-meta-secret')], $raw);
    }

    private function sms(array $extra = []): array
    {
        return [...['AccountSid' => $this->sid, 'MessageSid' => $this->messageSid, 'From' => '+51900000003', 'To' => '+15005550006', 'Body' => ' HOLA ', 'NumMedia' => '0'], ...$extra];
    }

    private function postSms(array $data, string $action = 'inbound', ?string $signature = null): TestResponse
    {
        $url = config('messaging.twilio.'.$action.'_url');
        $signature ??= (new RequestValidator('test-twilio'))->computeSignature($url, $data);

        return $this->call('POST', '/api/v1/webhooks/sms/'.$action, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => $signature], http_build_query($data));
    }

    private function outbound(string $provider = 'evolution', array $extra = [], array $contact = []): Message
    {
        $c = Conversation::factory()->create([...['provider' => $provider, 'account' => $provider === 'twilio' ? $this->sid : '123456', 'channel' => $provider === 'twilio' ? 'sms' : 'whatsapp', 'consent' => 'granted', 'state' => 'READY', 'last_inbound_at' => now()], ...$contact]);

        return Message::factory()->create([...['conversation_id' => $c->id, 'provider' => $provider, 'account' => $c->account, 'direction' => 'outbound', 'external_id' => null, 'body' => 'Oferta recibida para revisión.', 'status' => 'queued', 'metadata' => []], ...$extra]);
    }

    public function test_meta_challenge_requires_correct_mode_and_token(): void
    {
        $this->get('/api/v1/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=test-verify&hub.challenge=12345')->assertOk()->assertContent('12345');
        $this->get('/api/v1/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_unsigned_and_modified_meta_bodies_are_rejected_without_persistence(): void
    {
        $this->postMeta($this->meta([$this->incoming()]), 'sha256=invalid')->assertForbidden();
        $signature = 'sha256='.hash_hmac('sha256', json_encode($this->meta([$this->incoming()])), 'test-meta-secret');
        $this->postMeta($this->meta([$this->incoming(text: 'VENDER')]), $signature)->assertForbidden();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_meta_processes_every_message_once_and_durably_splits_evolution_sync(): void
    {
        $data = $this->meta([$this->incoming(), $this->incoming('wamid.in-2', 'Mi DNI 12345678')]);
        $this->postMeta($data)->assertOk();
        $this->postMeta($data)->assertOk();
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('outbox_events', 4);
        $this->assertSame('received', Message::query()->first()->status);
        $events = OutboxEvent::query()->where('type', 'forward_evolution')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertCount(1, $event->payload['entry'][0]['changes'][0]['value']['messages']);
            $this->assertStringNotContainsString('12345678', json_encode($event->payload));
        }
        Http::assertNothingSent();
    }

    public function test_foreign_account_rolls_back_the_entire_webhook_batch(): void
    {
        $data = $this->meta([$this->incoming()]);
        $wrong = $data['entry'][0];
        $wrong['id'] = '999';
        $data['entry'][] = $wrong;
        $this->postMeta($data)->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_canonical_meta_receipts_do_not_create_conversations_or_offers(): void
    {
        $this->postMeta($this->meta(statuses: [['id' => 'wamid.out', 'status' => 'read', 'timestamp' => (string) now()->timestamp]]))->assertOk();
        $this->assertDatabaseCount('message_receipts', 1);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_evolution_callback_is_authenticated_and_cannot_duplicate_canonical_messages(): void
    {
        $data = ['instance' => 'agroconecta', 'event' => 'messages.upsert', 'data' => ['text' => 'VENDER']];
        $this->postJson(route('webhooks.evolution'), $data)->assertForbidden();
        $this->withHeader('X-Evolution-Secret', 'test-callback')->postJson(route('webhooks.evolution'), $data)->assertOk();
        $this->postJson(route('webhooks.evolution'), [...$data, 'instance' => 'other'])->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_twilio_validates_all_original_form_fields_and_deduplicates_sid(): void
    {
        $data = $this->sms(['FutureTwilioField' => ' value with spaces ']);
        $this->postSms($data)->assertOk()->assertSee('<Response/>', false);
        $this->postSms($data)->assertOk();
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame(' HOLA ', Message::query()->sole()->body);
        $this->assertDatabaseCount('outbox_events', 1);
        Http::assertNothingSent();
    }

    public function test_twilio_bad_signature_account_and_destination_are_rejected(): void
    {
        $this->postSms($this->sms(), signature: 'bad')->assertForbidden();
        $this->postSms($this->sms(['AccountSid' => 'ACother']))->assertUnprocessable();
        $this->postSms($this->sms(['To' => '+15005550007']))->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_twilio_two_way_capability_must_be_explicitly_verified(): void
    {
        config(['messaging.twilio.two_way_verified' => false]);
        $this->postSms($this->sms())->assertStatus(503);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_twilio_status_callback_reconciles_early_receipt_after_acceptance(): void
    {
        config(['messaging.live_enabled' => true]);
        $message = $this->outbound('twilio');
        $this->postSms($this->sms(['MessageStatus' => 'delivered']), 'status')->assertNoContent();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => $this->messageSid, 'account_sid' => $this->sid, 'status' => 'queued'], 201)]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('delivered', $message->fresh()->status);
        Http::assertSent(fn ($request): bool => $request['To'] === '+51900000003' && $request['StatusCallback'] === config('messaging.twilio.status_url') && $request->hasHeader('Authorization', 'Basic '.base64_encode($this->sid.':test-twilio')));
    }

    public function test_live_delivery_is_disabled_by_default_even_with_credentials(): void
    {
        $message = $this->outbound();
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('blocked', $message->fresh()->status);
        $this->assertSame('live_disabled', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_evolution_sends_official_text_contract_and_requires_wamid(): void
    {
        config(['messaging.live_enabled' => true]);
        $message = $this->outbound();
        Http::fake(['evolution:8080/*' => Http::response(['key' => ['id' => 'wamid.accepted']], 201)]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('accepted', $message->fresh()->status);
        Http::assertSent(fn ($r): bool => $r->url() === 'http://evolution:8080/message/sendText/agroconecta' && $r['text'] === $message->body && $r['number'] === '51900000003' && $r->hasHeader('apikey', 'test-evolution'));
    }

    public function test_http_success_without_provider_id_is_uncertain_and_never_automatically_resent(): void
    {
        config(['messaging.live_enabled' => true]);
        $message = $this->outbound();
        Http::fake(['evolution:8080/*' => Http::response(['status' => 'success'], 201)]);
        app(MessageDelivery::class)->deliver($message->id);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('uncertain', $message->fresh()->status);
        $this->assertSame('missing_acceptance', $message->fresh()->error_code);
        Http::assertSentCount(1);
    }

    public function test_timeout_and_interrupted_worker_do_not_resend_possibly_accepted_messages(): void
    {
        config(['messaging.live_enabled' => true]);
        $message = $this->outbound();
        Http::fake(fn () => throw new ConnectionException('Timeout'));
        app(MessageDelivery::class)->deliver($message->id);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('uncertain', $message->fresh()->status);
        $this->assertSame(1, $message->attempts()->count());
        $message->update(['status' => 'sending', 'lease_until' => now()->subMinute()]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('worker_interrupted', $message->fresh()->error_code);
    }

    public function test_rate_limit_is_retried_with_delay_and_bounded_attempts(): void
    {
        config(['messaging.live_enabled' => true]);
        $message = $this->outbound();
        Http::fake(['evolution:8080/*' => Http::response([], 429)]);
        app(MessageDelivery::class)->deliver($message->id);
        app(MessageDelivery::class)->deliver($message->id);
        Http::assertSentCount(1);
        $this->assertSame('queued', $message->fresh()->status);
        $this->travel(2)->minutes();
        app(MessageDelivery::class)->deliver($message->id);
        $this->travel(2)->minutes();
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('retry_limit', $message->fresh()->error_code);
        Http::assertSentCount(3);
    }

    public function test_free_text_outside_whatsapp_window_is_blocked(): void
    {
        config(['messaging.live_enabled' => true]);
        $message = $this->outbound(contact: ['last_inbound_at' => now()->subHours(24)]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('approved_template_required', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_only_configured_template_snapshot_can_be_sent_outside_window(): void
    {
        $template = ['name' => 'test_approved_template', 'language' => 'es', 'components' => []];
        config(['messaging.live_enabled' => true, 'messaging.approved_templates' => ['test' => $template]]);
        $message = $this->outbound(extra: ['metadata' => ['template_key' => 'test', 'template' => $template]], contact: ['last_inbound_at' => now()->subDays(2)]);
        Http::fake(['evolution:8080/*' => Http::response(['key' => ['id' => 'wamid.template']], 201)]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('accepted', $message->fresh()->status);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/sendTemplate/') && $r['name'] === $template['name'] && $r['language'] === 'es' && ! isset($r['text']));
    }

    public function test_sms_segments_and_revoked_consent_block_sends(): void
    {
        config(['messaging.live_enabled' => true, 'messaging.max_sms_segments' => 1]);
        $message = $this->outbound('twilio', ['body' => str_repeat('a', 161)]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('sms_segment_limit', $message->fresh()->error_code);
        $message->refresh()->update(['status' => 'queued', 'body' => 'Mensaje breve']);
        $message->conversation->update(['consent' => 'declined']);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('consent_revoked', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_invalid_twilio_acceptance_and_server_failure_are_uncertain(): void
    {
        config(['messaging.live_enabled' => true]);
        $message = $this->outbound('twilio');
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'not-a-sid'], 201)]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('uncertain', $message->fresh()->status);
    }

    public function test_prepare_evolution_refuses_missing_secrets_and_does_not_send_messages(): void
    {
        config(['messaging.whatsapp.access_token' => null]);
        $this->artisan('messaging:prepare-evolution')->assertFailed();
        Http::assertNothingSent();
    }
}
