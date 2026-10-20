<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Services\Messaging\EvolutionConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PrepareWhatsAppConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $revision) {}

    public function handle(EvolutionConnectionManager $manager): void
    {
        if (config('platform.internal_communication')) {
            return;
        }
        $lock = Cache::lock('whatsapp:connection:prepare', 100);
        if (! $lock->get()) {
            return;
        }
        try {
            $connection = WhatsAppConnection::query()->find(1);
            if ($connection === null || $connection->revision !== $this->revision) {
                return;
            }
            $manager->prepare($connection);
        } catch (Throwable $exception) {
            $known = ['evolution_not_configured', 'instance_lookup_failed', 'instance_integration_mismatch', 'meta_not_configured', 'instance_creation_uncertain', 'callback_setup_failed', 'qr_request_failed'];
            $code = in_array($exception->getMessage(), $known, true) ? $exception->getMessage() : 'connection_failed';
            WhatsAppConnection::query()->whereKey(1)->where('revision', $this->revision)
                ->update(['status' => 'error', 'last_error' => $code]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        WhatsAppConnection::query()->whereKey(1)->where('revision', $this->revision)
            ->update(['status' => 'error', 'last_error' => 'connection_interrupted']);
    }
}
