<?php

namespace App\Models;

use Database\Factories\LotHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['lot_id', 'actor_id', 'action', 'lot_version', 'changes', 'reason', 'occurred_at'])]
#[Hidden(['changes', 'reason'])]
class LotHistory extends Model
{
    /** @use HasFactory<LotHistoryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'encrypted:array', 'reason' => 'encrypted', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial del lote no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial del lote no se elimina.'));
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
