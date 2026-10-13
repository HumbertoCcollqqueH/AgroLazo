<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'base_unit_id', 'allocation_rule', 'fee_unit_price', 'logistics_minor', 'discount_threshold', 'discount_minor', 'tax_bps', 'tax_basis', 'disclosure', 'delivery_mode', 'synthetic', 'active', 'recorded_by'])]
class PricingPolicy extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['active'];

    protected function casts(): array
    {
        return ['fee_unit_price' => 'decimal:6', 'discount_threshold' => 'decimal:3', 'synthetic' => 'boolean', 'active' => 'boolean', 'logistics_minor' => 'integer', 'discount_minor' => 'integer', 'tax_bps' => 'integer'];
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }
}
