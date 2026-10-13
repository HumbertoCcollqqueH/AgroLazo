<?php

namespace App\Models;

use Database\Factories\OfferPriceAgreementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['offer_id', 'offer_version', 'version', 'gross_unit_price', 'producer_unit_cost', 'net_unit_price', 'currency', 'terms', 'valid_until', 'status', 'proposed_by', 'recorded_by', 'evidence', 'accepted_at'])]
#[Hidden(['terms', 'evidence'])]
class OfferPriceAgreement extends Model
{
    /** @use HasFactory<OfferPriceAgreementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['gross_unit_price' => 'decimal:6', 'producer_unit_cost' => 'decimal:6', 'net_unit_price' => 'decimal:6', 'terms' => 'encrypted', 'evidence' => 'encrypted', 'valid_until' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $agreement): void {
            if ($agreement->getOriginal('status') !== 'proposed' || $agreement->isDirty(['offer_id', 'offer_version', 'version', 'gross_unit_price', 'producer_unit_cost', 'net_unit_price', 'currency', 'terms', 'valid_until', 'proposed_by'])) {
                throw new LogicException('Las condiciones propuestas se conservan; crea una nueva versión.');
            }
        });
        static::deleting(fn () => throw new LogicException('Los acuerdos no se eliminan.'));
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
