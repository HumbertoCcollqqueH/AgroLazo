<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Producer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Offer> */
class OfferFactory extends Factory
{
    public function published(string $grossPrice = '5.000000', string $unitCost = '0.000000'): static
    {
        return $this->state(fn (): array => [
            'status' => 'published',
            'variety_id' => fn (array $a) => ProductVariety::factory()->create(['product_id' => $a['product_id']])->id,
            'quality_grade_id' => fn (array $a) => QualityGrade::factory()->create(['product_id' => $a['product_id']])->id,
        ])->afterCreating(function (Offer $offer) use ($grossPrice, $unitCost): void {
            OfferPriceAgreement::factory()->create([
                'offer_id' => $offer->id, 'offer_version' => $offer->version, 'status' => 'accepted',
                'gross_unit_price' => $grossPrice, 'producer_unit_cost' => $unitCost, 'net_unit_price' => bcsub($grossPrice, $unitCost, 6),
                'accepted_at' => now(), 'recorded_by' => $offer->registered_by,
                'valid_until' => CarbonImmutable::parse($offer->available_until->toDateString(), 'America/Lima')->endOfDay()->utc(),
            ]);
        });
    }

    public function definition(): array
    {
        return ['source_key' => (string) Str::uuid(), 'source_hash' => hash('sha256', (string) Str::uuid()), 'channel' => 'assisted_web', 'registered_by' => User::factory(), 'producer_id' => Producer::factory()->verified(), 'product_id' => Product::factory(), 'location_id' => Location::factory(), 'quantity' => '80.000', 'normalized_quantity' => '80.000', 'conversion_factor' => '1.000000000', 'conversion_source' => 'Misma unidad', 'conversion_date' => now('America/Lima')->toDateString(), 'base_unit_id' => fn (array $a) => Product::findOrFail($a['product_id'])->base_unit_id, 'unit_id' => fn (array $a) => $a['base_unit_id'], 'context' => '', 'available_from' => now('America/Lima')->toDateString(), 'available_until' => now('America/Lima')->addWeek()->toDateString(), 'status' => 'pending_verification', 'version' => 1];
    }
}
