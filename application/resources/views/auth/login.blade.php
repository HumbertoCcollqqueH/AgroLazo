<x-layout title="Ingresar">
    @php($nativeApp = str_contains(request()->userAgent() ?? '', 'AgroLazoAndroid/'))
    <div class="auth-login-grid grid gap-10 lg:grid-cols-[1.1fr_1fr] lg:items-center">
        <section class="py-4 lg:pr-12">
            <p class="text-xs font-bold uppercase tracking-[.2em] text-[#65845b]">Comercio agrícola · Puno, Perú</p>
            <h1 class="mt-5 text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Cultivamos vínculos.<br><span class="text-[#628456]">Creamos oportunidades.</span></h1>
            <p class="mt-6 max-w-lg leading-7 text-[#6a7762]">Tu producción, tus compras y tu equipo, conectados en un solo lugar.</p>
            <div class="mt-8 rounded-2xl bg-[#e9eddf] p-6 text-sm leading-6"><p class="font-semibold">¿Eres productor?</p><p class="mt-2 text-[#627057]">Crea tu cuenta, completa tu ficha y envía tus ofertas desde AgroLazo. Coordinación verifica tus datos antes de publicar.</p><a class="pp-link" href="{{ route('producer.register') }}">Crear mi cuenta de productor →</a></div>
        </section>
        <section aria-labelledby="login-title" class="auth-login-form rounded-3xl border border-[#dfe4da] bg-white p-7 shadow-sm sm:p-9">
            <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Tu espacio AgroLazo</p>
            <h2 id="login-title" class="mt-2 text-2xl font-semibold">Bienvenido de nuevo</h2>
            @if($nativeApp)
                <p class="mt-3 text-sm leading-6 text-[#627057]">Entra una vez y continúa donde lo dejaste. Tu sesión permanece iniciada en este teléfono hasta que cierres sesión o sea necesario verificar tu acceso.</p>
                <button type="button" data-native-autofill hidden class="mt-5 w-full rounded-xl border border-[#dfe4da] px-4 py-3 text-sm font-semibold text-[#22583e]">Autocompletar acceso</button>
                <p data-native-autofill-help hidden class="mt-2 text-xs leading-5 text-[#627057]">Usa las credenciales guardadas en el gestor de contraseñas de tu teléfono.</p>
            @endif
            <form method="POST" action="{{ route('login') }}" autocomplete="on" class="mt-7 space-y-5">
                @csrf
                <x-field name="email" label="Correo electrónico" type="email" autocomplete="username" required autofocus maxlength="255" />
                <x-field name="password" label="Contraseña" type="password" autocomplete="current-password" required maxlength="72" />
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    @if($nativeApp)
                        <input type="hidden" name="remember" value="1">
                        <label class="flex items-center gap-2"><input type="checkbox" name="save_access" data-native-remember value="1" checked class="size-4 accent-[#22583e]" aria-describedby="remember-access-help">Recordarme</label>
                    @else
                        <label class="flex items-center gap-2"><input type="checkbox" name="remember" value="1" class="size-4 accent-[#22583e]">Recordarme</label>
                    @endif
                    <a class="font-semibold underline underline-offset-4" href="{{ route('password.request') }}">Olvidé mi contraseña</a>
                </div>
                @if($nativeApp)
                    <p id="remember-access-help" class="text-xs leading-5 text-[#627057]">Solicitar guardar correo y contraseña en el gestor de Android al ingresar. Acepta su confirmación para autocompletar la próxima vez. Tu sesión seguirá iniciada aunque desmarques esta opción.</p>
                @endif
                <button class="w-full rounded-xl bg-[#22583e] px-5 py-3.5 font-semibold text-white transition hover:bg-[#173f2c]" type="submit">Ingresar a mi espacio <span aria-hidden="true">→</span></button>
            </form>
            <p class="mt-6 text-center text-sm text-[#6f7d66]">¿Primera vez en AgroLazo? <a class="font-semibold text-[#22583e] underline underline-offset-4" href="{{ route('register') }}">Elige tu cuenta</a></p>
        </section>
    </div>
</x-layout>
