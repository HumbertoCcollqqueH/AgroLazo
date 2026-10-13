<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\OperationKey> */
class OperationKeyFactory extends Factory
{
    public function definition(): array
    {
        return ['actor_id' => Models\User::factory(), 'operation' => 'synthetic-fixture', 'key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', '{}'), 'resource_type' => Models\Order::class, 'resource_id' => Models\Order::factory()];
    }
}
