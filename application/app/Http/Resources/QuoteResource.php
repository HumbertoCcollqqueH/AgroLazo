<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'lot_code' => $this->lot->code,
            'quantity' => $this->quantity, 'unit' => $this->baseUnit->code,
            'currency' => 'PEN', 'status' => $this->status, 'synthetic' => $this->synthetic,
            'subtotal_minor' => $this->subtotal_minor, 'fee_minor' => $this->fee_minor,
            'logistics_minor' => $this->logistics_minor, 'discount_minor' => $this->discount_minor,
            'tax_minor' => $this->tax_minor, 'total_minor' => $this->total_minor,
            'weighted_unit_price' => $this->weighted_unit_price, 'pricing_version' => $this->pricing_version,
            'transport_paid_by' => $this->pricing_snapshot['transport_paid_by'] ?? null,
            'delivery_date_required' => true,
            'delivery_date_min' => max(now('America/Lima')->addDay()->toDateString(), $this->lot->available_from->toDateString()),
            'delivery_date_max' => $this->lot->available_until->toDateString(),
            'issued_at' => $this->issued_at->toIso8601String(), 'expires_at' => $this->expires_at->toIso8601String(),
            'retains_inventory' => false, 'disclosure' => $this->pricing_snapshot['disclosure'],
            'items' => $this->items->map(fn ($item): array => ['quantity' => $item->quantity, 'gross_unit_price' => $item->gross_unit_price, 'gross_minor' => $item->gross_minor]),
        ];
    }
}
