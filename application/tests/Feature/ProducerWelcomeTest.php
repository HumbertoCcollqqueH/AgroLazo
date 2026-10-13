<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Producer;
use App\Models\ProducerConsent;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\MessageDelivery;
use App\Services\Messaging\ProducerWelcome;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProducerWelcomeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actor = User::factory()->withRole('admin')->create();
        config(['messaging.evolution.url' => 'http://evolution.test', 'messaging.evolution.api_key' => 'test-key',
            'messaging.registered_contacts_only' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        WhatsAppConnection::query()->create(['id' => 1, 'integration' => 'WHATSAPP-BAILEYS', 'instance_name' => 'test-qr',
            'status' => 'connected', 'automation_enabled' => true, 'notice_approved' => true,
            'notice_text' => 'Usaremos tu contacto para registrar ofertas agrícolas de prueba.', 'notice_version' => 'test-notice-v1', 'revision' => 1, 'updated_by' => $this->actor->id]);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_explicit_welcome_is_unique_and_does_not_fabricate_inbound_or_consent(): void
    {
        $producer = Producer::factory()->verified()->create(['full_name' => 'Productora de prueba']);
        $service = app(ProducerWelcome::class);
        $message = $service->queue($this->actor, $producer);
        $duplicate = $service->queue($this->actor, $producer);
        $this->assertSame($message->id, $duplicate->id);
        $this->assertStringContainsString('¡Hola, Productora de prueba!', $message->body);
        $this->assertStringContainsString('responde ACEPTO', $message->body);
        $this->assertStringContainsString('BAJA', $message->body);
        $this->assertTrue($message->metadata['is_onboarding']);
        $this->assertSame('pending', $message->conversation->consent);
        $this->assertSame('WAITING_CONSENT', $message->conversation->state);
        $this->assertNull($message->conversation->last_inbound_at);
        $this->assertDatabaseCount('producer_consents', 0);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->actor->id, 'action' => 'conversation.welcome_queued']);
        Http::assertNothingSent();
    }

    public function test_welcome_uses_normal_delivery_and_repeat_does_not_send_twice(): void
    {
        $producer = Producer::factory()->verified()->create();
        $message = app(ProducerWelcome::class)->queue($this->actor, $producer);
        Http::fake(['evolution.test/message/sendText/test-qr' => Http::response(['key' => ['id' => 'WELCOME-ACCEPTED']])]);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('accepted', $message->fresh()->status);
        $duplicate = app(ProducerWelcome::class)->queue($this->actor, $producer);
        app(MessageDelivery::class)->deliver($duplicate->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['number'] === ltrim($producer->phone, '+') && str_contains($request['text'], 'ACEPTO'));
        $this->assertDatabaseCount('producer_consents', 0);
    }

    public function test_preview_command_never_creates_a_conversation_or_sends(): void
    {
        $producer = Producer::factory()->verified()->create();
        $this->artisan('messaging:welcome-producer', ['producer' => $producer->id])->assertSuccessful();
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_declined_contact_cannot_be_invited_again(): void
    {
        $producer = Producer::factory()->verified()->create();
        ProducerConsent::query()->create(['producer_id' => $producer->id, 'recorded_by' => $this->actor->id,
            'purpose' => 'whatsapp_messages', 'notice_version' => 'old', 'channel' => 'whatsapp', 'decision' => 'declined', 'recorded_at' => now()]);
        $this->expectException(ValidationException::class);
        try {
            app(ProducerWelcome::class)->queue($this->actor, $producer);
        } finally {
            $this->assertDatabaseCount('conversations', 0);
            $this->assertDatabaseCount('messages', 0);
            Http::assertNothingSent();
        }
    }

    public function test_existing_draft_is_not_reset_by_a_welcome(): void
    {
        $producer = Producer::factory()->verified()->create();
        $conversation = Conversation::factory()->create(['provider' => 'evolution', 'account' => 'qr:test-qr',
            'producer_id' => $producer->id, 'phone' => $producer->phone, 'phone_lookup' => $producer->phone_lookup,
            'state' => 'OFFER_QUANTITY', 'consent' => 'granted', 'context' => ['draft' => ['product_id' => 1]]]);
        $this->expectException(ValidationException::class);
        try {
            app(ProducerWelcome::class)->queue($this->actor, $producer);
        } finally {
            $this->assertSame('OFFER_QUANTITY', $conversation->fresh()->state);
            $this->assertSame(['draft' => ['product_id' => 1]], $conversation->fresh()->context);
            $this->assertDatabaseCount('messages', 0);
            Http::assertNothingSent();
        }
    }

    public function test_suspension_before_delivery_blocks_the_queued_welcome(): void
    {
        $producer = Producer::factory()->verified()->create();
        $message = app(ProducerWelcome::class)->queue($this->actor, $producer);
        $producer->update(['status' => 'suspended']);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('unregistered_contact', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_buyer_cannot_queue_a_welcome(): void
    {
        $this->expectException(AuthorizationException::class);
        try {
            app(ProducerWelcome::class)->queue(User::factory()->withRole('buyer')->create(), Producer::factory()->verified()->create());
        } finally {
            $this->assertDatabaseCount('messages', 0);
            Http::assertNothingSent();
        }
    }

    public function test_disconnected_or_official_connection_does_not_queue_a_plain_welcome(): void
    {
        $producer = Producer::factory()->verified()->create();
        foreach ([['status' => 'disconnected'], ['status' => 'configured', 'integration' => 'WHATSAPP-BUSINESS']] as $change) {
            WhatsAppConnection::query()->findOrFail(1)->update($change);
            try {
                app(ProducerWelcome::class)->queue($this->actor, $producer);
                $this->fail('La bienvenida debió ser rechazada.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('messages', 0);
            }
        }
        Http::assertNothingSent();
    }
}
