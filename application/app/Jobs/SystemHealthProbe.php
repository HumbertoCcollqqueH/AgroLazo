<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SystemHealthProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly ?string $probeId = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cache = Cache::store('redis');
        $cache->put('system:queue:heartbeat', time(), 180);

        if ($this->probeId !== null) {
            $cache->put('system:queue:probe:'.$this->probeId, true, 120);
        }
    }
}
