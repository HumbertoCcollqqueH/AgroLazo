<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\ShipmentEvent> */
class ShipmentEventFactory extends Factory
{
    public function definition(): array
    {
        return ['shipment_id' => Models\Shipment::factory(), 'actor_id' => Models\User::factory(), 'action' => 'scheduled', 'notes' => 'Movimiento sintético registrado', 'occurred_at' => now()];
    }
}
