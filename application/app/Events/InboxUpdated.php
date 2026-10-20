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

class InboxUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $connection;

    public function __construct(private int $userId, public string $revision = '')
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
        return [
            new PrivateChannel('coordination.user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    /** @return array{revision: string} */
    public function broadcastWith(): array
    {
        return ['revision' => $this->revision];
    }

    public function broadcastQueue(): string
    {
        return 'realtime';
    }
}
