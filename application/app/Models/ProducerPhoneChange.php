<?php

namespace App\Models;

use Database\Factories\ProducerPhoneChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['producer_id', 'changed_by', 'previous_phone', 'new_phone', 'reason', 'changed_at'])]
#[Hidden(['previous_phone', 'new_phone', 'reason'])]
class ProducerPhoneChange extends Model
{
    /** @use HasFactory<ProducerPhoneChangeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['previous_phone' => 'encrypted', 'new_phone' => 'encrypted', 'reason' => 'encrypted', 'changed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de teléfonos no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de teléfonos no se elimina.'));
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
