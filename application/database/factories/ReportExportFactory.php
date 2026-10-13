<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ReportExportFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->withRole('admin'), 'key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'fixture'), 'report' => 'sales', 'format' => 'csv', 'filters' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'data' => 'synthetic', 'product_id' => null, 'location_id' => null], 'status' => 'pending', 'expires_at' => now()->addDay()];
    }
}
