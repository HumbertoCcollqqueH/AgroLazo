@props(['title' => 'Mi espacio', 'permissions'])
@php
    $administrator = $permissions->contains('manage-users');
    $workspaceName = $administrator ? 'Administración' : 'Coordinación';
    $navigationGroups = [
        'Vista general' => [
            ['Inicio AgroLazo', 'marketplace.index', [], 'leaf', 'view-operations', false],
            ['Resumen', 'dashboard', [], 'grid', 'view-operations', request()->routeIs('dashboard')],
            ['Reportes', 'reports.index', [], 'chart', 'view-operations', request()->routeIs('reports.*') && request('report') !== 'audit'],
        ],
        'Operación' => [
            ['Conversaciones', 'coordination.index', [], 'users', 'view-operations', request()->routeIs('coordination.*')],
            ['Avisos', 'notifications.index', [], 'inbox', 'view-operations', request()->routeIs('notifications.*')],
            ['Productores', 'producers.index', [], 'users', 'view-producers', request()->routeIs('producers.*')],
            ['Ofertas y verificación', 'offers.index', [], 'shield', 'view-offers', request()->routeIs('offers.*')],
            ['Lotes y publicaciones', 'lots.index', [], 'box', 'view-lots', request()->routeIs('lots.*', 'trace.manage')],
            ['Pedidos y logística', 'commerce.index', [], 'truck', 'manage-commerce', request()->routeIs('commerce.*', 'trace.chain') && ! request()->routeIs('commerce.pricing*')],
            ['Cobros por revisar', 'payments.inbox', [], 'wallet', 'confirm-payments', request()->routeIs('payments.inbox')],
            ['Puntos de acopio', 'fulfillment.points', [], 'pin', 'manage-commerce', request()->routeIs('fulfillment.points*')],
            ['Mensajería', 'messaging.index', [], 'inbox', 'manage-messaging', request()->routeIs('messaging.*', 'dev.message-simulator')],
        ],
        'Administración' => [
            ['Usuarios y compradores', 'admin.users.index', [], 'user', 'manage-users', request()->routeIs('admin.users.*')],
            ['Precios de mercado', 'commerce.pricing', [], 'chart', 'manage-pricing', request()->routeIs('commerce.pricing*')],
            ['Métodos de pago', 'payments.methods', [], 'wallet', 'confirm-payments', request()->routeIs('payments.methods*')],
        ],
        'Configuración' => [
            ['Catálogos', 'catalog.index', ['catalog' => 'products'], 'document', 'view-catalogs', request()->routeIs('catalog.*')],
            ['Conexión WhatsApp', 'whatsapp.connection', [], 'whatsapp', 'manage-users', request()->routeIs('whatsapp.*')],
            ['Auditoría', 'reports.index', ['report' => 'audit'], 'clock', 'manage-users', request()->routeIs('reports.*') && request('report') === 'audit'],
        ],
    ];
    if (config('platform.internal_communication')) {
        foreach ($navigationGroups as $group => $links) {
            $navigationGroups[$group] = array_values(array_filter($links, fn ($link) => !in_array($link[1], ['messaging.index', 'whatsapp.connection'], true)));
        }
    }
    if (app()->environment(['local', 'testing']) && $administrator) {
        $navigationGroups['Configuración'][] = ['Estado del sistema', 'dev.status', [], 'settings', 'manage-users', request()->routeIs('dev.status')];
    }
    $navigationGroups = collect($navigationGroups)->map(fn ($links) => array_values(array_filter($links, fn ($link) => $permissions->contains($link[4]))))->filter(fn ($links) => count($links) > 0);
    $quickActions = collect([
        ['Crear usuario', 'admin.users.create', 'user', 'manage-users'],
        ['Registrar productor', 'producers.create', 'leaf', 'manage-producers'],
        ['Registrar oferta', 'offers.create', 'document', 'manage-offers'],
        ['Agrupar un lote', 'lots.create', 'box', 'manage-lots'],
    ])->filter(fn ($action) => $permissions->contains($action[3]));
    $catalogs = ['products' => 'Productos', 'categories' => 'Categorías', 'varieties' => 'Variedades', 'qualities' => 'Calidades', 'units' => 'Unidades', 'locations' => 'Localidades', 'conversions' => 'Equivalencias'];
    $currentSection = $navigationGroups->flatten(1)->first(fn ($link) => $link[5]);
    $pageLabel = request()->routeIs('dashboard') ? 'Resumen general' : $title;
