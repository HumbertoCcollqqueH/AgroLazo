<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shipment_id', 'physical_lot_item_id', 'quantity', 'delivered_quantity', 'returned_quantity', 'lost_quantity'])]
class ShipmentItem extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['delivered_quantity', 'returned_quantity', 'lost_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'delivered_quantity' => 'decimal:3', 'returned_quantity' => 'decimal:3', 'lost_quantity' => 'decimal:3'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function physicalItem(): BelongsTo
    {
        return $this->belongsTo(PhysicalLotItem::class, 'physical_lot_item_id');
    }
}
