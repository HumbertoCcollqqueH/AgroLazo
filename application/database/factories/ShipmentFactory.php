<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\Shipment> */
class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'D-'.strtoupper(Str::random(10)), 'order_id' => Models\Order::factory(), 'scheduled_by' => Models\User::factory(), 'status' => 'scheduled', 'version' => 1, 'cost_minor' => 0, 'scheduled_at' => now()->addHour(), 'notes' => 'Despacho sintético preparado'];
    }
}
