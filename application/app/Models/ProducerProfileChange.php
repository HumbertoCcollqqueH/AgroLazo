<?php

namespace App\Models;

use Database\Factories\ProducerProfileChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['producer_id', 'changed_by', 'changes', 'reason', 'changed_at'])]
#[Hidden(['changes', 'reason'])]
class ProducerProfileChange extends Model
{
    /** @use HasFactory<ProducerProfileChangeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'encrypted:array', 'reason' => 'encrypted', 'changed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de correcciones no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de correcciones no se elimina.'));
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
