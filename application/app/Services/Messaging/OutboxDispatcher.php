<?php

namespace App\Services\Messaging;

use App\Jobs\DeliverMessage;
use App\Jobs\DeliverOperationalNotice;
use App\Jobs\ForwardEvolutionEvent;
use App\Jobs\ProcessConversation;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboxDispatcher
{
    public function dispatch(int $limit = 100): int
    {
        $count = 0;
        while ($count < $limit && $this->dispatchNext()) {
            $count++;
        }

        return $count;
    }

    public function dispatchEvent(int $eventId): bool
    {
        return $this->dispatchNext($eventId);
    }

    private function dispatchNext(?int $eventId = null): bool
    {
        $event = DB::transaction(function () use ($eventId): ?OutboxEvent {
            $event = OutboxEvent::query()->whereIn('status', ['pending', 'dispatched'])->where('available_at', '<=', now())
                ->when($eventId !== null, fn ($query) => $query->whereKey($eventId))
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->orderBy('id')->lock('for update skip locked')->first();
            if ($event) {
                $event->update(['status' => 'dispatched', 'lease_until' => now()->addMinutes(5), 'attempts' => $event->attempts + 1]);
            }

            return $event;
        });
        if (! $event) {
            return false;
        }
        try {
            $job = match ($event->type) {
                'process' => new ProcessConversation($event->id, $event->payload['conversation_id']),
                'deliver' => new DeliverMessage($event->id, $event->payload['message_id']),
                'forward_evolution' => new ForwardEvolutionEvent($event->id),
                'commerce_notice' => new DeliverOperationalNotice($event->id, $event->payload['notice_id']),
            };
            Bus::dispatch($job->onConnection('redis')->onQueue('default'));
        } catch (Throwable) {
            $event->update(['status' => 'pending', 'lease_until' => null, 'available_at' => now()->addMinute(), 'error_code' => 'queue_unavailable']);
        }

        return true;
    }
}
