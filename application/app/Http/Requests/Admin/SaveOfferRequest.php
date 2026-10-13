<?php

namespace App\Http\Requests\Admin;

use App\Services\OfferRegistry;
use Illuminate\Foundation\Http\FormRequest;

class SaveOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-offers') ?? false;
    }

    public function rules(): array
    {
        return OfferRegistry::rules($this->route('offer') !== null);
    }
}
