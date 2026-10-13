<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MarketplaceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $decimal = ['nullable', 'string', 'regex:/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/'];

        return [
            'q' => ['nullable', 'string', 'max:80'],
            'product_id' => 'nullable|integer|exists:products,id',
            'variety_id' => 'nullable|integer|exists:product_varieties,id',
            'quality_grade_id' => 'nullable|integer|exists:quality_grades,id',
            'base_unit_id' => 'nullable|integer|exists:units,id',
            'region_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'department')],
            'province_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
            'min_quantity' => $decimal,
            'min_price' => ['nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/'],
            'max_price' => ['nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/'],
            'date' => 'nullable|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString(),
            'availability' => ['nullable', Rule::in(['now', 'upcoming'])],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'quantity_desc', 'date_asc', 'nearest'])],
            'page' => 'nullable|integer|min:1|max:100000',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->validated();
            if (isset($data['min_price'], $data['max_price']) && $data['min_price'] !== '' && $data['max_price'] !== ''
                && bccomp($data['min_price'], $data['max_price'], 6) > 0) {
                $validator->errors()->add('max_price', 'El precio máximo debe ser igual o mayor al mínimo.');
            }
        }];
    }
}
