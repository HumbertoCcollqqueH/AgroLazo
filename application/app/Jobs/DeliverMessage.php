<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\OutboxEvent;
use App\Services\Messaging\MessageDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $eventId, public int $messageId) {}

    public function handle(MessageDelivery $delivery): void
    {
        $delivery->deliver($this->messageId);
        if (Message::query()->whereKey($this->messageId)->where('status', 'sending')->exists()) {
            return;
        }
        OutboxEvent::query()->whereKey($this->eventId)->update(['status' => 'done', 'lease_until' => null, 'error_code' => null]);
    }
}
