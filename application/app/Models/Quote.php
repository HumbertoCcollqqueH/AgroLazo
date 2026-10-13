<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'buyer_id', 'lot_id', 'lot_version', 'pricing_policy_id', 'quantity', 'base_unit_id', 'currency', 'subtotal_minor', 'fee_minor', 'logistics_minor', 'discount_minor', 'tax_minor', 'total_minor', 'weighted_unit_price', 'pricing_version', 'pricing_snapshot', 'reference_snapshot', 'delivery_details', 'delivery_address', 'delivery_mode', 'payment_method', 'synthetic', 'status', 'issued_at', 'expires_at'])]
class Quote extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'weighted_unit_price' => 'decimal:6', 'pricing_snapshot' => 'encrypted:array', 'reference_snapshot' => 'encrypted:array', 'delivery_details' => 'encrypted', 'delivery_address' => 'encrypted:array', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'synthetic' => 'boolean', 'lot_version' => 'integer', 'subtotal_minor' => 'integer', 'fee_minor' => 'integer', 'logistics_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function pricingPolicy(): BelongsTo
    {
        return $this->belongsTo(PricingPolicy::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('id');
    }
}
