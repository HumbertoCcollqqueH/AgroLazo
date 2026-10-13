<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MessageAttempt> */
class MessageAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['message_id' => Message::factory(), 'token' => (string) Str::uuid(), 'outcome' => 'sending', 'started_at' => now()];
    }
}
