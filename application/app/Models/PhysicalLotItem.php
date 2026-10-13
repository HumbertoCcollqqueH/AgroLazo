<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['physical_lot_id', 'collection_receipt_id', 'quantity', 'retired_quantity'])]
class PhysicalLotItem extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['retired_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'retired_quantity' => 'decimal:3'];
    }

    public function physicalLot(): BelongsTo
    {
        return $this->belongsTo(PhysicalLot::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CollectionReceipt::class, 'collection_receipt_id');
    }
}
