<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'test_'.strtolower(Str::random(8)), 'name' => 'Medio sintético', 'recipient' => 'RECEPTOR SINTÉTICO · NO TRANSFERIR', 'instructions' => 'Instrucciones sintéticas sin transferencia de dinero', 'active' => true, 'live_ready' => false, 'version' => 1];
    }
}