@endphp
<!DOCTYPE html>
<html lang="es" @env('local') data-local-performance @endenv>
<head>
    <meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $administrator ? '#14243b' : '#123e42' }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>{{ $title }} · {{ $workspaceName }} · AgroLazo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="op-shell op-shell--{{ $administrator ? 'admin' : 'coordinator' }} font-sans antialiased">
    <a class="op-skip" href="#contenido">Ir al contenido</a>
    <aside class="op-sidebar" id="operations-navigation" aria-label="Espacio de {{ mb_strtolower($workspaceName) }}" data-operations-sidebar>
        <div class="op-sidebar-heading"><a class="op-brand" href="{{ route('marketplace.index') }}" aria-label="AgroLazo, página principal"><span class="op-brand-mark"><x-ac-icon name="brand" /></span><span>AgroLazo<small>{{ $administrator ? 'CENTRO DE CONTROL' : 'COORDINACIÓN DE CAMPO' }}</small></span></a><button type="button" class="op-menu-close" data-operations-close aria-label="Cerrar menú"><x-ac-icon name="close" /></button></div>
        <div class="op-sidebar-scroll">
            <div class="op-workspace-label"><span class="op-workspace-icon"><x-ac-icon :name="$administrator ? 'shield' : 'leaf'" /></span><div><strong>{{ $workspaceName }}</strong><small>{{ $administrator ? 'Gestión de la plataforma' : 'Productores y operación' }}</small></div><span class="op-workspace-dot" aria-hidden="true"></span></div>
            <nav aria-label="Navegación de {{ mb_strtolower($workspaceName) }}">
                @foreach($navigationGroups as $group => $links)
                    <div class="op-nav-group"><p>{{ $group === 'Configuración' && ! $administrator ? 'Consulta' : $group }}</p>
                        @foreach($links as [$label, $route, $parameters, $icon, $permission, $active])
                            <a class="op-nav-link {{ $active ? 'is-active' : '' }}" href="{{ route($route, $parameters) }}" @if($active) aria-current="page" @endif><x-ac-icon :name="$icon" /><span>{{ $label }}</span>@if($active)<span class="op-nav-indicator" aria-hidden="true"></span>@endif</a>
                            @if($route === 'catalog.index' && request()->routeIs('catalog.*'))
                                <div class="op-subnav" aria-label="Catálogos disponibles">@foreach($catalogs as $key => $name)<a href="{{ route('catalog.index', $key) }}" @if(request()->route('catalog') === $key) aria-current="page" @endif>{{ $name }}</a>@endforeach</div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </nav>
        </div>
        <div class="op-sidebar-bottom"><a href="{{ route('account.edit') }}" class="op-user"><span class="op-avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span><strong>{{ auth()->user()->name }}</strong><small>Mi perfil y seguridad</small></span><x-ac-icon name="chevron" /></a></div>
    </aside>
    <button type="button" class="op-menu-backdrop" data-operations-backdrop aria-label="Cerrar menú" tabindex="-1" hidden></button>
    <div class="op-workspace">
        <header class="op-topbar">
            <div class="op-topbar-context"><button type="button" class="op-menu-toggle" data-operations-toggle aria-expanded="false" aria-controls="operations-navigation" aria-label="Abrir menú de {{ mb_strtolower($workspaceName) }}" hidden><x-ac-icon name="menu" /><span>Menú</span></button><div><p>{{ $workspaceName }} <span aria-hidden="true">/</span> {{ $currentSection[0] ?? 'Mi cuenta' }}</p><strong>{{ $pageLabel }}</strong></div></div>
            <div class="op-topbar-actions">
                <a class="op-notice-link" href="{{ route('notifications.index') }}" aria-label="Abrir notificaciones"><x-ac-icon name="inbox" /><span>Avisos</span><span class="pp-count" data-unread-count>{{ auth()->user()->unreadNotifications()->count() }}</span></a>
                <button type="button" class="op-search-trigger" data-operations-search-open aria-label="Buscar módulo" hidden><x-ac-icon name="search" /><span>Buscar módulo</span><kbd>Ctrl K</kbd></button>
                <a class="op-store-link" href="{{ route('marketplace.index') }}"><x-ac-icon name="leaf" /><span>Inicio AgroLazo</span></a>
                @if($quickActions->isNotEmpty())<details class="op-create-menu"><summary aria-label="Acciones de creación"><x-ac-icon name="plus" /><span>Crear</span></summary><div class="op-create-dropdown">@foreach($quickActions as [$label, $route, $icon, $permission])<a href="{{ route($route) }}"><x-ac-icon :name="$icon" />{{ $label }}</a>@endforeach</div></details>@endif
                @if(str_contains(request()->userAgent() ?? '', 'AgroLazoAndroid/'))
                    <x-native-account-menu />
                @else
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="op-logout" aria-label="Cerrar sesión" title="Cerrar sesión"><x-ac-icon name="logout" /></button></form>
                @endif
            </div>
        </header>
        <main id="contenido" class="op-main">
            @if(session('status'))<div role="status" class="op-flash op-flash--success"><x-ac-icon name="check" /><p>{{ session('status') }}</p></div>@endif
            @if($errors->any())<div role="alert" class="op-flash op-flash--error"><div><strong>Revisa los siguientes datos</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
            {{ $slot }}
        </main>
        <footer class="op-footer"><span>AgroLazo <span aria-hidden="true">·</span> {{ $workspaceName }}</span><span>{{ now()->timezone('America/Lima')->translatedFormat('d M Y') }} <span aria-hidden="true">·</span> Hora de Perú</span></footer>
    </div>
    <x-operations-mobile-nav />
    <dialog class="op-command-dialog" id="operations-module-search" aria-labelledby="operations-search-title">
        <div class="op-command-heading"><div><h2 id="operations-search-title">¿A dónde quieres ir?</h2><p>Encuentra una sección de tu espacio.</p></div><form method="dialog"><button aria-label="Cerrar buscador"><x-ac-icon name="close" /></button></form></div>
        <div class="op-command-input"><x-ac-icon name="search" /><label class="sr-only" for="operations-module-query">Buscar una sección</label><input id="operations-module-query" data-operations-module-query type="search" placeholder="Productores, ofertas, reportes…" autocomplete="off"></div>
        <nav class="op-command-results" aria-label="Secciones disponibles">
            @foreach($navigationGroups as $group => $links)@foreach($links as [$label, $route, $parameters, $icon, $permission, $active])<a href="{{ route($route, $parameters) }}" data-operations-module><x-ac-icon :name="$icon" /><span>{{ $label }}<small>{{ $group }}</small></span><x-ac-icon name="arrow" /></a>@endforeach @endforeach
        </nav>
        <p class="op-command-empty" data-operations-search-empty role="status" hidden>No encontramos esa sección. Prueba con otro nombre.</p>
        <p class="op-command-hint"><kbd>Esc</kbd> para cerrar <span>Usa Tab para recorrer los resultados</span></p>
    </dialog>
<x-platform-updates />
</body>
</html>
