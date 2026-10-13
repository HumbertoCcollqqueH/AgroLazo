<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'type', 'parent_id', 'code', 'active'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    public const TYPES = [
        'country' => 'País',
        'department' => 'Departamento',
        'province' => 'Provincia',
        'district' => 'Distrito',
        'community' => 'Comunidad',
    ];

    public const PARENT_TYPES = [
        'country' => null,
        'department' => 'country',
        'province' => 'department',
        'district' => 'province',
        'community' => 'district',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['type', 'parent_id', 'code'])) {
                throw ValidationException::withMessages([
                    'type' => 'Los campos estructurales no se modifican. Desactiva el registro y crea una nueva versión.',
                ]);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function isUsable(): bool
    {
        $location = $this;
        $visited = [];

        while ($location !== null) {
            if (! $location->active || isset($visited[$location->getKey()])) {
                return false;
            }

            $visited[$location->getKey()] = true;

            if ($location->parent_id === null) {
                return $location->type === 'country';
            }

            $parent = $location->parent()->first();

            if ($parent === null || (self::PARENT_TYPES[$location->type] ?? null) !== $parent->type) {
                return false;
            }

            $location = $parent;
        }

        return false;
    }
}
