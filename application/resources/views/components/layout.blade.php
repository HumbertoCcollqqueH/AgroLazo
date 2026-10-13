@props(['title' => 'AgroLazo'])
@php
    $navigationPermissions = auth()->user()?->permissionCodes() ?? collect();
    $support = app(\App\Services\ProductVisual::class)->support();
    $operationsLayout = $navigationPermissions->contains('view-operations')
        && ! request()->routeIs('marketplace.*', 'trace.show', 'trace.qr', 'api.traces.show');
@endphp
@if($operationsLayout)
    <x-operations-layout :title="$title" :permissions="$navigationPermissions">{{ $slot }}</x-operations-layout>
@else
<!DOCTYPE html>
<html lang="es" @env('local') data-local-performance @endenv>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#153f2d">
    <meta name="description" content="AgroLazo conecta el campo peruano con tu negocio. Explora lotes agrícolas, consulta precios y sigue tus compras en un solo lugar.">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>{{ $title }} · AgroLazo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ac-body font-sans antialiased">
    <a href="#contenido" class="ac-skip">Ir al contenido</a>
    <div class="ac-topbar"><div class="ac-container"><span><x-ac-icon name="leaf" />Del campo peruano a tu negocio</span><a href="{{ route('marketplace.index') }}#como-funciona">Compra con origen, compra con confianza <span aria-hidden="true">↗︎</span></a></div></div>
    <header class="ac-header">
        <div class="ac-container ac-header-inner">
            <a href="{{ route('home') }}" class="ac-brand" aria-label="AgroLazo, inicio"><span class="ac-brand-symbol"><x-ac-icon name="brand" /></span><span>Agro<span class="ac-brand-accent">Lazo</span><small>EL CAMPO NOS UNE</small></span></a>
            <button class="ac-menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="primary-navigation" hidden><x-ac-icon name="menu" /><span>Menú</span></button>
            <nav id="primary-navigation" aria-label="Navegación principal" class="ac-navigation">
                <a class="ac-nav-link {{ request()->routeIs('home', 'marketplace.*') ? 'is-active' : '' }}" href="{{ route('marketplace.index') }}">Explorar productos</a>
                <a class="ac-nav-link" href="{{ route('marketplace.index') }}#como-funciona">Cómo funciona</a>
                @auth
                    <a class="ac-nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}">{{ $navigationPermissions->contains('view-operations') ? 'Administración' : 'Mi panel' }}</a>
                    @if($navigationPermissions->contains('manage-commerce') || $navigationPermissions->contains('view-buyer-area'))<a class="ac-nav-link" href="{{ route('commerce.index') }}">{{ $navigationPermissions->contains('manage-commerce') ? 'Gestionar pedidos' : 'Mis compras' }}</a>@endif
                    <details class="ac-account-menu">
                        <summary><x-ac-icon name="user" />Mi cuenta <span aria-hidden="true">⌄</span></summary>
                        <div class="ac-account-dropdown">
                            <p>{{ auth()->user()->name }}</p>
                            @if($navigationPermissions->contains('view-operations') || $navigationPermissions->contains('view-buyer-area'))<a href="{{ route('reports.index') }}">Reportes</a>@endif
                            @if($navigationPermissions->contains('view-producers'))<a href="{{ route('producers.index') }}">Productores</a>@endif
                            @if($navigationPermissions->contains('view-offers'))<a href="{{ route('offers.index') }}">Ofertas</a>@endif
                            @if($navigationPermissions->contains('view-lots'))<a href="{{ route('lots.index') }}">Lotes</a>@endif
                            @if($navigationPermissions->contains('manage-messaging'))<a href="{{ route('messaging.index') }}">Mensajería</a>@endif
                            @if($navigationPermissions->contains('view-catalogs'))<a href="{{ route('catalog.index', 'products') }}">Catálogos</a>@endif
                            @if($navigationPermissions->contains('manage-users'))<a href="{{ route('admin.users.index') }}">Usuarios</a><a href="{{ route('whatsapp.connection') }}">Conexión WhatsApp</a>@endif
                            <a href="{{ route('account.edit') }}">Mi perfil</a>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Cerrar sesión</button></form>
                        </div>
                    </details>
                @else
                    <a class="ac-nav-login" href="{{ route('login') }}"><x-ac-icon name="user" />Ingresar</a>
                    <a class="ac-button ac-button--small" href="{{ route('register') }}">Crear cuenta <span aria-hidden="true">↗︎</span></a>
                @endauth
            </nav>
        </div>
    </header>
    <main id="contenido" class="ac-container ac-main {{ request()->routeIs('home', 'marketplace.index') ? 'ac-main--market' : '' }}">
        @if($navigationPermissions->contains('view-operations'))
            <section aria-label="Vista del equipo" class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[#c9d8cb] bg-[#edf3ec] p-5 text-sm">
                <div><p class="font-semibold text-[#153f2d]">{{ $navigationPermissions->contains('manage-users') ? 'Vista de administración' : 'Vista de coordinación' }} · {{ auth()->user()->name }}</p><p class="mt-1 text-[#536c5c]">Estás supervisando la tienda. Abre un lote para gestionar sus aportaciones, productores y acopio.</p></div>
                <div class="flex flex-wrap gap-4">@if($navigationPermissions->contains('view-lots'))<a class="font-semibold underline" href="{{ route('lots.index') }}">Gestionar lotes</a>@endif<a class="font-semibold underline" href="{{ route('dashboard') }}">Panel de operación →</a></div>
            </section>
        @endif
        @if(session('status'))<div role="status" class="mb-6 rounded-xl border border-[#c6daba] bg-[#edf5e8] p-4 text-sm text-[#345d35]">{{ session('status') }}</div>@endif
        @if($errors->any())<div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="font-semibold">Revisa los siguientes datos:</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main>
    <footer class="ac-footer" id="contacto">
        <div class="ac-container">
            <div class="ac-footer-top"><div><p class="ac-eyebrow">BUENOS PRODUCTOS. NUEVAS OPORTUNIDADES.</p><h2>Lo mejor empieza<br>en el campo.</h2></div><a class="ac-button ac-button--light" href="{{ route('marketplace.index') }}#catalogo">Encuentra tu próximo lote <x-ac-icon name="arrow" /></a></div>
            <div class="ac-footer-grid">
                <div class="ac-footer-about"><a class="ac-brand" href="{{ route('home') }}"><span class="ac-brand-symbol"><x-ac-icon name="brand" /></span><span>AgroLazo</span></a><p>Acercamos la producción agrícola peruana a quienes la necesitan. Origen, cantidades y condiciones en un solo lugar.</p><span class="ac-footer-country">Hecho para conectar al Perú <span aria-hidden="true">↗︎</span></span></div>
                <div><h3>Explora</h3><a href="{{ route('marketplace.index') }}#catalogo">Productos disponibles</a><a href="{{ route('marketplace.index') }}#categorias">Nuestro catálogo</a><a href="{{ route('marketplace.index') }}#como-funciona">Cómo comprar</a></div>
                <div><h3>Tu espacio</h3><a href="{{ route('dashboard') }}">Mi panel</a><a href="{{ route('commerce.index') }}">Mis compras</a><a href="{{ route('account.edit') }}">Mi perfil</a>@guest<a href="{{ route('register') }}">Crear una cuenta</a>@endguest</div>
                <div><h3>Estamos cerca</h3><a href="{{ route('marketplace.index') }}#preguntas">Preguntas frecuentes</a>@if($support['whatsapp_url'])<a href="{{ $support['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Conversemos por WhatsApp ↗︎</a>@endif @if($support['contact_email'])<a href="mailto:{{ $support['contact_email'] }}">{{ $support['contact_email'] }}</a>@endif<p>Consulta el estado de tu compra y los datos de coordinación desde tu pedido.</p></div>
            </div>
            <div class="ac-footer-bottom"><span>© {{ now()->year }} AgroLazo · Del campo a nuevas oportunidades</span><span>Fotografías referenciales · Disponibilidad sujeta a consulta</span>@env('local')<a href="{{ route('dev.status') }}">Estado del sistema</a>@endenv</div>
        </div>
    </footer>
    @if($support['whatsapp_url'])
        <a class="ac-contact-float" href="{{ $support['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Contáctanos por WhatsApp"><span>¿Te ayudamos?</span><x-ac-icon name="whatsapp" /></a>
    @else
        <details class="ac-contact-widget"><summary aria-label="Ayuda y contacto"><span>¿Te ayudamos?</span><x-ac-icon name="whatsapp" /></summary><div class="ac-contact-panel"><strong>Estamos para orientarte</strong><p>El canal público de WhatsApp estará disponible próximamente. Mientras tanto, encuentra respuestas sobre tus compras aquí.</p><a href="{{ route('marketplace.index') }}#preguntas">Consultar preguntas frecuentes →</a><a href="{{ route('commerce.index') }}">Revisar mis compras →</a>@if($support['contact_email'])<a href="mailto:{{ $support['contact_email'] }}">Escribir por correo →</a>@endif</div></details>
    @endif
    <div class="ac-toast" data-storefront-toast role="status" aria-live="polite" hidden></div>
</body>
</html>
@endif
