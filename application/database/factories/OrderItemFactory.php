<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\OrderItem> */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Models\Order::factory(), 'lot_id' => fn (array $a) => Models\Order::findOrFail($a['order_id'])->quote->lot_id, 'quantity' => '1.000', 'base_unit_id' => fn (array $a) => Models\Lot::findOrFail($a['lot_id'])->base_unit_id, 'product_snapshot' => function (array $a): array {
            $lot = Models\Lot::findOrFail($a['lot_id']);

            return ['product' => $lot->product->name, 'variety' => $lot->variety->name, 'quality' => $lot->qualityGrade->name, 'location' => $lot->location->name, 'unit' => $lot->baseUnit->code];
        }];
    }
}
