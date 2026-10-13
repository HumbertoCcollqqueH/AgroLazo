<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'order_version', 'proposed_by', 'accepted_by', 'reason', 'status', 'reduction_minor', 'accepted_at'])]
class OrderAdjustment extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'accepted_by', 'accepted_at'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'order_version' => 'integer', 'reduction_minor' => 'integer', 'accepted_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderAdjustmentItem::class)->orderBy('id');
    }
}
