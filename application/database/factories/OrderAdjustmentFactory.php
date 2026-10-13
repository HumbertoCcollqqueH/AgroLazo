<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\OrderAdjustment> */
class OrderAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Models\Order::factory(), 'order_version' => fn (array $a) => Models\Order::findOrFail($a['order_id'])->version, 'proposed_by' => Models\User::factory(), 'reason' => 'Ajuste sintético pendiente de aceptación', 'status' => 'proposed', 'reduction_minor' => 255];
    }
}
