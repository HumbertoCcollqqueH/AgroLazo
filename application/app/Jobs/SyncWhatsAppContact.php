<?php

namespace App\Jobs;

use App\Services\Messaging\EvolutionConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncWhatsAppContact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    public int $timeout = 30;

    public function __construct(public int $producerId) {}

    public function handle(EvolutionConnectionManager $manager): void
    {
        $manager->syncContact($this->producerId);
    }
}
