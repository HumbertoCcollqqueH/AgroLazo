<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>AgroLazo · Espacio de desarrollo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f6f5f0] font-sans text-[#243c32] antialiased">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:z-10 focus:bg-white focus:p-4">Ir al contenido</a>
    <header class="border-b border-[#dfe4da] bg-white/80">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
            <a href="{{ route('home') }}" class="flex items-center gap-3 text-xl font-bold tracking-tight">
                <span class="grid size-10 place-items-center rounded-xl bg-[#22583e] text-white" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 18c0-8 4-12 14-12 0 10-5 14-12 12m0 0L17 8M6 19l-2 2"/></svg>
                </span>
                AgroLazo
            </a>
            <div class="flex flex-wrap items-center gap-4"><a href="{{ route('marketplace.index') }}" class="text-sm font-semibold underline underline-offset-4">Marketplace</a><a href="{{ route('login') }}" class="rounded-lg bg-[#22583e] px-4 py-2 text-sm font-semibold text-white">Ingresar al sistema →</a></div>
        </div>
    </header>

    <main id="contenido" class="mx-auto max-w-6xl px-6 py-10 sm:py-14">
        <div class="mb-10 grid gap-8 lg:grid-cols-[1.5fr_1fr] lg:items-end">
            <div>
                <p class="mb-4 text-xs font-bold uppercase tracking-[.2em] text-[#568263]">Puno, Perú · Construyendo AgroLazo</p>
                <h1 class="max-w-2xl text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Pequeñas cosechas.<br><span class="text-[#568263]">Más oportunidades.</span></h1>
                <p class="mt-5 max-w-xl text-base leading-7 text-[#647168]">Este es tu espacio para observar el desarrollo y comprobar que la base del sistema funciona. Los módulos comerciales se irán habilitando por fases.</p>
            </div>
            <div class="rounded-2xl bg-[#e9eddf] p-6">
                <p class="text-xs font-bold uppercase tracking-widest text-[#627057]">Última fase habilitada</p>
                <p class="mt-3 text-xl font-semibold">Reportes, seguridad y pruebas integrales</p>
                <p class="mt-2 text-sm leading-6 text-[#627057]">{{ config('agroconecta.current_phase') }}</p>
                <a href="#avance" class="mt-4 inline-flex items-center gap-2 text-sm font-semibold underline decoration-[#a9b695] underline-offset-4">Ver el avance <span aria-hidden="true">↗︎</span></a>
            </div>
        </div>

        @php($healthy = collect($checks)->every(fn ($check) => $check['healthy']))
        <section aria-labelledby="servicios" class="rounded-3xl border border-[#dfe4da] bg-white p-6 shadow-sm sm:p-8">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 id="servicios" class="text-xl font-semibold">Estado de los servicios</h2>
                    <p class="mt-1 text-sm text-[#738077]">Comprobación real · {{ $checkedAt }} · hora de Perú</p>
                </div>
                <span class="rounded-full px-3 py-1.5 text-xs font-semibold {{ $healthy ? 'bg-[#e9f3e7] text-[#346139]' : 'bg-amber-50 text-amber-800' }}">{{ $healthy ? 'Entorno operativo' : 'Requiere revisión' }}</span>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($checks as $check)
                    <article class="rounded-2xl border border-[#e4e8de] bg-[#fbfcf8] p-5">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="font-semibold">{{ $check['label'] }}</h3>
                            <span aria-hidden="true" class="size-2.5 rounded-full {{ $check['healthy'] ? 'bg-[#649c59]' : 'bg-amber-500' }}"></span>
                        </div>
                        <p class="text-sm font-medium {{ $check['healthy'] ? 'text-[#4e7746]' : 'text-amber-800' }}">{{ $check['healthy'] ? 'Disponible' : 'Pendiente de señal' }}</p>
                        <p class="mt-2 text-xs leading-5 text-[#76816f]">{{ $check['detail'] }}</p>
                    </article>
                @endforeach
            </div>
            <a href="{{ route('dev.status') }}" class="mt-6 inline-flex items-center rounded-lg border border-[#d3ddcc] px-4 py-2.5 text-sm font-semibold transition hover:bg-[#f1f5ec] focus:outline-2 focus:outline-offset-2 focus:outline-[#22583e]">Actualizar comprobación</a>
        </section>

        <div class="mt-8 grid gap-8 lg:grid-cols-[1.4fr_1fr]">
            <section id="avance" aria-labelledby="avance-title" class="rounded-3xl border border-[#dfe4da] bg-white p-6 sm:p-8">
                <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Del diseño a la operación</p>
                <h2 id="avance-title" class="mt-2 text-xl font-semibold">El camino del proyecto</h2>
                <ol class="mt-6 divide-y divide-[#edf0e8]">
                    @foreach($milestones as $milestone)
                        <li class="flex items-center gap-4 py-4">
                            <span class="min-w-12 text-xs font-semibold tabular-nums text-[#829177]">{{ $milestone['phase'] }}</span>
                            <div class="flex-1"><p class="text-sm font-semibold">{{ $milestone['name'] }}</p><p class="mt-1 text-xs text-[#788373]">{{ $milestone['status'] }}</p></div>
                            @if($milestone['complete'])<span class="text-[#55804c]" aria-label="Completado">✓</span>@endif
                        </li>
                    @endforeach
                </ol>
            </section>
            <aside class="space-y-6">
                <section class="rounded-3xl bg-[#254f3b] p-7 text-white">
                    <p class="text-xs font-bold uppercase tracking-widest text-[#bed0af]">Canal elegido</p>
                    <h2 class="mt-3 text-2xl font-semibold">WhatsApp oficial<br>a través de Evolution</h2>
                    <p class="mt-4 text-sm leading-6 text-[#d0ddcb]">El simulador ya permite probar el registro y la confirmación de ofertas. El adaptador de Meta está implementado; falta configurar la cuenta y comprobar la recepción y entrega reales.</p>
                    <span class="mt-5 inline-block rounded-full border border-[#708c72] px-3 py-1.5 text-xs text-[#dce6d6]">Conexión real pendiente</span>
                </section>
                <section class="rounded-3xl border border-[#dfe4da] p-7">
                    <h2 class="font-semibold">¿Qué puedes comprobar hoy?</h2>
                    <p class="mt-3 text-sm leading-6 text-[#677461]">Prueba el recorrido desde la oferta hasta la compra, acopio, entrega, cobro, liquidación y QR. Revisa sus indicadores y exporta reportes a Excel, CSV o PDF. El recorrido integral de 220 kg y la restauración del respaldo ya fueron comprobados localmente. WhatsApp oficial y SMS requieren validación externa.</p>
                    <a href="{{ route('reports.index') }}" class="mt-4 inline-flex text-sm font-semibold underline underline-offset-4">Abrir dashboard y reportes →</a>
                    <p class="mt-4 text-xs leading-5 text-[#7f8b77]">Esta pantalla muestra el avance del desarrollo y comprueba servicios reales. La ficha de prueba de interfaz está identificada como sintética.</p>
                </section>
            </aside>
        </div>
        <footer class="mt-10 flex flex-wrap justify-between gap-3 border-t border-[#dfe4da] pt-6 text-xs text-[#7b8673]"><span>AgroLazo · Infraestructura comercial agrícola</span><span>Desarrollo local · Datos privados protegidos</span></footer>
    </main>
</body>
</html>
