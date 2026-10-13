<?php

namespace Database\Factories;

use App\Models\Producer;
use App\Models\ProducerProfileChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProducerProfileChange>
 */
class ProducerProfileChangeFactory extends Factory
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
            'changes' => fn (array $attributes): array => ['full_name' => [
                'before' => 'Nombre anterior de prueba',
                'after' => Producer::query()->findOrFail($attributes['producer_id'])->full_name,
            ]],
            'reason' => 'Corrección sintética para pruebas.',
            'changed_at' => now(),
        ];
    }
}
