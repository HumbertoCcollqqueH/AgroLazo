<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OutboxEvent> */
class OutboxEventFactory extends Factory
{
    public function definition(): array
    {
        return ['event_key' => (string) Str::uuid(), 'type' => 'process', 'payload' => ['conversation_id' => Conversation::factory()->create()->id], 'status' => 'pending', 'available_at' => now()];
    }
}
