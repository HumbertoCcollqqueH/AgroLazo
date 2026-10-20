@php
    $creating = $creating ?? false;
    $roleDescriptions = [
        ['Administración', 'Configura el sistema, gestiona usuarios, supervisa publicaciones y controla las finanzas.'],
        ['Coordinación', 'Verifica productores y ofertas, atiende mensajes y organiza pedidos, acopio y entregas.'],
        ['Compras', 'Explora productos y consulta sus pedidos. La aprobación de su perfil habilita las compras.'],
        ['Producción', 'Completa su ficha, ofrece productos, acepta precios y coordina recojos dentro de AgroLazo.'],
    ];
@endphp
<x-layout :title="$creating ? 'Crear usuario' : 'Usuarios y compradores'">
    <header class="mb-7 flex flex-wrap items-end justify-between gap-4">
        <div>
            @if($creating)<a href="{{ route('admin.users.index') }}" class="mb-4 inline-flex text-sm font-semibold text-slate-600 underline underline-offset-4">← Volver al directorio</a>@endif
            <p class="text-xs font-bold uppercase tracking-[.16em] text-slate-500">Equipo y acceso</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{{ $creating ? 'Una cuenta, el acceso adecuado' : 'Usuarios y compradores' }}</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">{{ $creating ? 'Completa los datos de acceso y elige qué podrá hacer esta persona en AgroLazo.' : 'Administra tu equipo, revisa compradores y mantén el control de cada cuenta.' }}</p>
        </div>
        @unless($creating)<a href="{{ route('admin.users.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-700"><x-ac-icon name="plus" />Crear usuario</a>@endunless
    </header>
    @if($creating)
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
            <form method="POST" action="{{ route('admin.users.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                @csrf
                <section aria-labelledby="account-data-title">
                    <div class="mb-6 flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-sm font-bold text-slate-700">01</span><div><h2 id="account-data-title" class="text-lg font-semibold text-slate-900">Identidad y rol</h2><p class="mt-1 text-sm text-slate-500">El correo será su nombre de usuario para ingresar.</p></div></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-field name="name" label="Nombre completo" autocomplete="name" required maxlength="255" />
                        <x-field name="email" label="Correo de acceso" type="email" autocomplete="email" required maxlength="255" />
                        <div class="sm:col-span-2"><label for="role" class="mb-2 block text-sm font-semibold">Rol de la cuenta</label><select id="role" name="role" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3" @if($errors->has('role')) aria-invalid="true" @endif><option value="">Selecciona un rol</option>@foreach($roles as $role)<option value="{{ $role->code }}" @selected(old('role') === $role->code)>{{ $role->name }}</option>@endforeach</select></div>
                    </div>
                </section>
                <section class="mt-7 border-t border-slate-100 pt-7" aria-labelledby="account-security-title">
                    <div class="mb-6 flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-sm font-bold text-slate-700">02</span><div><h2 id="account-security-title" class="text-lg font-semibold text-slate-900">Acceso inicial</h2><p class="mt-1 text-sm text-slate-500">La persona podrá cambiar su contraseña desde Mi perfil.</p></div></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-field name="password" label="Contraseña inicial" type="password" autocomplete="new-password" required minlength="12" maxlength="72" hint="Al menos 12 caracteres, con mayúsculas, minúsculas y números." />
                        <x-field name="password_confirmation" label="Repite la contraseña" type="password" autocomplete="new-password" required minlength="12" maxlength="72" />
                        <div class="sm:col-span-2"><x-field name="reason" label="Motivo del alta" required minlength="5" maxlength="500" placeholder="Por ejemplo: incorporación al equipo de coordinación" hint="Quedará registrado junto al responsable de crear la cuenta." /></div>
                    </div>
                </section>
                <div class="mt-7 flex flex-wrap items-center gap-4 border-t border-slate-100 pt-6"><button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-700"><x-ac-icon name="check" />Crear usuario</button><a class="text-sm font-semibold text-slate-600 underline underline-offset-4" href="{{ route('admin.users.index') }}">Cancelar</a></div>
            </form>
            <aside class="space-y-5">
                <section class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-ac-icon name="shield" />¿Qué acceso necesita?</h2><dl class="mt-5 space-y-5">@foreach($roleDescriptions as [$label, $description])<div><dt class="text-sm font-semibold text-slate-900">{{ $label }}</dt><dd class="mt-1 text-sm leading-6 text-slate-600">{{ $description }}</dd></div>@endforeach</dl></section>
                <section class="rounded-2xl border border-teal-200 bg-teal-50 p-6"><h2 class="text-sm font-semibold text-teal-950">¿Vas a registrar un productor?</h2><p class="mt-2 text-sm leading-6 text-teal-900">Crea su ficha de producción y contacto. Podrá presentar ofertas desde su cuenta de productor.</p><a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-950 underline underline-offset-4" href="{{ route('producers.create') }}">Registrar productor <x-ac-icon name="arrow" /></a></section>
                <p class="px-1 text-xs leading-5 text-slate-500">Comunica el correo y la contraseña inicial al nuevo usuario. Los compradores necesitan la aprobación de su perfil antes de comprar.</p>
            </aside>
        </div>
    @else
        <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="directory-title">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4"><div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600"><x-ac-icon name="users" /></span><div><h2 id="directory-title" class="text-sm font-semibold text-slate-900">Directorio de cuentas</h2><p class="mt-0.5 text-xs text-slate-500">{{ $users->total() }} {{ $users->total() === 1 ? 'usuario encontrado' : 'usuarios encontrados' }}</p></div></div><a href="{{ route('admin.users.index', ['role' => 'buyer', 'buyer_status' => 'pending', 'active' => '1']) }}" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">Compradores por aprobar →</a></div>
            <form method="GET" action="{{ route('admin.users.index') }}" class="grid items-end gap-4 p-5 sm:grid-cols-2 xl:grid-cols-[minmax(180px,1.5fr)_1fr_1fr_1fr_auto]">
                <div><label for="user-search" class="mb-2 block text-xs font-semibold text-slate-600">Buscar una cuenta</label><input type="search" id="user-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Nombre o correo" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"></div>
                <div><label for="filter-role" class="mb-2 block text-xs font-semibold text-slate-600">Rol</label><select id="filter-role" name="role" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">Todos los roles</option>@foreach($roles as $role)<option value="{{ $role->code }}" @selected(($filters['role'] ?? '') === $role->code)>{{ $role->name }}</option>@endforeach</select></div>
                <div><label for="filter-active" class="mb-2 block text-xs font-semibold text-slate-600">Acceso</label><select id="filter-active" name="active" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">Todos los accesos</option><option value="1" @selected((string) ($filters['active'] ?? '') === '1')>Activo</option><option value="0" @selected((string) ($filters['active'] ?? '') === '0')>Desactivado</option></select></div>
                <div><label for="filter-buyer" class="mb-2 block text-xs font-semibold text-slate-600">Perfil comprador</label><select id="filter-buyer" name="buyer_status" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">Todos los perfiles</option>@foreach(['pending' => 'Por aprobar', 'approved' => 'Aprobado', 'suspended' => 'Suspendido'] as $value => $label)<option value="{{ $value }}" @selected(($filters['buyer_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="flex items-center gap-3"><button type="submit" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Filtrar</button>@if(collect($filters)->contains(fn ($value) => $value !== null && $value !== ''))<a class="text-xs font-semibold text-slate-600 underline underline-offset-4" href="{{ route('admin.users.index') }}">Limpiar</a>@endif</div>
            </form>
        </section>
        <div class="space-y-4">
            @forelse($users as $user)
                @php
                    $isOwnAccount = $user->id === auth()->id();
                    $isBuyer = $user->roles->contains('code', 'buyer') && $user->buyer;
                    $editingAccess = (string) old('access_user_id') === (string) $user->id;
                    $editingApproval = $isBuyer && (string) old('approval_buyer_id') === (string) $user->buyer->id;
                    $initials = collect(preg_split('/\s+/u', trim($user->name)))->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
                @endphp
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="user-name-{{ $user->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-4 p-5 sm:p-6">
                        <div class="flex min-w-0 max-w-full items-start gap-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-sm font-bold text-slate-600" aria-hidden="true">{{ $initials }}</span>
                            <div class="min-w-0"><h2 id="user-name-{{ $user->id }}" class="text-base font-semibold text-slate-900">{{ $user->name }} @if($isOwnAccount)<span class="ml-1 text-xs font-normal text-slate-500">· Tú</span>@endif</h2><p class="mt-1 break-all text-sm text-slate-500">{{ $user->email }}</p><div class="mt-3 flex flex-wrap gap-2">@foreach($user->roles as $role)<span @class(['rounded-md px-2.5 py-1 text-xs font-semibold', 'bg-slate-900 text-white' => $role->code === 'admin', 'bg-teal-50 text-teal-800' => $role->code === 'coordinator', 'bg-indigo-50 text-indigo-700' => $role->code === 'buyer', 'bg-slate-100 text-slate-600' => ! in_array($role->code, ['admin', 'coordinator', 'buyer'], true)])>{{ $role->name }}</span>@endforeach</div></div>
                        </div>
                        <div class="text-left sm:text-right"><span @class(['inline-flex rounded-full px-3 py-1.5 text-xs font-semibold', 'bg-emerald-50 text-emerald-800' => $user->active, 'bg-slate-100 text-slate-600' => ! $user->active])>{{ $user->active ? 'Acceso activo' : 'Acceso desactivado' }}</span><p class="mt-2 text-xs text-slate-500">{{ $user->last_login_at ? 'Último ingreso: '.$user->last_login_at->timezone('America/Lima')->format('d/m/Y H:i') : 'Aún no ha ingresado' }}</p></div>
                    </div>
                    @if($isBuyer)
                        <div @class(['mx-5 mb-5 rounded-xl border p-4 sm:mx-6', 'border-amber-200 bg-amber-50' => $user->buyer->status === 'pending', 'border-slate-200 bg-slate-50' => $user->buyer->status !== 'pending'])>
                            <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-semibold text-slate-800">Perfil comprador · {{ match($user->buyer->status) { 'approved' => 'Aprobado', 'suspended' => 'Suspendido', default => 'Pendiente de aprobación' } }}</p><p class="text-xs text-slate-600">Teléfono: {{ $user->buyer->phone ?: 'Sin registrar' }}</p></div>
                            @if($user->buyer->status === 'pending' && $user->active)
                                <p class="mt-2 text-xs leading-5 text-amber-900">Revisa los datos de contacto y registra el resultado para habilitar sus compras.</p>
                                <form method="POST" action="{{ route('admin.buyers.approve', $user->buyer) }}" class="mt-4 flex flex-wrap items-end gap-3">
                                    @csrf<input type="hidden" name="approval_buyer_id" value="{{ $user->buyer->id }}">
                                    <div class="min-w-0 basis-64 grow"><label for="approval-{{ $user->id }}" class="mb-2 block text-xs font-semibold text-slate-700">Resultado de la revisión</label><input id="approval-{{ $user->id }}" name="reason" value="{{ $editingApproval ? old('reason') : '' }}" required minlength="5" maxlength="500" class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2.5 text-sm" placeholder="Describe los datos que verificaste" @if($editingApproval && $errors->has('reason')) aria-invalid="true" @endif></div>
                                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Aprobar comprador</button>
                                </form>
                            @elseif($user->buyer->status === 'pending')<p class="mt-2 text-xs leading-5 text-slate-600">La cuenta tiene el acceso desactivado. Un administrador debe habilitarlo antes de aprobar el perfil.</p>@endif
                        </div>
                    @endif
                    @if($isOwnAccount)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/70 px-5 py-4 sm:px-6"><p class="text-xs leading-5 text-slate-500">Otro administrador gestiona tu rol y estado de acceso.</p><a class="text-xs font-semibold text-slate-700 underline underline-offset-4" href="{{ route('account.edit') }}">Editar mi perfil y contraseña →</a></div>
                    @else
                        <details class="group border-t border-slate-100" @if($editingAccess) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-semibold text-slate-600 hover:bg-slate-50 sm:px-6"><span class="flex items-center gap-2"><x-ac-icon name="settings" />Gestionar acceso</span><span class="transition group-open:rotate-90"><x-ac-icon name="chevron" /></span></summary>
                            <form action="{{ route('admin.users.update', $user) }}" method="POST" class="grid gap-4 bg-slate-50 px-5 pb-5 pt-3 sm:grid-cols-2 sm:px-6 xl:grid-cols-[1fr_1fr_2fr_auto] xl:items-end">
                                @csrf @method('PATCH')<input type="hidden" name="access_user_id" value="{{ $user->id }}">
                                <div><label for="role-{{ $user->id }}" class="mb-2 block text-xs font-semibold text-slate-600">Rol</label><select id="role-{{ $user->id }}" name="role" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm" @if($editingAccess && $errors->has('role')) aria-invalid="true" @endif>@foreach($roles as $role)<option value="{{ $role->code }}" @selected($editingAccess ? old('role') === $role->code : $user->roles->contains('code', $role->code))>{{ $role->name }}</option>@endforeach</select></div>
                                <div><label for="active-{{ $user->id }}" class="mb-2 block text-xs font-semibold text-slate-600">Acceso</label><select id="active-{{ $user->id }}" name="active" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="1" @selected($editingAccess ? (string) old('active') === '1' : $user->active)>Activo</option><option value="0" @selected($editingAccess ? (string) old('active') === '0' : ! $user->active)>Desactivado</option></select></div>
                                <div><label for="reason-{{ $user->id }}" class="mb-2 block text-xs font-semibold text-slate-600">Motivo del cambio</label><input id="reason-{{ $user->id }}" name="reason" value="{{ $editingAccess ? old('reason') : '' }}" required minlength="5" maxlength="500" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm" placeholder="Explica el motivo" @if($editingAccess && $errors->has('reason')) aria-invalid="true" @endif></div>
                                <button class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700" type="submit">Guardar cambios</button>
                                <p class="text-xs leading-5 text-slate-500 sm:col-span-2 xl:col-span-4">El cambio de rol y acceso quedará registrado en auditoría.</p>
                            </form>
                        </details>
                    @endif
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center"><span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500"><x-ac-icon name="search" /></span><h2 class="mt-4 text-lg font-semibold text-slate-900">No encontramos cuentas con estos filtros</h2><p class="mt-2 text-sm text-slate-500">Prueba con otro nombre, correo o estado.</p><a href="{{ route('admin.users.index') }}" class="mt-5 inline-flex rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700">Ver todas las cuentas</a></div>
            @endforelse
        </div>
        <div class="mt-6">{{ $users->links() }}</div>
    @endif
</x-layout>
