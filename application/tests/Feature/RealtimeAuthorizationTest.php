<?php

namespace Tests\Feature;

use App\Events\ConversationUpdated;
use App\Events\InboxUpdated;
use App\Models\CoordinationThread;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class RealtimeAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');
    }

    public function test_private_channels_require_login_and_ownership_or_operation_permission(): void
    {
        $owner = User::factory()->withRole('producer')->create();
        $other = User::factory()->withRole('buyer')->create();
        $operator = User::factory()->withRole('coordinator')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Mi oferta', 'status' => 'open']);
        $channel = 'private-coordination.thread.'.$thread->id;

        $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertUnauthorized();
        $this->actingAs($owner)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs($other)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertForbidden();
        $this->actingAs($operator)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertOk();
        $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-coordination.user.'.$owner->id])->assertForbidden();
        $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-coordination.user.'.$operator->id])->assertOk();
    }

    public function test_disabled_and_revoked_operators_cannot_subscribe(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->withRole('coordinator')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Entrega', 'status' => 'open']);
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-coordination.thread.'.$thread->id];
        $operator->roles()->detach();
        $this->actingAs($operator)->postJson('/broadcasting/auth', $payload)->assertForbidden();
        $owner->forceFill(['active' => false])->save();
        $this->actingAs($owner)->postJson('/broadcasting/auth', $payload)->assertForbidden();
    }

    public function test_broadcasts_never_include_messages_or_profile_details(): void
    {
        $event = new ConversationUpdated(7, 12, [1, 2, 2]);
        $this->assertSame(['thread_id' => 7, 'revision' => 12], $event->broadcastWith());
        $this->assertSame([
            'private-coordination.thread.7', 'private-coordination.user.1', 'private-coordination.user.2',
        ], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
        $notice = new InboxUpdated(1, 'updated');
        $this->assertSame(['revision' => 'updated'], $notice->broadcastWith());
        $this->assertSame('private-coordination.user.1', $notice->broadcastOn()[0]->name);
    }

    public function test_csp_permits_only_the_configured_websocket_origin(): void
    {
        $response = $this->get('/login');
        $this->assertStringContainsString("connect-src 'self' ws://localhost:8080;", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('wss://*', $response->headers->get('Content-Security-Policy'));
    }
}
