<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'order_allocation_id', 'collection_receipt_id', 'shipment_item_id', 'kind', 'quantity', 'status', 'resolution', 'resolved_by', 'resolved_at'])]
class LogisticsIncident extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'resolution', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'resolution' => 'encrypted', 'resolved_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OrderAllocation::class, 'order_allocation_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CollectionReceipt::class, 'collection_receipt_id');
    }

    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
    }
}
