<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends ForgotPasswordRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [...parent::rules()['email'], Rule::unique('users')],
            'role' => ['required', 'string', Rule::in(['admin', 'coordinator', 'buyer', 'producer'])],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers(), function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('La contraseña no puede superar 72 bytes.');
                }
            }],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
