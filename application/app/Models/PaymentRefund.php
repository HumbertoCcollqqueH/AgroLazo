<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_id', 'authorized_by', 'amount_minor', 'status', 'version', 'reason', 'operation_reference', 'reference_lookup', 'confirmed_by', 'confirmed_at'])]
class PaymentRefund extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'version', 'operation_reference', 'reference_lookup', 'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'version' => 'integer', 'reason' => 'encrypted', 'operation_reference' => 'encrypted', 'confirmed_at' => 'immutable_datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
