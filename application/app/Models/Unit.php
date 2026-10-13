<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'name', 'dimension', 'active'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    public const DIMENSIONS = [
        'mass' => 'Masa',
        'count' => 'Conteo',
        'package' => 'Envase',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['code', 'dimension'])) {
                throw ValidationException::withMessages([
                    'code' => 'Los campos estructurales no se modifican. Desactiva el registro y crea una nueva versión.',
                ]);
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }
}
