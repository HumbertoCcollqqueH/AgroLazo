@php($accountUser = $accountUser ?? null)
<x-layout :title="$accountUser ? 'Mi perfil' : 'Crear cuenta de comprador'">
    <div class="mx-auto max-w-2xl rounded-3xl border border-[#dfe4da] bg-white p-7 shadow-sm sm:p-9">
        <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">{{ $accountUser ? 'Datos de acceso' : 'Nuevas conexiones con el campo' }}</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ $accountUser ? 'Mi perfil' : 'Crea tu cuenta de comprador' }}</h1>
        @if($accountUser)
            <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Actualiza tu nombre y tu correo de acceso. Conservas tu cuenta, tus permisos y todo tu historial. Deja la nueva contraseña vacía si quieres mantener la actual.</p>
            @if($accountUser->hasRole('admin'))<p class="mt-4 rounded-xl bg-[#edf5e8] p-4 text-sm"><strong>Administrador con acceso completo.</strong> Puedes crear usuarios, aprobar compradores y gestionar ofertas y publicaciones. Personaliza aquí los datos iniciales de tu cuenta.</p>@endif
        @else
            <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Un administrador revisará tu perfil antes de habilitar las compras. Tu cuenta te permitirá consultar el estado de esa revisión.</p>
        @endif
        <form method="POST" action="{{ $accountUser ? route('account.update') : route('register') }}" class="mt-7 space-y-5">
            @csrf
            @if($accountUser) @method('PATCH') @endif
            <x-field name="name" label="Nombre completo o razón social" :value="$accountUser?->name" autocomplete="name" required maxlength="255" />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field name="email" label="Correo electrónico" :value="$accountUser?->email" type="email" autocomplete="email" required maxlength="255" />
                @unless($accountUser)
                    <x-field name="phone" label="Teléfono (opcional)" type="tel" autocomplete="tel" placeholder="+51987654321" hint="Incluye el código de país, por ejemplo +51 para Perú." maxlength="16" />
                @endunless
            </div>
            @unless($accountUser)
                <div><label for="buyer_type" class="mb-2 block text-sm font-semibold">Tipo de comprador</label><select id="buyer_type" name="buyer_type" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3 outline-none focus:ring-2 focus:ring-[#d8e6cf]">@foreach($buyerTypes as $value => $label)<option value="{{ $value }}" @selected(old('buyer_type', 'individual') === $value)>{{ $label }}</option>@endforeach</select></div>
            @endunless
            @if($accountUser)<x-field name="current_password" label="Contraseña actual" type="password" autocomplete="current-password" required hint="La necesitamos para guardar cambios en tus datos de acceso." />@endif
            <x-field name="password" :label="$accountUser ? 'Nueva contraseña (opcional)' : 'Contraseña'" type="password" autocomplete="new-password" :required="!$accountUser" minlength="12" maxlength="72" hint="Usa al menos 12 caracteres, con mayúsculas, minúsculas y números." />
            <x-field name="password_confirmation" label="Repite la contraseña" type="password" autocomplete="new-password" :required="!$accountUser" minlength="12" maxlength="72" />
            <button class="w-full rounded-xl bg-[#22583e] px-5 py-3.5 font-semibold text-white hover:bg-[#173f2c]" type="submit">{{ $accountUser ? 'Guardar mi perfil' : 'Crear mi cuenta' }}</button>
        </form>
        @if($accountUser?->buyer && $accountUser->can('update', $accountUser->buyer))
            <section class="mt-9 border-t border-[#dfe4da] pt-7">
                <h2 class="text-2xl font-semibold">Mi lugar habitual de entrega</h2>
                <p class="mt-3 text-sm leading-6">Guarda la dirección de tu restaurante, hotel, negocio u hogar. Es opcional y se cargará al cotizar; podrás elegir otro destino para cada pedido. Cambiarla aquí no modifica compras anteriores.</p>
                <form method="POST" action="{{ route('account.delivery-address') }}" class="mt-5 space-y-5">
                    @csrf @method('PATCH')
                    @include('commerce.delivery-address-fields', ['destination' => $accountUser->buyer->delivery_address ?? ['recipient' => $accountUser->name, 'phone' => $accountUser->buyer->phone ?? '']])
                    <button class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Guardar dirección habitual</button>
                </form>
                @if($accountUser->buyer->delivery_address)
                    <form method="POST" action="{{ route('account.delivery-address') }}" class="mt-4">@csrf @method('PATCH')<input type="hidden" name="clear_address" value="1"><button class="text-sm underline">Quitar dirección habitual del perfil</button></form>
                @endif
            </section>
        @endif
        @unless($accountUser)
            <p class="mt-6 text-center text-sm text-[#6f7d66]">¿Ya tienes cuenta? <a class="font-semibold text-[#22583e] underline underline-offset-4" href="{{ route('login') }}">Ingresar</a></p>
        @endunless
    </div>
</x-layout>
