<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\CollectionEventItem> */
class CollectionEventItemFactory extends Factory
{
    public function definition(): array
    {
        return ['collection_event_id' => Models\CollectionEvent::factory(), 'order_allocation_id' => fn (array $a) => Models\OrderAllocation::factory()->create(['order_item_id' => Models\OrderItem::factory()->create(['order_id' => Models\CollectionEvent::findOrFail($a['collection_event_id'])->order_id])->id])->id, 'expected_quantity' => '1.000'];
    }
}
