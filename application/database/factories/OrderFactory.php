<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'O-'.strtoupper(Str::random(10)), 'quote_id' => Models\Quote::factory()->state(['status' => 'converted']), 'buyer_id' => fn (array $a) => Models\Quote::findOrFail($a['quote_id'])->buyer_id, 'status' => 'confirmed', 'version' => 1, 'currency' => 'PEN', 'original_total_minor' => fn (array $a) => Models\Quote::findOrFail($a['quote_id'])->total_minor, 'total_minor' => fn (array $a) => $a['original_total_minor'], 'payment_method' => 'coordinated', 'delivery_details' => 'Retiro sintético de fábrica', 'synthetic' => true, 'confirmed_at' => now()];
    }
}
