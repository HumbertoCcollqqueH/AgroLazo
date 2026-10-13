<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Lot> */
class LotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'L-'.Str::upper(Str::random(8)), 'source_key' => (string) Str::uuid(),
            'source_hash' => hash('sha256', (string) Str::uuid()), 'created_by' => User::factory(),
            'product_id' => Product::factory(), 'location_id' => Location::factory(),
            'variety_id' => fn (array $a) => ProductVariety::factory()->create(['product_id' => $a['product_id']])->id,
            'quality_grade_id' => fn (array $a) => QualityGrade::factory()->create(['product_id' => $a['product_id']])->id,
            'base_unit_id' => fn (array $a) => Product::findOrFail($a['product_id'])->base_unit_id,
            'available_from' => now('America/Lima')->toDateString(), 'available_until' => now('America/Lima')->addDays(3)->toDateString(),
            'status' => 'draft', 'version' => 1, 'allow_price_variation' => false, 'compatibility_rule' => 'exact-locality-v1',
        ];
    }

    public function withOffer(Offer $offer, string $quantity = '40.000'): static
    {
        return $this->state(fn (): array => [
            ...$offer->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id', 'available_from', 'available_until']),
        ])->afterCreating(function (Lot $lot) use ($offer, $quantity): void {
            LotOffer::factory()->create(['lot_id' => $lot->id, 'offer_id' => $offer->id, 'agreement_id' => $offer->activeAgreement()->id, 'offer_version' => $offer->version, 'quantity' => $quantity]);
        });
    }
}
