<nav class="op-mobile-nav" aria-label="Secciones de la aplicación">
    <a href="{{ route('marketplace.index') }}" @if(request()->routeIs('marketplace.*')) aria-current="page" @endif><x-ac-icon name="leaf" /><span>Inicio</span></a>
    <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif><x-ac-icon name="grid" /><span>Mi panel</span></a>
    @can('manage-commerce')<a href="{{ route('commerce.index') }}" @if(request()->routeIs('commerce.*')) aria-current="page" @endif><x-ac-icon name="box" /><span>Pedidos</span></a>@endcan
    <a href="{{ route('coordination.index') }}" @if(request()->routeIs('coordination.*')) aria-current="page" @endif><x-ac-icon name="users" /><span>Chats</span></a>
    <a href="{{ route('notifications.index') }}" @if(request()->routeIs('notifications.*')) aria-current="page" @endif><x-ac-icon name="inbox" /><span>Avisos <b data-unread-count>{{ auth()->user()->unreadNotifications()->count() ?: '' }}</b></span></a>
</nav>

