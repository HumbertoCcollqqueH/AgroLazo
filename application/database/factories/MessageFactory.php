<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return ['conversation_id' => Conversation::factory(), 'direction' => 'inbound', 'provider' => 'fake', 'account' => 'local-simulator', 'external_id' => (string) Str::uuid(), 'deduplication_key' => (string) Str::uuid(), 'body' => 'HOLA', 'status' => 'received', 'received_at' => now()];
    }
}
