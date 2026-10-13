<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\PhysicalLot> */
class PhysicalLotFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'PH-'.strtoupper(Str::random(10)), 'order_id' => Models\Order::factory(), 'lot_id' => fn (array $a) => Models\Order::findOrFail($a['order_id'])->quote->lot_id, 'collection_point_id' => fn (array $a) => Models\CollectionPoint::factory()->create(['base_unit_id' => Models\Order::findOrFail($a['order_id'])->quote->base_unit_id])->id, 'created_by' => Models\User::factory(), 'status' => 'ready'];
    }
}
