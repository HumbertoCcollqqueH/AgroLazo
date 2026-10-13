<?php

namespace Database\Factories;

use App\Models\Producer;
use App\Models\ProducerVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProducerVerification>
 */
class ProducerVerificationFactory extends Factory
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
            'verified_by' => User::factory(),
            'result' => 'verified',
            'method' => 'manual_document',
            'notes' => 'Revisión sintética de documento para pruebas.',
            'verified_at' => now(),
        ];
    }
}
