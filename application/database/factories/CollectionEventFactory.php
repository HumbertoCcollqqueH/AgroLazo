<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\CollectionEvent> */
class CollectionEventFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Models\Order::factory(), 'collection_point_id' => fn (array $a) => Models\CollectionPoint::factory()->create(['base_unit_id' => Models\Order::findOrFail($a['order_id'])->quote->base_unit_id])->id, 'scheduled_by' => Models\User::factory(), 'starts_at' => now()->addDay()->startOfHour(), 'ends_at' => now()->addDay()->startOfHour()->addHour(), 'quantity' => '1.000', 'base_unit_id' => fn (array $a) => Models\Order::findOrFail($a['order_id'])->quote->base_unit_id, 'status' => 'scheduled', 'version' => 1, 'reason' => 'Acopio sintético para pruebas', 'point_snapshot' => fn (array $a) => Models\CollectionPoint::findOrFail($a['collection_point_id'])->only(['name', 'address'])];
    }
}
