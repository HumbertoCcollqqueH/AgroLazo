<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\DeliveryAddress;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function deliveryAddress(Request $request): RedirectResponse
    {
        $buyer = $request->user()->buyer;
        abort_unless($buyer && $request->user()->can('update', $buyer), 403);
        $request->validate(['clear_address' => 'sometimes|boolean']);
        $address = $request->boolean('clear_address') ? null : DeliveryAddress::validate($request->all());
        DB::transaction(function () use ($buyer, $address, $request): void {
            $buyer = $buyer->newQuery()->lockForUpdate()->findOrFail($buyer->id);
            $buyer->update(['delivery_address' => $address]);
            AuditLog::record($request->user(), 'buyer.delivery_address_updated', $buyer, ['has_address' => $address !== null]);
        });
        $request->user()->unsetRelation('buyer');

        return to_route('account.edit')->with('status', $address === null ? 'Dirección habitual eliminada. Tus compras anteriores conservan su destino.' : 'Dirección habitual guardada. Se usará para preparar tus próximas cotizaciones y podrás cambiarla en cada pedido.');
    }

    public function edit(Request $request): View
    {
        return view('auth.register', ['accountUser' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($request->user()->id)],
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['nullable', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers(), function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('La contraseña no puede superar 72 bytes.');
                }
            }],
        ], [
            'current_password.required' => 'Escribe tu contraseña actual para guardar los cambios.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'email.unique' => 'Ese correo ya pertenece a otra cuenta.',
        ]);

        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->active, 403);

            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }

            $before = $user->only(['name', 'email']);
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $passwordChanged = filled($data['password'] ?? null);

            if ($passwordChanged) {
                $user->password = $data['password'];
                $user->remember_token = Str::random(60);
            }

            $user->save();
            AuditLog::record($user, 'user.profile_updated', $user, [
                'before' => $before,
                'after' => $user->only(['name', 'email']),
                'password_changed' => $passwordChanged,
            ]);

            return $user;
        });

        Auth::guard('web')->setUser($user);
        $request->session()->regenerate();

        return to_route('account.edit')->with('status', 'Tu cuenta fue actualizada. Conservas tus permisos y tu historial.');
    }
}
