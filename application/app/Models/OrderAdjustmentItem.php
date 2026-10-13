<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_adjustment_id', 'order_allocation_id', 'quantity', 'retire', 'reduction_minor'])]
class OrderAdjustmentItem extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'retire' => 'boolean', 'reduction_minor' => 'integer'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(OrderAdjustment::class, 'order_adjustment_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OrderAllocation::class, 'order_allocation_id');
    }
}
