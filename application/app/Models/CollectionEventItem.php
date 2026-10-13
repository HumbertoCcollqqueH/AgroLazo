<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['collection_event_id', 'order_allocation_id', 'expected_quantity', 'pickup_address', 'pickup_at'])]
class CollectionEventItem extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'pickup_address' => 'encrypted', 'pickup_at' => 'immutable_datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CollectionEvent::class, 'collection_event_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OrderAllocation::class, 'order_allocation_id');
    }
}
