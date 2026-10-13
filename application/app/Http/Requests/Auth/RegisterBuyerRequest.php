<?php

namespace App\Http\Requests\Auth;

use App\Models\Buyer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterBuyerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('phone'))) {
            $phone = preg_replace('/[\s()\-]/', '', $this->input('phone'));
            $this->merge(['phone' => preg_match('/^9\d{8}$/', $phone) ? '+51'.$phone : $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')],
            'buyer_type' => ['required', Rule::in(array_keys(Buyer::TYPES))],
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers(), function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('La contraseña no puede superar 72 bytes.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'buyer_type.required' => 'Selecciona el tipo de comprador.',
            'buyer_type.in' => 'Selecciona un tipo de comprador válido.',
            'phone.regex' => 'Usa un teléfono peruano de 9 dígitos o formato internacional, por ejemplo +51987654321.',
            'password.required' => 'Escribe una contraseña.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ];
    }
}
