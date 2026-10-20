@php
    $counts = $operationsDashboard['counts'];
    $modeLabel = config('commerce.show_test_labels') && $operationsDashboard['data'] === 'synthetic' ? 'Operaciones de prueba' : 'Operaciones';
    $orderLabels = ['confirmed' => 'Confirmada', 'collecting' => 'En acopio', 'ready' => 'Lista para despacho', 'in_transit' => 'En camino', 'delivered' => 'Entregada', 'closed' => 'Cerrada', 'cancelled' => 'Cancelada'];
    $attentionCards = [
        ['permission' => 'manage-offers', 'count' => $counts['offers_pending'], 'title' => 'Ofertas por verificar', 'description' => 'Revisa cantidad, calidad y condiciones.', 'url' => route('offers.index', ['status' => 'pending_verification']), 'icon' => 'box'],
        ['permission' => 'verify-producers', 'count' => $counts['producers_pending'], 'title' => 'Productores por verificar', 'description' => 'Confirma sus datos y producción.', 'url' => route('producers.index', ['status' => 'pending_verification']), 'icon' => 'user'],
        ['permission' => 'approve-buyers', 'count' => $counts['buyers_pending'], 'title' => 'Compradores por aprobar', 'description' => 'Habilita el acceso a las compras.', 'url' => route('admin.users.index', ['role' => 'buyer', 'buyer_status' => 'pending', 'active' => '1']), 'icon' => 'shield'],
        ['permission' => 'manage-messaging', 'count' => $counts['conversations_attention'], 'title' => 'Conversaciones por atender', 'description' => 'Continúa las solicitudes con asistencia.', 'url' => route('messaging.index'), 'icon' => 'whatsapp'],
    ];
    $modules = [
        ['permission' => 'manage-users', 'title' => 'Usuarios y accesos', 'description' => 'Crea cuentas, asigna roles y autoriza compradores.', 'icon' => 'users', 'links' => [['label' => 'Gestionar usuarios', 'route' => 'admin.users.index', 'parameters' => []], ['label' => 'Crear usuario', 'route' => 'admin.users.create', 'parameters' => []]]],
        ['permission' => 'manage-pricing', 'title' => 'Precios y mercado', 'description' => 'Consulta referencias, fuentes y reglas de cotización.', 'icon' => 'chart', 'links' => [['label' => 'Administrar precios', 'route' => 'commerce.pricing', 'parameters' => []]]],
        ['permission' => 'confirm-payments', 'title' => 'Cobros y liquidaciones', 'description' => 'Configura medios de pago y revisa el cierre de cada orden.', 'icon' => 'wallet', 'links' => [['label' => 'Medios de pago', 'route' => 'payments.methods', 'parameters' => []], ['label' => 'Revisar órdenes', 'route' => 'commerce.index', 'parameters' => []]]],
        ['permission' => 'view-catalogs', 'title' => 'Catálogos del sistema', 'description' => 'Productos, variedades, calidades y equivalencias.', 'icon' => 'leaf', 'links' => [['label' => 'Productos', 'route' => 'catalog.index', 'parameters' => ['catalog' => 'products']], ['label' => 'Variedades', 'route' => 'catalog.index', 'parameters' => ['catalog' => 'varieties']], ['label' => 'Localidades', 'route' => 'catalog.index', 'parameters' => ['catalog' => 'locations']]]],
        ['permission' => 'manage-commerce', 'title' => 'Acopio y logística', 'description' => 'Organiza puntos de recepción, capacidad y entregas.', 'icon' => 'truck', 'links' => [['label' => 'Puntos de acopio', 'route' => 'fulfillment.points', 'parameters' => []], ['label' => 'Órdenes y entregas', 'route' => 'commerce.index', 'parameters' => []]]],
        ['permission' => 'manage-users', 'title' => 'WhatsApp y automatización', 'description' => 'Gestiona la conexión y los mensajes al productor.', 'icon' => 'whatsapp', 'links' => [['label' => 'Configurar WhatsApp', 'route' => 'whatsapp.connection', 'parameters' => []], ['label' => 'Conversaciones', 'route' => 'messaging.index', 'parameters' => []]]],
    ];
    $moduleRoutePermissions = ['admin.users.index' => 'manage-users', 'admin.users.create' => 'manage-users', 'commerce.pricing' => 'manage-pricing', 'payments.methods' => 'confirm-payments', 'commerce.index' => 'manage-commerce', 'catalog.index' => 'view-catalogs', 'fulfillment.points' => 'manage-commerce', 'whatsapp.connection' => 'manage-users', 'messaging.index' => 'manage-messaging'];
    if (config('platform.internal_communication')) {
        $pendingThreads = \App\Models\CoordinationThread::where('status', 'open')->where(fn ($q) => $q->whereNull('team_seen_at')->orWhereColumn('updated_at', '>', 'team_seen_at'))->count();
        $attentionCards[3] = ['permission' => 'view-operations', 'count' => $pendingThreads, 'title' => 'Conversaciones por atender', 'description' => 'Atiende a productores y compradores dentro de AgroLazo.', 'url' => route('coordination.index'), 'icon' => 'users'];
        $modules[5] = ['permission' => 'view-operations', 'title' => 'Conversaciones', 'description' => 'Conversaciones privadas con el contexto de cada persona.', 'icon' => 'users', 'links' => [['label' => 'Abrir coordinación', 'route' => 'coordination.index', 'parameters' => []]]];
        $moduleRoutePermissions['coordination.index'] = 'view-operations';
    }
