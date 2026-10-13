<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NormalizeQuantityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-catalogs') ?? false;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'string', 'regex:/^\\d{1,15}(?:\\.\\d{1,3})?$/D'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'from_unit_id' => ['required', 'integer', 'exists:units,id'],
            'context' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
