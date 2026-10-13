<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\LogisticsIncident> */
class LogisticsIncidentFactory extends Factory
{
    public function definition(): array
    {
        return ['order_allocation_id' => Models\OrderAllocation::factory(), 'order_id' => fn (array $a) => Models\OrderAllocation::findOrFail($a['order_allocation_id'])->orderItem->order_id, 'kind' => 'shortage', 'quantity' => '0.500', 'status' => 'open'];
    }
}
