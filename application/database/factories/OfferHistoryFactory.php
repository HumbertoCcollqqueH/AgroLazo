<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfferHistory> */
class OfferHistoryFactory extends Factory
{
    public function definition(): array
    {
        return ['offer_id' => Offer::factory(), 'actor_id' => User::factory(), 'action' => 'created', 'offer_version' => 1, 'changes' => ['status' => ['before' => null, 'after' => 'pending_verification']], 'reason' => 'Evento sintético de prueba', 'occurred_at' => now()];
    }
}
