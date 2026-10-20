@props(['title' => 'Mi producción'])
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#153f2d"><meta name="robots" content="noindex,nofollow"><title>{{ $title }} · AgroLazo</title><link rel="icon" href="{{ asset('favicon.svg') }}">@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="pp-body">
    <a class="ac-skip" href="#contenido">Ir al contenido</a>
    <header class="pp-header"><a class="ac-brand" href="{{ route('producer.home') }}"><span class="ac-brand-symbol"><x-ac-icon name="brand" /></span><span>AgroLazo<small>MI CAMPO, MIS OPORTUNIDADES</small></span></a>@if(str_contains(request()->userAgent() ?? '', 'AgroLazoAndroid/'))<x-native-account-menu />@else<a class="pp-account" href="{{ route('account.edit') }}"><x-ac-icon name="user" /><span>{{ auth()->user()->name }}</span></a>@endif</header>
    <div class="pp-shell"><aside class="pp-sidebar"><p class="pp-kicker">ESPACIO DEL PRODUCTOR</p><nav aria-label="Mi producción">
        @foreach([['producer.home','leaf','Mi producción'],['producer.offers.create','plus','Nueva oferta'],['producer.sales','truck','Ventas y recojos'],['coordination.index','users','Conversaciones'],['notifications.index','inbox','Avisos'],['producer.profile','pin','Mi ficha y ubicación']] as [$route,$icon,$label])
        <a href="{{ route($route) }}" @class(['is-active' => request()->routeIs($route)])><x-ac-icon :name="$icon" />{{ $label }}@if($route === 'notifications.index')<span class="pp-count" data-unread-count>{{ auth()->user()->unreadNotifications()->count() ?: '' }}</span>@endif</a>
        @endforeach
    </nav><div class="pp-sidebar-note"><x-ac-icon name="shield" /><strong>Todo en tu cuenta</strong><p>Revisa precios, recibe avisos y conversa con el equipo sin salir de AgroLazo.</p></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="pp-text-button">Cerrar sesión</button></form></aside>
    <main id="contenido" class="pp-main">@if(session('status'))<div class="pp-feedback" role="status">{{ session('status') }}</div>@endif @if($errors->any())<div class="pp-feedback pp-feedback--error" role="alert"><strong>Revisa estos datos</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif {{ $slot }}<footer class="pp-footer">AgroLazo · El campo nos une <a href="{{ route('coordination.index') }}">Hablar con coordinación</a></footer></main></div>
    <nav class="pp-bottom" aria-label="Navegación móvil"><a href="{{ route('producer.home') }}"><x-ac-icon name="leaf" />Inicio</a><a href="{{ route('coordination.index') }}"><x-ac-icon name="users" />Chats</a><a href="{{ route('producer.sales') }}"><x-ac-icon name="truck" />Ventas</a><a href="{{ route('notifications.index') }}"><x-ac-icon name="inbox" /><span>Avisos <span data-unread-count>{{ auth()->user()->unreadNotifications()->count() ?: '' }}</span></span></a><a href="{{ route('producer.profile') }}"><x-ac-icon name="user" />Ficha</a></nav>
<x-platform-updates />
</body></html>


