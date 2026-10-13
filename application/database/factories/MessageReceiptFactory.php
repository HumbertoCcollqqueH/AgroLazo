<?php

namespace Database\Factories;

use App\Models\MessageReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MessageReceipt> */
class MessageReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['provider' => 'fake', 'account' => 'local-simulator', 'external_id' => 'fake:'.fake()->unique()->randomNumber(), 'status' => 'delivered', 'event_key' => hash('sha256', (string) Str::uuid()), 'occurred_at' => now()];
    }
}
