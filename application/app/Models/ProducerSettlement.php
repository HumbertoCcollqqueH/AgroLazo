<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'producer_id', 'version', 'snapshot_hash', 'quantity', 'gross_minor', 'agreed_cost_minor', 'net_minor', 'currency', 'status', 'calculated_by', 'approved_by', 'approved_at'])]
class ProducerSettlement extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'quantity' => 'decimal:3', 'gross_minor' => 'integer', 'agreed_cost_minor' => 'integer', 'net_minor' => 'integer', 'approved_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProducerSettlementItem::class)->orderBy('id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ProducerPayout::class)->orderBy('id');
    }
}
