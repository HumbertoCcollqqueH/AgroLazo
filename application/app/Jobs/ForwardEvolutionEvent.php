<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ForwardEvolutionEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = OutboxEvent::query()->findOrFail($this->eventId);
        if ($event->status === 'done') {
            return;
        }
        if (config('platform.internal_communication')) {
            $event->update(['status' => 'blocked', 'error_code' => 'internal_channel_only', 'lease_until' => null]);

            return;
        }
        if (! config('messaging.whatsapp.enabled') || ! config('messaging.live_enabled')) {
            $event->update(['status' => 'blocked', 'error_code' => 'live_disabled', 'lease_until' => null]);

            return;
        }
        try {
            $response = Http::baseUrl(rtrim((string) config('messaging.evolution.url'), '/'))->withHeaders(['apikey' => config('messaging.evolution.api_key')])
                ->timeout(15)->connectTimeout(5)->withoutRedirecting()->post('/webhook/meta', $event->payload);
            if ($response->successful()) {
                $event->update(['status' => 'done', 'error_code' => null, 'lease_until' => null]);

                return;
            }
        } catch (ConnectionException) {
            // Canonical ingestion is already durable; this only synchronizes Evolution.
        }
        $event->update(['status' => $event->attempts >= 10 ? 'blocked' : 'pending', 'error_code' => 'evolution_sync_unconfirmed', 'lease_until' => null, 'available_at' => now()->addMinutes(2)]);
    }
}
