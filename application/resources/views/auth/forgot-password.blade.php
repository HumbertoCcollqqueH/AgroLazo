<x-layout title="Recuperar contraseña">
    <section class="mx-auto max-w-lg rounded-3xl border border-[#dfe4da] bg-white p-8 shadow-sm">
        <h1 class="text-2xl font-semibold">Recupera tu acceso</h1>
        <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Indica el correo de tu cuenta. Si está registrado, recibirás un enlace para crear una nueva contraseña.</p>
        <form method="POST" action="{{ route('password.email') }}" class="mt-7 space-y-5">@csrf
            <x-field name="email" label="Correo electrónico" type="email" autocomplete="email" required autofocus maxlength="255" />
            <button type="submit" class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white hover:bg-[#173f2c]">Enviar enlace</button>
        </form>
        <a class="mt-6 block text-center text-sm font-semibold underline underline-offset-4" href="{{ route('login') }}">Volver al ingreso</a>
    </section>
</x-layout>
