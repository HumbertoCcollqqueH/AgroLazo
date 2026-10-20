<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $connection;

    /** @param array<int, int> $userIds */
    public function __construct(public int $threadId, public int $revision, private array $userIds = [])
    {
        $this->connection = in_array(config('broadcasting.default'), [null, 'null'], true) ? 'sync' : 'realtime';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('coordination.thread.'.$this->threadId)];
        foreach (array_unique($this->userIds) as $userId) {
            $channels[] = new PrivateChannel('coordination.user.'.(int) $userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /** @return array{thread_id: int, revision: int} */
    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId, 'revision' => $this->revision];
    }

    public function broadcastQueue(): string
    {
        return 'realtime';
    }
}
