<?php

namespace Database\Factories;

use App\Models;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Models\Order::factory(), 'payment_method_id' => Models\PaymentMethod::factory(), 'recorded_by' => fn (array $a) => Models\Order::findOrFail($a['order_id'])->buyer->user_id, 'amount_minor' => 510, 'currency' => 'PEN', 'operation_reference' => 'SIM-'.Str::uuid(), 'reference_lookup' => fn (array $a) => PaymentService::fingerprint('synthetic', $a['operation_reference']), 'occurred_at' => now(), 'recipient_snapshot' => ['recipient' => 'RECEPTOR SINTÉTICO', 'instructions' => 'No transferir dinero'], 'status' => 'pending', 'version' => 1];
    }
}
