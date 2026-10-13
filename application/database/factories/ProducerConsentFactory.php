<?php

namespace Database\Factories;

use App\Models\Producer;
use App\Models\ProducerConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProducerConsent>
 */
class ProducerConsentFactory extends Factory
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
            'purpose' => 'profile_contact',
            'notice_version' => 'producer-notice-v1',
            'channel' => 'assisted_web',
            'decision' => 'granted',
            'recorded_at' => now(),
        ];
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes): array => ['decision' => 'declined']);
    }
}
