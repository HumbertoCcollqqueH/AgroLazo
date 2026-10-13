<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Services\OperationalNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverOperationalNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $eventId, public int $noticeId) {}

    public function handle(OperationalNotifier $notifier): void
    {
        $notifier->deliver($this->noticeId);
        OutboxEvent::query()->whereKey($this->eventId)->update(['status' => 'done', 'lease_until' => null, 'error_code' => null]);
    }
}
