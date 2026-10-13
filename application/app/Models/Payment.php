<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'payment_method_id', 'recorded_by', 'amount_minor', 'currency', 'operation_reference', 'reference_lookup', 'occurred_at', 'recipient_snapshot', 'status', 'version', 'reviewed_by', 'confirmed_at'])]
class Payment extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'version', 'reviewed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'operation_reference' => 'encrypted', 'occurred_at' => 'immutable_datetime', 'recipient_snapshot' => 'encrypted:array', 'version' => 'integer', 'confirmed_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class)->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PaymentReview::class)->orderBy('id');
    }
}
