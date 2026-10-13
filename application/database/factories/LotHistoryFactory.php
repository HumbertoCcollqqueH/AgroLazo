<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\LotHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LotHistory> */
class LotHistoryFactory extends Factory
{
    public function definition(): array
    {
        return ['lot_id' => Lot::factory(), 'actor_id' => User::factory(), 'action' => 'created', 'lot_version' => 1, 'changes' => ['status' => ['before' => null, 'after' => 'draft']], 'reason' => 'Composición sintética de prueba', 'occurred_at' => now()];
    }
}
