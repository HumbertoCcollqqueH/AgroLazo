<?php

namespace App\Http\Requests;

use App\Services\Commerce;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'data' => ['nullable', Rule::in(['synthetic', 'real'])],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ];
    }

    public function filters(): array
    {
        $data = $this->validated();
        $now = CarbonImmutable::now('America/Lima');
        $from = CarbonImmutable::parse($data['from'] ?? $now->startOfMonth()->toDateString(), 'America/Lima')->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $now->toDateString(), 'America/Lima')->startOfDay();
        Commerce::ensure($to->greaterThanOrEqualTo($from) && $from->diffInDays($to) <= 365, 'to', 'Selecciona un período ordenado de hasta 366 días.');

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'data' => $data['data'] ?? (app()->environment(['local', 'testing']) ? 'synthetic' : 'real'), 'product_id' => $data['product_id'] ?? null, 'location_id' => $data['location_id'] ?? null];
    }
}
