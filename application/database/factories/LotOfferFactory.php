<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LotOffer> */
class LotOfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory()->published(),
            'lot_id' => fn (array $a) => Lot::factory()->create(Offer::findOrFail($a['offer_id'])->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id', 'available_from', 'available_until']))->id,
            'agreement_id' => fn (array $a) => Offer::findOrFail($a['offer_id'])->activeAgreement()->id,
            'offer_version' => fn (array $a) => Offer::findOrFail($a['offer_id'])->version,
            'quantity' => '40.000', 'released_quantity' => '0.000', 'retired_quantity' => '0.000',
        ];
    }
}
