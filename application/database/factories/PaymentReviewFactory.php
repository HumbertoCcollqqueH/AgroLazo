<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\PaymentReview> */
class PaymentReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => Models\Payment::factory(), 'order_id' => fn (array $a) => Models\Payment::findOrFail($a['payment_id'])->order_id, 'actor_id' => Models\User::factory(), 'action' => 'synthetic_review', 'notes' => 'Comprobación sintética documentada', 'details' => ['synthetic' => true], 'occurred_at' => now()];
    }
}
