<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterBuyerRequest;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Role;
use App\Models\User;
use App\Services\ProducerPortal;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisteredBuyerController extends Controller
{
    public function create(Request $request): View
    {
        if ($request->query('type') !== 'buyer') {
            return view('auth.choose-account');
        }

        return view('auth.register', ['buyerTypes' => Buyer::TYPES]);
    }

    public function store(RegisterBuyerRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->roles()->attach(Role::query()->where('code', 'buyer')->firstOrFail());
            $user->buyer()->create(['type' => $data['buyer_type'], 'phone' => $data['phone'] ?? null]);
            AuditLog::record($user, 'buyer.registered', $user->buyer, ['status' => 'pending']);
            ProducerPortal::team('Nuevo comprador pendiente de aprobación: '.$user->name.'.', route('admin.users.index', ['role' => 'buyer', 'buyer_status' => 'pending']), 'buyer-registered:'.$user->id, 'manage-users');

            return $user;
        });

        event(new Registered($user));
        Auth::login($user, str_contains($request->userAgent() ?? '', 'AgroLazoAndroid/'));
        $request->session()->regenerate();

        return to_route('marketplace.index')->with('status', 'Tu cuenta está creada. Ya puedes explorar productos; el equipo revisará tu perfil antes de habilitar compras.');
    }
}
