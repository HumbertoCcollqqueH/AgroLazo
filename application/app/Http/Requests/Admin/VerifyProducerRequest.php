<?php

namespace App\Http\Requests\Admin;

use App\Services\ProducerRegistry;
use Illuminate\Foundation\Http\FormRequest;

class VerifyProducerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verify', $this->route('producer')) ?? false;
    }

    public function rules(): array
    {
        return ProducerRegistry::verificationRules();
    }
}
