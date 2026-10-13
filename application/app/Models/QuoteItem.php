<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quote_id', 'lot_offer_id', 'agreement_id', 'offer_version', 'quantity', 'gross_unit_price', 'producer_unit_cost', 'gross_minor', 'producer_cost_minor', 'producer_net_minor', 'terms_snapshot'])]
class QuoteItem extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'gross_unit_price' => 'decimal:6', 'producer_unit_cost' => 'decimal:6', 'gross_minor' => 'integer', 'producer_cost_minor' => 'integer', 'producer_net_minor' => 'integer', 'terms_snapshot' => 'encrypted', 'offer_version' => 'integer'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function portion(): BelongsTo
    {
        return $this->belongsTo(LotOffer::class, 'lot_offer_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(OfferPriceAgreement::class, 'agreement_id');
    }
}
