<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'collection_point_id', 'scheduled_by', 'starts_at', 'ends_at', 'quantity', 'base_unit_id', 'status', 'version', 'reason', 'point_snapshot'])]
class CollectionEvent extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'quantity' => 'decimal:3', 'version' => 'integer', 'reason' => 'encrypted', 'point_snapshot' => 'encrypted:array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(CollectionPoint::class, 'collection_point_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CollectionEventItem::class)->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CollectionReceipt::class)->orderBy('id');
    }
}
