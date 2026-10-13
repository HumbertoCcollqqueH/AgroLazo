<?php

namespace App\Http\Requests\Admin;

use App\Services\ProducerRegistry;
use Illuminate\Foundation\Http\FormRequest;

class RecordProducerConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-producers') ?? false;
    }

    public function rules(): array
    {
        return ProducerRegistry::consentRules();
    }
}
