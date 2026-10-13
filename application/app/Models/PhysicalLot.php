<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'order_id', 'lot_id', 'collection_point_id', 'created_by', 'status'])]
class PhysicalLot extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status'];

    protected function casts(): array
    {
        return [];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(CollectionPoint::class, 'collection_point_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PhysicalLotItem::class)->orderBy('id');
    }
}
