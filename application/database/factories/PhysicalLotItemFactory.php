<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\PhysicalLotItem> */
class PhysicalLotItemFactory extends Factory
{
    public function definition(): array
    {
        return ['collection_receipt_id' => Models\CollectionReceipt::factory(), 'physical_lot_id' => fn (array $a) => Models\PhysicalLot::factory()->create(['order_id' => Models\CollectionReceipt::findOrFail($a['collection_receipt_id'])->event->order_id, 'collection_point_id' => Models\CollectionReceipt::findOrFail($a['collection_receipt_id'])->event->collection_point_id])->id, 'quantity' => '1.000', 'retired_quantity' => '0.000'];
    }
}
