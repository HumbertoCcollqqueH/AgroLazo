<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Models\Quote> */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'Q-'.strtoupper(Str::random(10)), 'buyer_id' => Models\Buyer::factory()->approved(), 'lot_id' => fn () => Models\LotOffer::factory()->create()->lot_id, 'lot_version' => fn (array $a) => Models\Lot::findOrFail($a['lot_id'])->version, 'base_unit_id' => fn (array $a) => Models\Lot::findOrFail($a['lot_id'])->base_unit_id, 'pricing_policy_id' => fn (array $a) => Models\PricingPolicy::factory()->create(['base_unit_id' => $a['base_unit_id']])->id, 'quantity' => '1.000', 'currency' => 'PEN', 'subtotal_minor' => 500, 'fee_minor' => 10, 'logistics_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 510, 'weighted_unit_price' => '5.000000', 'pricing_version' => 'exact-lines-v1', 'pricing_snapshot' => ['tax_bps' => 0, 'allocation_rule' => 'fifo', 'disclosure' => 'Cotización sintética'], 'delivery_details' => 'Retiro sintético coordinado', 'delivery_mode' => 'collection', 'payment_method' => 'coordinated', 'synthetic' => true, 'status' => 'issued', 'issued_at' => now(), 'expires_at' => now()->addMinutes(20)];
    }
}
