<?php

namespace Database\Factories;

use App\Models;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Models\ReservationItem> */
class ReservationItemFactory extends Factory
{
    public function definition(): array
    {
        return ['reservation_id' => Models\Reservation::factory(), 'quote_item_id' => fn (array $a) => Models\QuoteItem::factory()->create(['quote_id' => Models\Reservation::findOrFail($a['reservation_id'])->quote_id])->id, 'lot_offer_id' => fn (array $a) => Models\QuoteItem::findOrFail($a['quote_item_id'])->lot_offer_id, 'quantity' => '1.000'];
    }
}
