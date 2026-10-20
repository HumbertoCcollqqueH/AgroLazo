<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommerceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    public function rules(): array
    {
        return ['key' => ['required', 'uuid']];
    }

    protected function getRedirectUrl(): string
    {
        if ($this->routeIs('commerce.quotes.store')) {
            return route('commerce.quotes.create', $this->route('lot'));
        }

        return parent::getRedirectUrl();
    }
}
