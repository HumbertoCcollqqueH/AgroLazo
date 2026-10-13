<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\ProducerPayout> */
class ProducerPayoutFactory extends Factory
{
    public function definition(): array
    {
        return ['producer_settlement_id' => Models\ProducerSettlement::factory()->state(['status' => 'approved', 'approved_by' => Models\User::factory(), 'approved_at' => now()]), 'order_id' => fn (array $a) => Models\ProducerSettlement::findOrFail($a['producer_settlement_id'])->order_id, 'producer_id' => fn (array $a) => Models\ProducerSettlement::findOrFail($a['producer_settlement_id'])->producer_id, 'authorized_by' => Models\User::factory(), 'amount_minor' => 500, 'status' => 'authorized', 'version' => 1, 'reason' => 'Desembolso sintético autorizado'];
    }
}
