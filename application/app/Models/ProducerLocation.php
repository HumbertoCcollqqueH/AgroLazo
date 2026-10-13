<?php

namespace App\Models;

use Database\Factories\ProducerLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['producer_id', 'location_id', 'community', 'address', 'latitude', 'longitude', 'recorded_by', 'valid_from', 'valid_until'])]
#[Hidden(['community', 'address', 'latitude', 'longitude'])]
class ProducerLocation extends Model
{
    /** @use HasFactory<ProducerLocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['community' => 'encrypted', 'address' => 'encrypted', 'latitude' => 'decimal:6', 'longitude' => 'decimal:6', 'valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            $changed = array_values(array_diff(array_keys($record->getDirty()), ['updated_at']));
            if ($changed !== ['valid_until'] || $record->getOriginal('valid_until') !== null || $record->valid_until === null || $record->valid_until->lt($record->valid_from)) {
                throw new LogicException('La ubicación histórica solo admite cerrar su vigencia una vez.');
            }
        });
        static::deleting(fn () => throw new LogicException('El historial de ubicaciones no se elimina.'));
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
