<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UnitConversion> */
class UnitConversionFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'from_unit_id' => Unit::factory()->state(['dimension' => 'package']), 'to_unit_id' => fn (array $attributes): int => Product::query()->findOrFail($attributes['product_id'])->base_unit_id, 'factor' => '50.000000000', 'context' => 'saco-prueba', 'source' => 'Pesaje sintético de prueba', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null, 'verified_by' => User::factory(), 'verified_at' => now()->subDay(), 'active' => true];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
