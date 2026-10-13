<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\MarketPrice> */
class MarketPriceFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Models\Product::factory(), 'variety_id' => fn (array $a) => Models\ProductVariety::factory()->create(['product_id' => $a['product_id']])->id, 'quality_grade_id' => fn (array $a) => Models\QualityGrade::factory()->create(['product_id' => $a['product_id']])->id, 'location_id' => Models\Location::factory(), 'unit_id' => fn (array $a) => Models\Product::findOrFail($a['product_id'])->base_unit_id, 'unit_price' => '5.000000', 'currency' => 'PEN', 'market' => 'Mercado sintético', 'observed_on' => now('America/Lima')->toDateString(), 'source' => 'Referencia sintética documentada', 'provider' => 'manual', 'synthetic' => true, 'recorded_by' => Models\User::factory()];
    }
}
