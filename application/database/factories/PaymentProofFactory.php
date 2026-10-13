<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\PaymentProof> */
class PaymentProofFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => Models\Payment::factory(), 'recorded_by' => fn (array $a) => Models\Payment::findOrFail($a['payment_id'])->recorded_by, 'path' => 'payment-proofs/'.Str::uuid().'.pdf', 'mime' => 'application/pdf', 'size' => 128, 'sha256' => hash('sha256', (string) Str::uuid())];
    }
}
