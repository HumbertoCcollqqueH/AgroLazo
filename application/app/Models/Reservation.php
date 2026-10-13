<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['quote_id', 'buyer_id', 'status', 'expires_at'])]
class Reservation extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['status'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReservationItem::class)->orderBy('id');
    }
}
