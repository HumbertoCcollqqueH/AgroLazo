<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\PaymentRefund> */
class PaymentRefundFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => Models\Payment::factory()->state(['status' => 'confirmed', 'confirmed_at' => now(), 'reviewed_by' => Models\User::factory()]), 'authorized_by' => Models\User::factory(), 'amount_minor' => 100, 'status' => 'authorized', 'version' => 1, 'reason' => 'Reembolso sintético parcial autorizado'];
    }
}
