<?php

namespace App\Models;

use Database\Factories\OfferPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['offer_id', 'uploaded_by', 'path', 'mime', 'size', 'caption'])]
#[Hidden(['path', 'caption'])]
class OfferPhoto extends Model
{
    /** @use HasFactory<OfferPhotoFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['caption' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La evidencia fotográfica no se modifica.'));
        static::deleting(fn () => throw new LogicException('La evidencia fotográfica no se elimina.'));
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
