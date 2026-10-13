<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\ShipmentItem> */
class ShipmentItemFactory extends Factory
{
    public function definition(): array
    {
        return ['physical_lot_item_id' => Models\PhysicalLotItem::factory(), 'shipment_id' => fn (array $a) => Models\Shipment::factory()->create(['order_id' => Models\PhysicalLotItem::findOrFail($a['physical_lot_item_id'])->physicalLot->order_id])->id, 'quantity' => '1.000', 'delivered_quantity' => '0.000', 'returned_quantity' => '0.000', 'lost_quantity' => '0.000'];
    }
}
