<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\QualityGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QualityGrade> */
class QualityGradeFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'name' => 'Calidad de prueba', 'code' => fake()->unique()->slug(2), 'description' => null, 'criteria' => 'Criterios de prueba', 'active' => true];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
