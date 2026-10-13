<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversation;
use App\Models\OutboxEvent;
use App\Services\Messaging\OutboxDispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class OutboxImmediateDispatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'redis']);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_new_event_dispatches_only_after_the_outer_transaction_commits(): void
    {
        $event = DB::transaction(function (): OutboxEvent {
            $event = DB::transaction(fn () => OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]));
            Bus::assertNothingDispatched();
            $this->assertSame('pending', $event->fresh()->status);

            return $event;
        });

        Bus::assertDispatched(ProcessConversation::class, fn ($job) => $job->eventId === $event->id
            && $job->conversationId === 42 && $job->connection === 'redis' && $job->queue === 'default');
        Bus::assertDispatchedTimes(ProcessConversation::class, 1);
        $this->assertSame('dispatched', $event->fresh()->status);
        $this->assertSame(1, $event->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_rolled_back_event_never_dispatches_even_after_another_commit(): void
    {
        try {
            DB::transaction(function (): void {
                OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]);
                throw new RuntimeException('Rollback this message');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback this message', $exception->getMessage());
        }
        DB::transaction(fn () => null);

        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_duplicate_enqueue_and_scheduler_respect_the_same_event_lease(): void
    {
        $event = OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]);
        $duplicate = OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]);

        $this->assertSame($event->id, $duplicate->id);
        $this->assertSame(0, app(OutboxDispatcher::class)->dispatch());
        $this->assertFalse(app(OutboxDispatcher::class)->dispatchEvent($event->id));
        Bus::assertDispatchedTimes(ProcessConversation::class, 1);
        $this->assertSame(1, $event->fresh()->attempts);

        $event->update(['status' => 'done', 'lease_until' => null]);
        $this->assertFalse(app(OutboxDispatcher::class)->dispatchEvent($event->id));
        Bus::assertDispatchedTimes(ProcessConversation::class, 1);
    }

    public function test_redis_outage_keeps_the_commit_and_scheduler_recovers_the_event(): void
    {
        $dispatcher = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));

        $event = DB::transaction(fn () => OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]));

        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame('queue_unavailable', $event->fresh()->error_code);
        $this->assertNull($event->fresh()->lease_until);
        $this->assertTrue($event->fresh()->available_at->isFuture());

        Bus::swap($dispatcher);
        Bus::fake();
        $this->travel(61)->seconds();
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatch());
        $this->assertSame(0, app(OutboxDispatcher::class)->dispatch());
        Bus::assertDispatchedTimes(ProcessConversation::class, 1);
    }

    public function test_event_rescheduled_before_commit_does_not_dispatch_before_its_retry_time(): void
    {
        $event = DB::transaction(function (): OutboxEvent {
            $event = OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]);
            $event->update(['available_at' => now()->addMinute()]);

            return $event;
        });

        Bus::assertNothingDispatched();
        $this->assertSame('pending', $event->fresh()->status);
        $this->travel(61)->seconds();
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatch());
        Bus::assertDispatchedTimes(ProcessConversation::class, 1);
    }

    public function test_failed_immediate_dispatch_does_not_fail_the_already_committed_message(): void
    {
        $this->mock(OutboxDispatcher::class)->shouldReceive('dispatchEvent')->once()->andThrow(new RuntimeException('Unavailable'));
        Log::spy();

        $event = DB::transaction(fn () => OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]));

        $this->assertSame('pending', $event->fresh()->status);
        $this->assertDatabaseCount('outbox_events', 1);
        Bus::assertNothingDispatched();
        Log::shouldHaveReceived('warning')->once()->with('outbox_immediate_dispatch_deferred', ['outbox_event_id' => $event->id]);
    }

    public function test_expired_event_lease_can_be_recovered_once(): void
    {
        $event = OutboxEvent::factory()->create([
            'payload' => ['conversation_id' => 42],
            'status' => 'dispatched',
            'attempts' => 1,
            'lease_until' => now()->subSecond(),
        ]);

        $this->assertTrue(app(OutboxDispatcher::class)->dispatchEvent($event->id));
        $this->assertSame(0, app(OutboxDispatcher::class)->dispatch());
        Bus::assertDispatchedTimes(ProcessConversation::class, 1);
        $this->assertSame(2, $event->fresh()->attempts);
    }

    public function test_synchronous_environment_keeps_dispatch_under_explicit_control(): void
    {
        config(['queue.default' => 'sync']);

        $event = DB::transaction(fn () => OutboxEvent::enqueue('process:42', 'process', ['conversation_id' => 42]));

        Bus::assertNothingDispatched();
        $this->assertSame('pending', $event->fresh()->status);
    }
}
