<?php

namespace App\Models;

use App\Services\LotAvailability;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['source_key', 'source_hash', 'channel', 'registered_by', 'producer_id', 'product_id', 'variety_id', 'quality_grade_id', 'location_id', 'quantity', 'unit_id', 'base_unit_id', 'normalized_quantity', 'conversion_factor', 'conversion_id', 'conversion_source', 'conversion_date', 'context', 'available_from', 'available_until', 'requested_unit_price', 'notes', 'status', 'version', 'withdrawn_quantity'])]
#[Hidden(['notes', 'source_hash'])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    public const STATUSES = ['draft' => 'Borrador', 'pending_verification' => 'Pendiente de verificación', 'needs_information' => 'Necesita información', 'verified' => 'Verificada', 'published' => 'Publicada', 'rejected' => 'Rechazada', 'cancelled' => 'Cancelada', 'expired' => 'Vencida'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'normalized_quantity' => 'decimal:3', 'withdrawn_quantity' => 'decimal:3', 'conversion_factor' => 'decimal:9', 'requested_unit_price' => 'decimal:6', 'notes' => 'encrypted', 'available_from' => 'immutable_date', 'available_until' => 'immutable_date', 'conversion_date' => 'immutable_date', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $offer): void {
            if ($offer->isDirty(['producer_id', 'registered_by', 'source_key', 'source_hash', 'channel'])) {
                throw new LogicException('El origen y el titular de la oferta son permanentes.');
            }
        });
        static::deleting(fn () => throw new LogicException('La oferta se cancela; no se elimina.'));
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(OfferPriceAgreement::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OfferHistory::class)->orderByDesc('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(OfferPhoto::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'needs_information', 'rejected'], true);
    }

    public function activeAgreement(): ?OfferPriceAgreement
    {
        $agreement = $this->agreements()->where('offer_version', $this->version)->orderByDesc('version')->first();

        return $agreement?->status === 'accepted' && $agreement->valid_until->isFuture() ? $agreement : null;
    }

    public function isAvailableForAggregation(): bool
    {
        return $this->exists && app(LotAvailability::class)->ungroupedOffers()->whereKey($this->id)->exists();
    }
}
