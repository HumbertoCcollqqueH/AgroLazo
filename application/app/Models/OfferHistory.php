<?php

namespace App\Models;

use Database\Factories\OfferHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['offer_id', 'actor_id', 'action', 'offer_version', 'changes', 'reason', 'occurred_at'])]
#[Hidden(['changes', 'reason'])]
class OfferHistory extends Model
{
    /** @use HasFactory<OfferHistoryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'encrypted:array', 'reason' => 'encrypted', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial no se elimina.'));
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
