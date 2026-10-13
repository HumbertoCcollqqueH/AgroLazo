<?php

namespace Database\Factories;

use App\Models\Producer;
use App\Models\ProducerPhoneChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProducerPhoneChange>
 */
class ProducerPhoneChangeFactory extends Factory
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
            'changed_by' => User::factory(),
            'previous_phone' => '+519'.fake()->unique()->numerify('########'),
            'new_phone' => fn (array $attributes): string => Producer::query()->findOrFail($attributes['producer_id'])->phone,
            'reason' => 'Cambio asistido sintético para pruebas.',
            'changed_at' => now(),
        ];
    }
}
