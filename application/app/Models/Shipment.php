<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'order_id', 'scheduled_by', 'status', 'version', 'carrier', 'vehicle', 'cost_minor', 'scheduled_at', 'dispatched_at', 'completed_at', 'notes'])]
class Shipment extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'version', 'dispatched_at', 'completed_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'carrier' => 'encrypted', 'vehicle' => 'encrypted', 'cost_minor' => 'integer', 'scheduled_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'notes' => 'encrypted'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('id');
    }
}
