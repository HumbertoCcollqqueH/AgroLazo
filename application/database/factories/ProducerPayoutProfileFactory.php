<?php

namespace Database\Factories;

use App\Models\Producer;
use App\Models\ProducerPayoutProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProducerPayoutProfile>
 */
class ProducerPayoutProfileFactory extends Factory
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
            'recorded_by' => User::factory(),
            'version' => 1,
            'submission_key' => fake()->uuid(),
            'request_hash' => hash('sha256', fake()->uuid()),
            'holder_name' => fake()->name(),
            'yape_phone' => '9'.fake()->numerify('########'),
            'created_at' => now(),
        ];
    }
}
