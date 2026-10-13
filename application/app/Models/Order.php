<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'buyer_id', 'quote_id', 'reservation_id', 'status', 'version', 'currency', 'original_total_minor', 'total_minor', 'payment_method', 'delivery_details', 'delivery_address', 'requested_delivery_on', 'synthetic', 'confirmed_at', 'deferred_payment_approved_by', 'deferred_payment_limit_minor', 'deferred_payment_due_at', 'deferred_payment_reason'])]
class Order extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['delivery_address', 'status', 'version', 'total_minor', 'deferred_payment_approved_by', 'deferred_payment_limit_minor', 'deferred_payment_due_at', 'deferred_payment_reason'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'original_total_minor' => 'integer', 'total_minor' => 'integer', 'delivery_details' => 'encrypted', 'delivery_address' => 'encrypted:array', 'requested_delivery_on' => 'immutable_date', 'synthetic' => 'boolean', 'confirmed_at' => 'immutable_datetime', 'deferred_payment_limit_minor' => 'integer', 'deferred_payment_due_at' => 'immutable_datetime', 'deferred_payment_reason' => 'encrypted'];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommerceEvent::class)->orderBy('id');
    }
}
