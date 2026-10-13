<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\OrderAllocation> */
class OrderAllocationFactory extends Factory
{
    public function definition(): array
    {
        return ['order_item_id' => Models\OrderItem::factory(), 'quote_item_id' => fn (array $a) => Models\QuoteItem::factory()->create(['quote_id' => Models\OrderItem::findOrFail($a['order_item_id'])->order->quote_id])->id, 'lot_offer_id' => fn (array $a) => Models\QuoteItem::findOrFail($a['quote_item_id'])->lot_offer_id, 'agreement_id' => fn (array $a) => Models\QuoteItem::findOrFail($a['quote_item_id'])->agreement_id, 'quantity' => '1.000', 'released_quantity' => '0.000', 'gross_unit_price' => '5.000000', 'producer_unit_cost' => '0.000000', 'terms_snapshot' => fn (array $a) => Models\QuoteItem::findOrFail($a['quote_item_id'])->terms_snapshot];
    }
}
