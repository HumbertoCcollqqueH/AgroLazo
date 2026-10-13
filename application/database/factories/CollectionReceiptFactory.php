<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\CollectionReceipt> */
class CollectionReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['collection_event_id' => Models\CollectionEvent::factory(), 'order_allocation_id' => fn (array $a) => Models\CollectionEventItem::factory()->create(['collection_event_id' => $a['collection_event_id']])->order_allocation_id, 'recorded_by' => Models\User::factory(), 'expected_quantity' => '1.000', 'received_quantity' => '1.000', 'accepted_quantity' => '1.000', 'rejected_quantity' => '0.000', 'quality_status' => 'accepted', 'arrived_at' => now(), 'notes' => 'Recepción sintética aceptada'];
    }
}
