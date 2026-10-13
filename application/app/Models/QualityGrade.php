<?php

namespace App\Models;

use Database\Factories\QualityGradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['product_id', 'name', 'code', 'description', 'criteria', 'active'])]
class QualityGrade extends Model
{
    /** @use HasFactory<QualityGradeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['product_id', 'code'])) {
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
}
