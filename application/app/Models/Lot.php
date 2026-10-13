<?php

namespace App\Models;

use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['code', 'source_key', 'source_hash', 'created_by', 'product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id', 'available_from', 'available_until', 'status', 'version', 'allow_price_variation', 'compatibility_rule', 'published_at'])]
#[Hidden(['source_key', 'source_hash', 'created_by'])]
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use HasFactory;

    public const STATUSES = ['draft' => 'Borrador', 'published' => 'Publicado', 'paused' => 'Pausado', 'cancelled' => 'Cancelado', 'expired' => 'Vencido'];

    protected function casts(): array
    {
        return ['available_from' => 'immutable_date', 'available_until' => 'immutable_date', 'published_at' => 'immutable_datetime', 'version' => 'integer', 'allow_price_variation' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $lot): void {
            if ($lot->isDirty(['code', 'source_key', 'source_hash', 'created_by', 'product_id', 'variety_id', 'quality_grade_id', 'location_id', 'base_unit_id', 'available_from', 'available_until', 'allow_price_variation', 'compatibility_rule'])) {
                throw new LogicException('La composición y el origen del lote se conservan. Cancela el saldo libre para formar un lote nuevo.');
            }
        });
        static::deleting(fn () => throw new LogicException('El lote se cancela; no se elimina su trazabilidad.'));
    }

    public function portions(): HasMany
    {
        return $this->hasMany(LotOffer::class)->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(LotHistory::class)->orderByDesc('id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(ProductVariety::class, 'variety_id');
    }

    public function qualityGrade(): BelongsTo
    {
        return $this->belongsTo(QualityGrade::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
