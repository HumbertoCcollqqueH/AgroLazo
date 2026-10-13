<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfferPriceAgreement> */
class OfferPriceAgreementFactory extends Factory
{
    public function definition(): array
    {
        return ['offer_id' => Offer::factory(), 'offer_version' => 1, 'version' => 1, 'gross_unit_price' => '5.000000', 'producer_unit_cost' => '0.500000', 'net_unit_price' => '4.500000', 'currency' => 'PEN', 'terms' => 'Condiciones sintéticas de prueba', 'valid_until' => now()->addDays(5), 'status' => 'proposed', 'proposed_by' => User::factory()];
    }
}
