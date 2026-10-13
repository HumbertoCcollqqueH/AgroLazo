<?php

namespace Database\Factories;

use App\Models\Producer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producer>
 */
class ProducerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'dni' => fake()->unique()->numerify('########'),
            'phone' => '+519'.fake()->unique()->numerify('########'),
            'type' => 'individual',
            'status' => 'pending_verification',
            'registered_by' => User::factory(),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'verified']);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'rejected']);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'suspended']);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'archived']);
    }
}
