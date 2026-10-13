<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\ProducerSettlementItem> */
class ProducerSettlementItemFactory extends Factory
{
    public function definition(): array
    {
        return ['collection_receipt_id' => Models\CollectionReceipt::factory(), 'order_allocation_id' => fn (array $a) => Models\CollectionReceipt::findOrFail($a['collection_receipt_id'])->order_allocation_id, 'producer_settlement_id' => fn (array $a) => Models\ProducerSettlement::factory()->create(['order_id' => Models\CollectionReceipt::findOrFail($a['collection_receipt_id'])->event->order_id, 'producer_id' => Models\OrderAllocation::findOrFail($a['order_allocation_id'])->portion->offer->producer_id])->id, 'agreement_id' => fn (array $a) => Models\OrderAllocation::findOrFail($a['order_allocation_id'])->agreement_id, 'quantity' => '1.000', 'gross_minor' => 500, 'agreed_cost_minor' => 0, 'net_minor' => 500, 'terms_snapshot' => fn (array $a) => Models\OrderAllocation::findOrFail($a['order_allocation_id'])->terms_snapshot];
    }
}
