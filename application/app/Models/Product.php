<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['category_id', 'name', 'slug', 'base_unit_id', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['category_id', 'slug', 'base_unit_id'])) {
                throw ValidationException::withMessages([
                    'category_id' => 'Los campos estructurales no se modifican. Desactiva el registro y crea una nueva versión.',
                ]);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function varieties(): HasMany
    {
        return $this->hasMany(ProductVariety::class);
    }

    public function qualityGrades(): HasMany
    {
        return $this->hasMany(QualityGrade::class);
    }

    public function unitConversions(): HasMany
    {
        return $this->hasMany(UnitConversion::class);
    }

    public function isUsable(): bool
    {
        $this->loadMissing(['category', 'baseUnit']);

        return $this->active
            && $this->category?->active
            && $this->baseUnit?->active
            && in_array($this->baseUnit->dimension, ['mass', 'count'], true);
    }
}
