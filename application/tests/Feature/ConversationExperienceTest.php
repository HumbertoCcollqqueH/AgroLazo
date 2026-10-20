<?php

namespace Tests\Feature;

use App\Events\ConversationUpdated;
use App\Models\CoordinationThread;
use App\Models\User;
use App\Services\ProducerPortal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationExperienceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Event::fake([ConversationUpdated::class]);
    }

    public function test_conversations_are_separate_from_notifications_and_owned(): void
    {
        $owner = User::factory()->withRole('buyer')->create();
        $other = User::factory()->withRole('producer')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Entrega de quinua']);
        $thread->messages()->create(['user_id' => $owner->id, 'body' => 'Necesito coordinar el ingreso.', 'submission_key' => Str::uuid()]);
        ProducerPortal::notify($owner, 'Cuenta aprobada para comprar.', '/marketplace', 'approved');
        $this->actingAs($owner)->get(route('coordination.index'))->assertOk()->assertSee('Entrega de quinua')->assertDontSee('Activar notificaciones')->assertDontSee('Cuenta aprobada para comprar.');
        $this->get(route('notifications.index'))->assertOk()->assertSee('Cuenta aprobada para comprar.')->assertDontSee('Necesito coordinar el ingreso.');
        $this->getJson(route('coordination.conversations'))->assertJsonPath('threads.0.subject', 'Entrega de quinua');
        $this->actingAs($other)->getJson(route('coordination.conversations'))->assertJsonCount(0, 'threads');
        $this->getJson(route('coordination.messages', $thread))->assertNotFound();
        $this->get(route('coordination.show', $thread))->assertNotFound();
    }

    public function test_live_replies_retry_once_and_cannot_be_replayed_by_another_user(): void
    {
        $owner = User::factory()->withRole('buyer')->create();
        $operator = User::factory()->withRole('coordinator')->create();
        $other = User::factory()->withRole('producer')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Entrega']);
        $data = ['body' => 'Llegamos a las ocho.', 'submission_key' => (string) Str::uuid()];
        $this->actingAs($operator)->postJson(route('coordination.reply', $thread), $data)->assertOk()->assertJsonPath('message.own', true);
        $this->postJson(route('coordination.reply', $thread), $data)->assertOk();
        $this->assertSame(1, $thread->messages()->count());
        $this->actingAs($owner)->getJson(route('coordination.messages', $thread))->assertJsonPath('messages.0.body', $data['body'])->assertJsonPath('messages.0.own', false);
        $this->postJson(route('coordination.reply', $thread), $data)->assertConflict();
        $this->actingAs($other)->postJson(route('coordination.reply', $thread), ['body' => 'Otro', 'submission_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_cursor_catches_up_all_messages_and_older_pages_do_not_mix_threads(): void
    {
        $owner = User::factory()->withRole('buyer')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Historial']);
        for ($i = 0; $i < 65; $i++) {
            $thread->messages()->create(['user_id' => $owner->id, 'body' => 'Mensaje '.$i, 'submission_key' => Str::uuid()]);
        }
        $this->actingAs($owner);
        $data = $this->getJson(route('coordination.messages', $thread))->assertOk()->assertJsonCount(60, 'messages')->assertJsonPath('has_more', true)->json();
        $cursor = $data['messages'][59]['id'];
        $this->getJson(route('coordination.messages', [$thread, 'after' => $cursor]))->assertJsonCount(5, 'messages')->assertJsonPath('has_more', false);
        $this->getJson(route('coordination.messages', [$thread, 'before' => $cursor]))->assertJsonCount(59, 'messages')->assertJsonPath('messages.0.body', 'Mensaje 0');
        $this->get(route('coordination.show', $thread))->assertOk()->assertSee('data-chat-composer', false)->assertDontSee('Actualizar');
    }

    public function test_operator_can_choose_a_recipient_but_customers_cannot_contact_other_accounts(): void
    {
        $owner = User::factory()->withRole('buyer')->create();
        $operator = User::factory()->withRole('coordinator')->create();
        $data = ['recipient_id' => $owner->id, 'subject' => 'Tu entrega', 'body' => 'Coordinemos el acceso.', 'submission_key' => (string) Str::uuid()];
        $this->actingAs($operator)->post(route('coordination.store'), $data)->assertRedirect();
        $this->assertDatabaseHas('coordination_threads', ['user_id' => $owner->id]);
        $this->actingAs($owner)->post(route('coordination.store'), [...$data, 'recipient_id' => $operator->id, 'submission_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_seen_message_must_belong_to_the_thread_and_disabled_users_are_blocked(): void
    {
        $owner = User::factory()->withRole('buyer')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Consulta']);
        $otherThread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Otra']);
        $message = $otherThread->messages()->create(['user_id' => $owner->id, 'body' => 'Privado', 'submission_key' => Str::uuid()]);
        $this->actingAs($owner)->postJson(route('coordination.seen', $thread), ['message_id' => $message->id])->assertNotFound();
        $owner->forceFill(['active' => false])->save();
        $this->getJson(route('coordination.messages', $thread))->assertForbidden();
    }

    public function test_polling_does_not_consume_the_write_quota(): void
    {
        $owner = User::factory()->withRole('buyer')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Consulta']);
        $this->actingAs($owner);
        for ($i = 0; $i < 12; $i++) {
            $this->getJson(route('coordination.messages', $thread))->assertOk();
        }
        $this->post(route('coordination.store'), ['subject' => 'Otra consulta', 'body' => 'Mi entrega', 'submission_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseHas('coordination_threads', ['subject' => 'Otra consulta']);
    }

    public function test_another_message_in_the_same_second_remains_unread(): void
    {
        $this->freezeTime();
        $owner = User::factory()->withRole('buyer')->create();
        $operator = User::factory()->withRole('coordinator')->create();
        $thread = CoordinationThread::create(['user_id' => $owner->id, 'subject' => 'Consulta']);
        $first = $thread->messages()->create(['user_id' => $owner->id, 'body' => 'Primero', 'submission_key' => Str::uuid()]);
        $this->actingAs($operator)->postJson(route('coordination.seen', $thread), ['message_id' => $first->id])->assertOk();
        $thread->messages()->create(['user_id' => $owner->id, 'body' => 'Segundo', 'submission_key' => Str::uuid()]);
        $this->getJson(route('coordination.conversations'))->assertJsonPath('threads.0.unread', true);
    }
}
