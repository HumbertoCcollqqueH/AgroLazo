<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\Producer;

class RegisteredContactPolicy
{
    public function producer(string $lookup): ?Producer
    {
        return Producer::query()->where('phone_lookup', $lookup)
            ->whereIn('status', ['pending_verification', 'verified'])->first();
    }

    public function accepts(Conversation $conversation): bool
    {
        if (! config('messaging.registered_contacts_only', true)) {
            return true;
        }
        $producer = $this->producer($conversation->phone_lookup);

        return $producer !== null && ($conversation->producer_id === null || $conversation->producer_id === $producer->id);
    }
}
