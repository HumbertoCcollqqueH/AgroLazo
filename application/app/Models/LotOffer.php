<?php

namespace App\Models;

use App\Services\AllocationService;
use Database\Factories\LotOfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['lot_id', 'offer_id', 'agreement_id', 'offer_version', 'quantity', 'released_quantity', 'retired_quantity'])]
class LotOffer extends Model
{
    /** @use HasFactory<LotOfferFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'released_quantity' => 'decimal:3', 'retired_quantity' => 'decimal:3', 'offer_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $portion): void {
            if ($portion->isDirty(['lot_id', 'offer_id', 'agreement_id', 'offer_version', 'quantity'])) {
                throw new LogicException('La aportación original y su acuerdo se conservan.');
            }
        });
        static::deleting(fn () => throw new LogicException('Las porciones se liberan con historial; no se eliminan.'));
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(OfferPriceAgreement::class, 'agreement_id');
    }

    public function capacity(): string
    {
        return bcsub($this->quantity, $this->released_quantity, 3);
    }

    public function availableQuantity(): string
    {
        return app(AllocationService::class)->free($this);
    }
}