@endphp
<div class="op-dash op-dash--admin">
    <header class="op-dash-header">
        <div>
            <p class="op-dash-eyebrow">ADMINISTRACIÓN / VISTA GENERAL</p>
            <h1>Panel de administración</h1>
            <p>Hola, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}. Tu equipo, la operación y la configuración en un solo lugar.</p>
        </div>
        <div class="op-dash-actions">
            @if($displayPermissions->contains('manage-offers'))
                <a class="op-dash-button op-dash-button--outline" href="{{ route('offers.index', ['status' => 'pending_verification']) }}"><x-ac-icon name="shield" /> Revisar ofertas</a>
            @endif
            <a class="op-dash-button" href="{{ route('admin.users.create') }}"><x-ac-icon name="plus" /> Crear usuario</a>
        </div>
    </header>

    <section class="op-dash-overview" aria-labelledby="admin-overview-title">
        <div class="op-dash-overview-heading"><h2 id="admin-overview-title">El estado de tu plataforma</h2><span>Datos actuales del sistema</span></div>
        <dl class="op-dash-kpis">
            <div><dt><x-ac-icon name="users" /> Usuarios activos</dt><dd>{{ number_format($counts['users_active']) }}</dd><dd class="op-dash-kpi-caption"><a href="{{ route('admin.users.index', ['active' => '1']) }}">Administrar accesos <x-ac-icon name="arrow" /></a></dd></div>
            @if($displayPermissions->contains('view-producers'))
                <div><dt><x-ac-icon name="leaf" /> Productores registrados</dt><dd>{{ number_format($counts['producers_total']) }}</dd><dd class="op-dash-kpi-caption"><a href="{{ route('producers.index') }}">Ver productores <x-ac-icon name="arrow" /></a></dd></div>
            @endif
            @if($displayPermissions->contains('view-offers'))
                <div><dt><x-ac-icon name="shield" /> Ofertas verificadas</dt><dd>{{ number_format($counts['offers_verified']) }}</dd><dd class="op-dash-kpi-caption"><a href="{{ route('offers.index', ['status' => 'verified']) }}">Revisar publicación <x-ac-icon name="arrow" /></a></dd></div>
            @endif
            @if($displayPermissions->contains('view-lots'))
                <div><dt><x-ac-icon name="box" /> Lotes publicados</dt><dd>{{ number_format($counts['lots_published']) }}</dd><dd class="op-dash-kpi-caption"><a href="{{ route('lots.index', ['status' => 'published']) }}">Gestionar inventario <x-ac-icon name="arrow" /></a></dd></div>
            @endif
        </dl>
    </section>

    <section class="op-dash-section" aria-labelledby="admin-attention-title">
        <div class="op-dash-section-heading"><div><p class="op-dash-eyebrow">BANDEJA DE REVISIÓN</p><h2 id="admin-attention-title">Requiere tu atención</h2></div><span class="op-dash-caption">Pendientes de todos los períodos</span></div>
        <div class="op-dash-attention-grid">
            @foreach($attentionCards as $card)
                @if($displayPermissions->contains($card['permission']))
                    <a href="{{ $card['url'] }}" class="op-dash-attention {{ $card['count'] > 0 ? 'op-dash-attention--pending' : '' }}">
                        <div class="op-dash-attention-top"><span class="op-dash-icon-box"><x-ac-icon :name="$card['icon']" /></span><strong>{{ $card['count'] }}</strong></div>
                        <h3>{{ $card['title'] }}</h3><p>{{ $card['description'] }}</p>
                        <span class="op-dash-card-link">{{ $card['count'] > 0 ? 'Abrir pendientes' : 'Consultar registros' }} <x-ac-icon name="arrow" /></span>
                    </a>
                @endif
            @endforeach
        </div>
    </section>

    <div class="op-dash-main-grid">
        <section class="op-dash-panel" aria-labelledby="admin-orders-title">
            <div class="op-dash-panel-heading"><div><p class="op-dash-eyebrow">SEGUIMIENTO COMERCIAL</p><h2 id="admin-orders-title">Órdenes recientes</h2></div>@if($displayPermissions->contains('manage-commerce'))<a class="op-dash-text-link" href="{{ route('commerce.index') }}">Ver todas <x-ac-icon name="arrow" /></a>@endif</div>
            <div class="op-dash-order-context"><span class="op-dash-tag"><span aria-hidden="true"></span>{{ $modeLabel }}</span><p>{{ $counts['orders_active'] }} órdenes activas · {{ $counts['payments_pending'] }} pagos por revisar</p></div>
            @if($operationsDashboard['recentOrders']->isNotEmpty() && $displayPermissions->contains('manage-commerce'))
                <div class="op-dash-table-wrap">
                    <table class="op-dash-table">
                        <thead><tr><th>Orden / comprador</th><th>Estado</th><th class="op-dash-align-right">Importe</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                        <tbody>
                            @foreach($operationsDashboard['recentOrders'] as $order)
                                <tr>
                                    <td><a class="op-dash-order-code" href="{{ route('commerce.orders.show', $order) }}">{{ $order->code }}</a><span class="op-dash-table-secondary">{{ $order->buyer?->user?->name ?? 'Comprador' }}</span></td>
                                    <td><span class="op-dash-status op-dash-status--{{ in_array($order->status, ['closed', 'delivered']) ? 'complete' : 'active' }}">{{ $orderLabels[$order->status] ?? $order->status }}</span></td>
                                    <td class="op-dash-align-right op-dash-amount">S/ {{ \App\Services\Money::format($order->total_minor) }}<span class="op-dash-table-secondary">{{ $order->confirmed_at?->timezone('America/Lima')->format('d/m/Y') }}</span></td>
                                    <td><a class="op-dash-row-arrow" href="{{ route('commerce.orders.show', $order) }}" aria-label="Abrir orden {{ $order->code }}"><x-ac-icon name="arrow" /></a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="op-dash-empty"><span class="op-dash-empty-icon"><x-ac-icon name="box" /></span><h3>Aún no hay órdenes para mostrar</h3><p>Las compras confirmadas aparecerán aquí para seguir su acopio, entrega y cobro.</p>@if($displayPermissions->contains('view-lots'))<a class="op-dash-text-link" href="{{ route('lots.index') }}">Revisar los lotes <x-ac-icon name="arrow" /></a>@endif</div>
            @endif
            @if($reportSummary)
                <div class="op-dash-period-summary">
                    <div><span>Contratado en el período</span><strong>S/ {{ \App\Services\Money::format($reportSummary['metrics']['total_minor']) }}</strong></div>
                    <a class="op-dash-text-link" href="{{ route('reports.index', array_filter($reportSummary['filters'], fn ($value) => $value !== null)) }}">Ver reportes <x-ac-icon name="chart" /></a>
                    <p>{{ \Carbon\CarbonImmutable::parse($reportSummary['filters']['from'])->format('d/m/Y') }} – {{ \Carbon\CarbonImmutable::parse($reportSummary['filters']['to'])->format('d/m/Y') }} · {{ $modeLabel }}</p>
                </div>
            @endif
        </section>

        <aside class="op-dash-control" aria-labelledby="admin-control-title">
            <div class="op-dash-control-heading"><span class="op-dash-control-icon"><x-ac-icon name="settings" /></span><p class="op-dash-eyebrow">CENTRO DE CONTROL</p><h2 id="admin-control-title">Todo bajo tu gestión</h2><p>Accesos directos a los puntos clave de la operación.</p></div>
            @if(config('platform.internal_communication'))
                <a class="op-dash-control-row" href="{{ route('coordination.index') }}"><span><x-ac-icon name="users" /><span>Conversaciones<small>Dentro de la web y la app</small></span></span><x-ac-icon name="chevron" /></a>
            @elseif($operationsDashboard['whatsapp'])
                <a class="op-dash-control-row" href="{{ route('whatsapp.connection') }}"><span><x-ac-icon name="whatsapp" /><span>WhatsApp<small>{{ $operationsDashboard['whatsapp']['automation_enabled'] ? 'Automatización habilitada' : 'Automatización pausada' }}</small></span></span><x-ac-icon name="chevron" /></a>
            @endif
            @if($displayPermissions->contains('manage-pricing'))<a class="op-dash-control-row" href="{{ route('commerce.pricing') }}"><span><x-ac-icon name="chart" /><span>Precios de mercado<small>Referencias y reglas</small></span></span><x-ac-icon name="chevron" /></a>@endif
            @if($displayPermissions->contains('manage-commerce'))<a class="op-dash-control-row" href="{{ route('fulfillment.points') }}"><span><x-ac-icon name="pin" /><span>Puntos de acopio<small>Ubicación, capacidad y horarios</small></span></span><x-ac-icon name="chevron" /></a>@endif
            @if($displayPermissions->contains('manage-traceability') && $displayPermissions->contains('manage-commerce'))<a class="op-dash-control-row" href="{{ route('commerce.index') }}"><span><x-ac-icon name="shield" /><span>Trazabilidad<small>Consulta la cadena de cada orden</small></span></span><x-ac-icon name="chevron" /></a>@endif
            <a class="op-dash-control-market" href="{{ route('marketplace.index') }}">Explorar el catálogo público <x-ac-icon name="arrow" /></a>
        </aside>
    </div>

    <section class="op-dash-section" aria-labelledby="admin-modules-title">
        <div class="op-dash-section-heading"><div><p class="op-dash-eyebrow">ADMINISTRA AGROLAZO</p><h2 id="admin-modules-title">Configuración y herramientas</h2></div><span class="op-dash-caption">Organizadas por área de trabajo</span></div>
        <div class="op-dash-modules">
            @foreach($modules as $module)
                @if($displayPermissions->contains($module['permission']))
                    <article class="op-dash-module">
                        <span class="op-dash-icon-box"><x-ac-icon :name="$module['icon']" /></span>
                        <div>
                            <h3>{{ $module['title'] }}</h3><p>{{ $module['description'] }}</p>
                            <div class="op-dash-module-links">
                                @foreach($module['links'] as $link)
                                    @if($displayPermissions->contains($moduleRoutePermissions[$link['route']]))
                                        <a href="{{ route($link['route'], $link['parameters']) }}">{{ $link['label'] }} <x-ac-icon name="chevron" /></a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </article>
                @endif
            @endforeach
        </div>
    </section>

    @if($operationsDashboard['recentActivity']->isNotEmpty() && $displayPermissions->contains('manage-commerce'))
        <section class="op-dash-panel op-dash-activity" aria-labelledby="admin-activity-title">
            <div class="op-dash-panel-heading"><div><p class="op-dash-eyebrow">HISTORIAL DE LA OPERACIÓN</p><h2 id="admin-activity-title">Últimos movimientos</h2></div><span class="op-dash-caption">{{ $modeLabel }}</span></div>
            <ol>@foreach($operationsDashboard['recentActivity'] as $activity)<li><span class="op-dash-activity-dot" aria-hidden="true"></span><div><a href="{{ $activity['url'] }}">{{ $activity['label'] }}</a><p>{{ $activity['order_code'] }}</p></div><time datetime="{{ $activity['occurred_at']->toIso8601String() }}">{{ $activity['occurred_at']->timezone('America/Lima')->format('d/m · H:i') }}</time></li>@endforeach</ol>
        </section>
    @endif
</div>

