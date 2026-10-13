<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\OrderAdjustmentItem> */
class OrderAdjustmentItemFactory extends Factory
{
    public function definition(): array
    {
        return ['order_allocation_id' => Models\OrderAllocation::factory(), 'order_adjustment_id' => fn (array $a) => Models\OrderAdjustment::factory()->create(['order_id' => Models\OrderAllocation::findOrFail($a['order_allocation_id'])->orderItem->order_id])->id, 'quantity' => '0.500', 'retire' => true, 'reduction_minor' => 255];
    }
}
