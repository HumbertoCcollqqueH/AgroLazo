<?php

namespace App\Models;

use Database\Factories\UnitConversionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['product_id', 'from_unit_id', 'to_unit_id', 'factor', 'context', 'source', 'valid_from', 'valid_until', 'verified_by', 'verified_at', 'active'])]
class UnitConversion extends Model
{
    /** @use HasFactory<UnitConversionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'factor' => 'decimal:9', 'valid_from' => 'immutable_date', 'valid_until' => 'immutable_date', 'verified_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['product_id', 'from_unit_id', 'to_unit_id', 'factor', 'context', 'source', 'valid_from', 'valid_until', 'verified_by', 'verified_at'])) {
                throw ValidationException::withMessages([
                    'product_id' => 'Los campos estructurales no se modifican. Desactiva el registro y crea una nueva versión.',
                ]);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
