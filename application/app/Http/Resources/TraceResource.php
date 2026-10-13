<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TraceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->public_snapshot;

        return [
            ...collect($s)->only(['schema_version', 'kind', 'code', 'product', 'variety', 'quality', 'region', 'unit', 'recorded_at', 'physical_receipt_confirmed', 'synthetic', 'quantity', 'source_count'])->all(),
            'milestones' => array_map(fn (array $row): array => collect($row)->only(['event', 'date', 'quantity'])->all(), $s['milestones']),
        ];
    }
}
