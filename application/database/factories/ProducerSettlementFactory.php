<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\ProducerSettlement> */
class ProducerSettlementFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Models\Order::factory(), 'producer_id' => Models\Producer::factory()->verified(), 'version' => 1, 'snapshot_hash' => hash('sha256', (string) Str::uuid()), 'quantity' => '1.000', 'gross_minor' => 500, 'agreed_cost_minor' => 0, 'net_minor' => 500, 'currency' => 'PEN', 'status' => 'draft', 'calculated_by' => Models\User::factory()];
    }
}
