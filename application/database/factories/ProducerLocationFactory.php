<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Producer;
use App\Models\ProducerLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProducerLocation>
 */
class ProducerLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'producer_id' => Producer::factory(),
            'location_id' => Location::factory(),
            'community' => 'Comunidad de prueba',
            'address' => 'Referencia sintética para pruebas',
            'latitude' => '-15.840000',
            'longitude' => '-70.020000',
            'recorded_by' => User::factory(),
            'valid_from' => now()->subDay(),
            'valid_until' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay()]);
    }
}
