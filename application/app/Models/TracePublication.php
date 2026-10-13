<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lot_id', 'physical_lot_id', 'published_by', 'token', 'token_lookup', 'public_snapshot', 'published_at', 'revoked_by', 'revoked_at', 'revocation_reason'])]
class TracePublication extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['revoked_by', 'revoked_at', 'revocation_reason'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'public_snapshot' => 'array', 'published_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'revocation_reason' => 'encrypted'];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function physicalLot(): BelongsTo
    {
        return $this->belongsTo(PhysicalLot::class);
    }
}
