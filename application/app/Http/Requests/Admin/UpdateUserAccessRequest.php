<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()?->can('update', $target);
    }

    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'role' => ['required', 'string', Rule::in(['admin', 'coordinator', 'buyer', 'producer'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Indica el motivo del cambio.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.'];
    }
}
