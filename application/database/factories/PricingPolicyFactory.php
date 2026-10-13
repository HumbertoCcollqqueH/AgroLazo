<?php

namespace Database\Factories;

use App\Models\PricingPolicy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingPolicy> */
class PricingPolicyFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Regla sintética '.fake()->uuid(), 'base_unit_id' => Unit::factory(), 'allocation_rule' => 'fifo', 'fee_unit_price' => '0.100000', 'logistics_minor' => 0, 'discount_threshold' => '100.000', 'discount_minor' => 100, 'tax_bps' => 0, 'tax_basis' => 'Supuesto sintético sin tributos, no comercial.', 'disclosure' => 'Servicio al comprador S/ 0.10 por unidad; descuento de plataforma desde 100 unidades. Retiro sin transporte cobrado.', 'delivery_mode' => 'collection', 'synthetic' => true, 'active' => true, 'recorded_by' => User::factory()];
    }
}
