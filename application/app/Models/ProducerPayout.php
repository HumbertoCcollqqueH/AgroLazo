<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['producer_settlement_id', 'order_id', 'producer_id', 'authorized_by', 'amount_minor', 'status', 'version', 'reason', 'operation_reference', 'reference_lookup', 'confirmed_by', 'confirmed_at', 'recipient_snapshot'])]
#[Hidden(['recipient_snapshot'])]
class ProducerPayout extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status', 'version', 'operation_reference', 'reference_lookup', 'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'version' => 'integer', 'reason' => 'encrypted', 'operation_reference' => 'encrypted', 'confirmed_at' => 'immutable_datetime', 'recipient_snapshot' => 'encrypted:array'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ProducerSettlement::class, 'producer_settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }
}
