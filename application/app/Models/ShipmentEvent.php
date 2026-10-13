<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shipment_id', 'shipment_item_id', 'actor_id', 'action', 'quantity', 'notes', 'occurred_at'])]
class ShipmentEvent extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'notes' => 'encrypted', 'occurred_at' => 'immutable_datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class, 'shipment_item_id');
    }
}
