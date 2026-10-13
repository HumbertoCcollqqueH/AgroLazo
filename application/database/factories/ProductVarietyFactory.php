<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariety;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariety> */
class ProductVarietyFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'name' => fake()->words(2, true), 'code' => fake()->unique()->slug(2), 'active' => true];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
