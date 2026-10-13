<x-layout title="Cambiar contraseña">
    <section class="mx-auto max-w-lg rounded-3xl border border-[#dfe4da] bg-white p-8 shadow-sm">
        <h1 class="text-2xl font-semibold">Crea una nueva contraseña</h1>
        <form method="POST" action="{{ route('password.store') }}" class="mt-7 space-y-5">@csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-field name="email" label="Correo electrónico" type="email" :value="$email" autocomplete="email" required maxlength="255" />
            <x-field name="password" label="Nueva contraseña" type="password" autocomplete="new-password" required minlength="12" maxlength="72" hint="Usa al menos 12 caracteres, con mayúsculas, minúsculas y números." />
            <x-field name="password_confirmation" label="Repite la contraseña" type="password" autocomplete="new-password" required minlength="12" maxlength="72" />
            <button type="submit" class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white hover:bg-[#173f2c]">Guardar nueva contraseña</button>
        </form>
    </section>
</x-layout>
