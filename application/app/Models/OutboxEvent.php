<?php

namespace App\Models;

use App\Services\Messaging\OutboxDispatcher;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Fillable(['event_key', 'type', 'payload', 'status', 'attempts', 'available_at', 'lease_until', 'error_code'])]
#[Hidden(['payload'])]
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'attempts' => 'integer', 'available_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
    }

    public static function enqueue(string $key, string $type, array $payload): self
    {
        $event = self::query()->firstOrCreate(['event_key' => $key], ['type' => $type, 'payload' => $payload, 'available_at' => now()]);
        if ($event->wasRecentlyCreated && config('queue.default') === 'redis') {
            $eventId = $event->id;
            DB::afterCommit(function () use ($eventId): void {
                try {
                    app(OutboxDispatcher::class)->dispatchEvent($eventId);
                } catch (Throwable) {
                    Log::warning('outbox_immediate_dispatch_deferred', ['outbox_event_id' => $eventId]);
                }
            });
        }

        return $event;
    }
}
