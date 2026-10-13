<x-layout title="Ingresar">
    <div class="grid gap-10 lg:grid-cols-[1.1fr_1fr] lg:items-center">
        <section class="py-4 lg:pr-12">
            <p class="text-xs font-bold uppercase tracking-[.2em] text-[#65845b]">Comercio agrícola · Puno, Perú</p>
            <h1 class="mt-5 text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Cultivamos vínculos.<br><span class="text-[#628456]">Creamos oportunidades.</span></h1>
            <p class="mt-6 max-w-lg leading-7 text-[#6a7762]">Ingresa a tu espacio para coordinar con AgroLazo o preparar tu perfil de comprador.</p>
            <div class="mt-8 rounded-2xl bg-[#e9eddf] p-6 text-sm leading-6"><p class="font-semibold">¿Eres productor?</p><p class="mt-2 text-[#627057]">Un coordinador registra tu teléfono e identidad y te indica el canal de atención habilitado. No necesitas crear una contraseña aquí.</p></div>
        </section>
        <section aria-labelledby="login-title" class="rounded-3xl border border-[#dfe4da] bg-white p-7 shadow-sm sm:p-9">
            <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Tu espacio AgroLazo</p>
            <h2 id="login-title" class="mt-2 text-2xl font-semibold">Bienvenido de nuevo</h2>
            <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-5">
                @csrf
                <x-field name="email" label="Correo electrónico" type="email" autocomplete="username" required autofocus maxlength="255" />
                <x-field name="password" label="Contraseña" type="password" autocomplete="current-password" required maxlength="72" />
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="remember" value="1" class="size-4 accent-[#22583e]">Recordarme</label>
                    <a class="font-semibold underline underline-offset-4" href="{{ route('password.request') }}">Olvidé mi contraseña</a>
                </div>
                <button class="w-full rounded-xl bg-[#22583e] px-5 py-3.5 font-semibold text-white transition hover:bg-[#173f2c]" type="submit">Ingresar a mi espacio <span aria-hidden="true">→</span></button>
            </form>
            <p class="mt-6 text-center text-sm text-[#6f7d66]">¿Quieres comprar? <a class="font-semibold text-[#22583e] underline underline-offset-4" href="{{ route('register') }}">Crea tu cuenta</a></p>
        </section>
    </div>
</x-layout>
