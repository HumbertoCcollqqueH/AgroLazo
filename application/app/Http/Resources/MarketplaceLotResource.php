<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'product' => ['id' => $this->product->id, 'name' => $this->product->name],
            'variety' => ['id' => $this->variety->id, 'name' => $this->variety->name],
            'quality' => ['id' => $this->qualityGrade->id, 'name' => $this->qualityGrade->name],
            'location' => ['id' => $this->location->id, 'name' => $this->location->name],
            'unit' => ['code' => $this->baseUnit->code, 'dimension' => $this->baseUnit->dimension],
            'available_quantity' => bcadd((string) $this->available_quantity, '0', 3),
            'producer_count' => (int) $this->producer_count,
            'available_from' => $this->available_from->toDateString(),
            'available_until' => $this->available_until->toDateString(),
            'estimated_unit_price' => [
                'currency' => 'PEN',
                'minimum' => bcadd((string) $this->unit_price_min, '0', 6),
                'maximum' => bcadd((string) $this->unit_price_max, '0', 6),
                'basis' => 'Rango de precios brutos aceptados de las ofertas que componen el lote.',
                'excludes' => ['logística', 'cargos del comprador', 'tributos aplicables'],
                'is_quote' => false,
            ],
            'traceability' => ['kind' => 'commercial_lot', 'verified_sources' => true, 'physical_receipt_confirmed' => false],
            'purchasing_enabled' => (bool) config('commerce.live_enabled'),
            'demo_checkout_enabled' => app()->environment(['local', 'testing']) && ! config('commerce.live_enabled'),
            'checkout_url' => route('commerce.quotes.create', $this->resource),
            'url' => route('marketplace.show', $this->code),
        ];
    }
}
