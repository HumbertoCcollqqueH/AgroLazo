<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['collection_event_id', 'order_allocation_id', 'recorded_by', 'expected_quantity', 'received_quantity', 'accepted_quantity', 'rejected_quantity', 'quality_status', 'arrived_at', 'notes'])]
class CollectionReceipt extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'accepted_quantity' => 'decimal:3', 'rejected_quantity' => 'decimal:3', 'arrived_at' => 'immutable_datetime', 'notes' => 'encrypted'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CollectionEvent::class, 'collection_event_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OrderAllocation::class, 'order_allocation_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReceiptPhoto::class)->orderBy('id');
    }
}
