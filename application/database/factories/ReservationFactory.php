<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\Reservation> */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return ['quote_id' => Models\Quote::factory(), 'buyer_id' => fn (array $a) => Models\Quote::findOrFail($a['quote_id'])->buyer_id, 'status' => 'active', 'expires_at' => now()->addMinutes(10)];
    }
}
