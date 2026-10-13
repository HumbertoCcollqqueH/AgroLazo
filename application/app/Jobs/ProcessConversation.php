<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Services\Messaging\ConversationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $eventId, public int $conversationId) {}

    public function handle(ConversationEngine $engine): void
    {
        $engine->process($this->conversationId);
        OutboxEvent::query()->whereKey($this->eventId)->update(['status' => 'done', 'lease_until' => null, 'error_code' => null]);
    }
}
