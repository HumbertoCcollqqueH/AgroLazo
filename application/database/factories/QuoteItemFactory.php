<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\QuoteItem> */
class QuoteItemFactory extends Factory
{
    public function definition(): array
    {
        return ['quote_id' => Models\Quote::factory(), 'lot_offer_id' => fn (array $a) => Models\Quote::findOrFail($a['quote_id'])->lot->portions->first()->id, 'agreement_id' => fn (array $a) => Models\LotOffer::findOrFail($a['lot_offer_id'])->agreement_id, 'offer_version' => fn (array $a) => Models\LotOffer::findOrFail($a['lot_offer_id'])->offer_version, 'quantity' => '1.000', 'gross_unit_price' => '5.000000', 'producer_unit_cost' => '0.000000', 'gross_minor' => 500, 'producer_cost_minor' => 0, 'producer_net_minor' => 500, 'terms_snapshot' => 'Acuerdo sintético sin costos al productor'];
    }
}
