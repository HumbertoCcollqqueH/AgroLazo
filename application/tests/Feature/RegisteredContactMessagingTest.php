<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Producer;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\MessageDelivery;
use App\Services\Messaging\MessageIngress;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegisteredContactMessagingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['messaging.registered_contacts_only' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
    }

    private function inbound(string $phone, string $body = 'HOLA'): ?Message
    {
        return app(MessageIngress::class)->receive('fake', 'local-simulator', 'whatsapp', (string) Str::uuid(), $phone, $body, CarbonImmutable::now());
    }

    public function test_unknown_phone_is_silent_and_not_persisted(): void
    {
        $this->assertNull($this->inbound('+51900000901'));
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        Http::assertNothingSent();
    }

    public function test_registering_a_phone_enables_the_next_message_and_still_requires_consent(): void
    {
        $phone = '+51900000902';
        $this->assertNull($this->inbound($phone));
        $producer = Producer::factory()->create(['phone' => $phone]);
        $message = $this->inbound($phone);
        $this->assertNotNull($message);
        app(ConversationEngine::class)->process($message->conversation_id);
        $conversation = $message->conversation->fresh();
        $this->assertSame($producer->id, $conversation->producer_id);
        $this->assertSame('pending', $conversation->consent);
        $this->assertSame('WAITING_CONSENT', $conversation->state);
        $this->assertStringContainsString('ACEPTO', $conversation->messages()->where('direction', 'outbound')->sole()->body);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_suspension_blocks_already_queued_replies_and_further_inbound_messages(): void
    {
        $producer = Producer::factory()->verified()->create(['phone' => '+51900000903']);
        $message = $this->inbound($producer->phone);
        app(ConversationEngine::class)->process($message->conversation_id);
        $outbound = $message->conversation->messages()->where('direction', 'outbound')->sole();
        $producer->update(['status' => 'suspended']);
        app(MessageDelivery::class)->deliver($outbound->id);
        $this->assertSame('blocked', $outbound->fresh()->status);
        $this->assertSame('unregistered_contact', $outbound->fresh()->error_code);
        $this->assertNull($this->inbound($producer->phone));
        Http::assertNothingSent();
    }

    public function test_phone_change_does_not_allow_processing_or_delivery_to_the_old_contact(): void
    {
        $producer = Producer::factory()->verified()->create(['phone' => '+51900000904']);
        $message = $this->inbound($producer->phone);
        $producer->update(['phone' => '+51900000905']);
        app(ConversationEngine::class)->process($message->conversation_id);
        $this->assertSame('unregistered_contact', $message->fresh()->metadata['ignored_reason']);
        $this->assertDatabaseCount('messages', 1);
        $this->assertNull($this->inbound('+51900000904'));
        Http::assertNothingSent();
    }
}
