<?php

namespace App\Models;

use Database\Factories\BuyerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'phone', 'delivery_address'])]
#[Hidden(['phone', 'delivery_address'])]
class Buyer extends Model
{
    /** @use HasFactory<BuyerFactory> */
    use HasFactory;

    public const TYPES = [
        'restaurant' => 'Restaurante',
        'hotel' => 'Hotel',
        'store' => 'Tienda',
        'distributor' => 'Distribuidor',
        'processor' => 'Procesador',
        'company' => 'Empresa',
        'individual' => 'Persona natural',
    ];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'delivery_address' => 'encrypted:array', 'approved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
