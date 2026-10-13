<?php

namespace App\Http\Requests\Admin;

use App\Models\Producer;
use App\Services\ProducerRegistry;
use Illuminate\Foundation\Http\FormRequest;

class ChangeProducerPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $producer = $this->route('producer');

        return $producer instanceof Producer && ($this->user()?->can('update', $producer) ?? false);
    }

    public function rules(): array
    {
        return ProducerRegistry::phoneRules();
    }
}
