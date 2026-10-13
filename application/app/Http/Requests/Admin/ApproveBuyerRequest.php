<?php

namespace App\Http\Requests\Admin;

use App\Models\Buyer;
use Illuminate\Foundation\Http\FormRequest;

class ApproveBuyerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $buyer = $this->route('buyer');

        return $buyer instanceof Buyer && $this->user()?->can('approve', $buyer);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Indica el motivo de la aprobación.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.'];
    }
}
