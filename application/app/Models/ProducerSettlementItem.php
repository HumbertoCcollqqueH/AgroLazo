<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['producer_settlement_id', 'order_allocation_id', 'collection_receipt_id', 'agreement_id', 'quantity', 'gross_minor', 'agreed_cost_minor', 'net_minor', 'terms_snapshot'])]
class ProducerSettlementItem extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'gross_minor' => 'integer', 'agreed_cost_minor' => 'integer', 'net_minor' => 'integer', 'terms_snapshot' => 'encrypted'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ProducerSettlement::class, 'producer_settlement_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OrderAllocation::class, 'order_allocation_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CollectionReceipt::class, 'collection_receipt_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(OfferPriceAgreement::class, 'agreement_id');
    }
}
