<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\CommerceEvent> */
class CommerceEventFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Models\Order::factory(), 'lot_id' => fn (array $a) => Models\Order::findOrFail($a['order_id'])->quote->lot_id, 'actor_id' => Models\User::factory(), 'action' => 'synthetic_event', 'details' => ['synthetic' => true], 'occurred_at' => now()];
    }
}
