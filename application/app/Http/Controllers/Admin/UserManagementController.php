<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserAccessRequest;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['admin', 'coordinator', 'buyer'])],
            'active' => ['nullable', 'boolean'],
            'buyer_status' => ['nullable', Rule::in(['pending', 'approved', 'suspended'])],
        ]);
        $query = User::query()->with(['roles', 'buyer']);

        if (($filters['q'] ?? '') !== '') {
            $search = '%'.addcslashes(trim($filters['q']), '%_\\').'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $search)->orWhere('email', 'ilike', $search));
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn ($query) => $query->where('code', $filters['role']));
        }

        if (isset($filters['active'])) {
            $query->where('active', (bool) $filters['active']);
        }

        if (! empty($filters['buyer_status'])) {
            $query->whereHas('roles', fn ($query) => $query->where('code', 'buyer'))
                ->whereHas('buyer', fn ($query) => $query->where('status', $filters['buyer_status']));
        }

        return view('admin.users.index', [
            'users' => $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(),
            'roles' => Role::query()->orderBy('id')->get(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.index', [
            'creating' => true,
            'roles' => Role::query()->whereIn('code', ['admin', 'coordinator', 'buyer'])->orderBy('id')->get(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('create', User::class);
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->roles()->attach(Role::query()->where('code', $data['role'])->firstOrFail());

            if ($data['role'] === 'buyer') {
                $user->buyer()->create(['type' => 'individual']);
            }

            AuditLog::record($actor, 'user.created', $user, [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $data['role'],
            ], $data['reason']);
        });

        return to_route('admin.users.index')->with('status', 'Usuario creado. Ya puede ingresar con el correo y la contraseña definidos. Los compradores requieren aprobación antes de comprar.');
    }

    public function update(UpdateUserAccessRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $user, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($actor)->authorize('update', $target);
            $active = $request->boolean('active');

            if ($target->active && $target->hasRole('admin') && (! $active || $data['role'] !== 'admin')) {
                $activeAdmins = User::query()->where('active', true)
                    ->whereHas('roles', fn ($query) => $query->where('code', 'admin'))->count();

                if ($activeAdmins <= 1) {
                    throw ValidationException::withMessages(['role' => 'Debe existir al menos un administrador activo.']);
                }
            }

            $before = ['active' => $target->active, 'roles' => $target->roles()->pluck('code')->all()];
            $target->forceFill(['active' => $active])->save();
            $target->roles()->sync([Role::query()->where('code', $data['role'])->firstOrFail()->id]);

            if ($data['role'] === 'buyer' && ! $target->buyer()->exists()) {
                $target->buyer()->create(['type' => 'individual']);
            }

            AuditLog::record($actor, 'user.access_updated', $target, [
                'before' => $before,
                'after' => ['active' => $active, 'roles' => [$data['role']]],
            ], $data['reason']);
        });

        return back()->with('status', 'Acceso actualizado y registrado en auditoría.');
    }
}
