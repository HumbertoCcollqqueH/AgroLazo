<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\TracePublication> */
class TracePublicationFactory extends Factory
{
    public function definition(): array
    {
        return ['lot_id' => Models\Lot::factory(), 'published_by' => Models\User::factory(), 'token' => Str::random(32), 'token_lookup' => fn (array $a) => hash('sha256', $a['token']), 'public_snapshot' => ['schema_version' => 1, 'kind' => 'commercial_lot', 'code' => 'L-SYNTHETIC', 'product' => 'Quinua sintética', 'variety' => 'Blanca', 'quality' => 'Primera', 'region' => 'Perú', 'unit' => 'kg', 'quantity' => '1.000', 'source_count' => 1, 'recorded_at' => now()->toIso8601String(), 'physical_receipt_confirmed' => false, 'synthetic' => true, 'milestones' => []], 'published_at' => now()];
    }
}
