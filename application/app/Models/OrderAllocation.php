<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_item_id', 'lot_offer_id', 'quote_item_id', 'agreement_id', 'quantity', 'released_quantity', 'gross_unit_price', 'producer_unit_cost', 'terms_snapshot'])]
class OrderAllocation extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['released_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'released_quantity' => 'decimal:3', 'gross_unit_price' => 'decimal:6', 'producer_unit_cost' => 'decimal:6', 'terms_snapshot' => 'encrypted'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function portion(): BelongsTo
    {
        return $this->belongsTo(LotOffer::class, 'lot_offer_id');
    }

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(OfferPriceAgreement::class, 'agreement_id');
    }
}
