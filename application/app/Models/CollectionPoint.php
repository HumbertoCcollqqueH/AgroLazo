<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'description', 'address', 'location_id', 'latitude', 'longitude', 'capacity', 'base_unit_id', 'opens_at', 'closes_at', 'active', 'recorded_by'])]
class CollectionPoint extends CommerceRecord
{
    use HasFactory;

    protected array $mutable = ['active'];

    protected function casts(): array
    {
        return ['address' => 'encrypted', 'latitude' => 'decimal:6', 'longitude' => 'decimal:6', 'capacity' => 'decimal:3', 'active' => 'boolean'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }
}
