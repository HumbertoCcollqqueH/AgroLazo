@php
    $counts = $operationsDashboard['counts'];
    $modeLabel = config('commerce.show_test_labels') && $operationsDashboard['data'] === 'synthetic' ? 'Operaciones de prueba' : 'Operaciones';
    $orderLabels = ['confirmed' => 'Confirmada', 'collecting' => 'En acopio', 'ready' => 'Lista para despacho', 'in_transit' => 'En camino', 'delivered' => 'Entregada', 'closed' => 'Cerrada', 'cancelled' => 'Cancelada'];
    $workflow = [
        ['permission' => 'view-producers', 'number' => '01', 'icon' => 'user', 'title' => 'Verificar productores', 'count' => $counts['producers_pending'], 'caption' => 'pendientes de verificación', 'url' => route('producers.index', ['status' => 'pending_verification'])],
        ['permission' => 'view-offers', 'number' => '02', 'icon' => 'shield', 'title' => 'Revisar ofertas', 'count' => $counts['offers_pending'], 'caption' => 'por verificar', 'url' => route('offers.index', ['status' => 'pending_verification'])],
        ['permission' => 'view-offers', 'number' => '03', 'icon' => 'box', 'title' => 'Preparar publicación', 'count' => $counts['offers_verified'], 'caption' => 'ofertas verificadas', 'url' => route('offers.index', ['status' => 'verified'])],
        ['permission' => 'manage-commerce', 'number' => '04', 'icon' => 'truck', 'title' => 'Coordinar acopio', 'count' => $counts['orders_active'], 'caption' => 'órdenes activas en este modo', 'url' => route('commerce.index')],
    ];
@endphp
<div class="op-dash op-dash--coordinator">
    <header class="op-dash-header">
        <div><p class="op-dash-eyebrow">COORDINACIÓN / MESA DE TRABAJO</p><h1>Centro de coordinación</h1><p>Hola, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}. Acompaña cada producto, desde el registro hasta la entrega.</p></div>
        <div class="op-dash-actions">
            @if($displayPermissions->contains('manage-offers'))<a class="op-dash-button op-dash-button--outline" href="{{ route('offers.create') }}"><x-ac-icon name="plus" /> Registrar oferta</a>@endif
            @if($displayPermissions->contains('manage-producers'))<a class="op-dash-button" href="{{ route('producers.create') }}"><x-ac-icon name="user" /> Registrar productor</a>@endif
        </div>
    </header>

    <section class="op-dash-workspace-banner" aria-labelledby="coordinator-workflow-title">
        <div><span class="op-dash-workspace-mark"><x-ac-icon name="leaf" /></span><div><p class="op-dash-eyebrow">DEL CAMPO A LA ENTREGA</p><h2 id="coordinator-workflow-title">Cada paso, bien acompañado.</h2><p>Revisa los pendientes y continúa la operación desde donde quedó.</p></div></div>
        <a href="{{ route('reports.index') }}" class="op-dash-button op-dash-button--light"><x-ac-icon name="chart" /> Ver reporte operativo</a>
    </section>

    <section class="op-dash-workflow" aria-labelledby="coordinator-workflow-title">
        @foreach($workflow as $step)
            @if($displayPermissions->contains($step['permission']))
                <a href="{{ $step['url'] }}" class="op-dash-step"><div class="op-dash-step-top"><span class="op-dash-step-number">{{ $step['number'] }}</span><x-ac-icon :name="$step['icon']" /></div><h3>{{ $step['title'] }}</h3><strong>{{ $step['count'] }}</strong><p>{{ $step['caption'] }}</p><span class="op-dash-card-link">Abrir etapa <x-ac-icon name="arrow" /></span></a>
            @endif
        @endforeach
    </section>

    <div class="op-dash-review-grid">
        @if($displayPermissions->contains('view-offers'))
            <section class="op-dash-panel" aria-labelledby="coordinator-offers-title">
                <div class="op-dash-panel-heading"><div><p class="op-dash-eyebrow">CALIDAD Y DISPONIBILIDAD</p><h2 id="coordinator-offers-title">Ofertas por revisar <span class="op-dash-count">{{ $counts['offers_pending'] }}</span></h2></div><a class="op-dash-text-link" href="{{ route('offers.index', ['status' => 'pending_verification']) }}">Ver todas <x-ac-icon name="arrow" /></a></div>
                <div class="op-dash-review-list">
                    @forelse($operationsDashboard['pendingOffers'] as $offer)
                        <a class="op-dash-review-row" href="{{ route('offers.show', $offer) }}">
                            <span class="op-dash-product-icon"><x-ac-icon name="leaf" /></span>
                            <span class="op-dash-review-copy"><strong>{{ $offer->product?->name ?? 'Producto' }} <span>· {{ $offer->variety?->name ?? 'Sin especificar' }}</span></strong><small>{{ $offer->producer?->full_name ?? 'Productor' }} · {{ $offer->location?->name ?? 'Ubicación por completar' }}</small><span class="op-dash-review-meta">Oferta #{{ $offer->id }} · {{ \App\Models\Offer::STATUSES[$offer->status] ?? $offer->status }}</span></span>
                            <span class="op-dash-review-quantity">
                                @if($offer->normalized_quantity !== null)
                                    {{ rtrim(rtrim(number_format((float) $offer->normalized_quantity, 3, '.', ''), '0'), '.') }} <small>{{ $offer->baseUnit?->code }}</small>
                                @else
                                    <small>Cantidad por normalizar</small>
                                @endif
                                <x-ac-icon name="chevron" />
                            </span>
                        </a>
                    @empty
                        <div class="op-dash-empty op-dash-empty--compact"><span class="op-dash-empty-icon"><x-ac-icon name="shield" /></span><h3>Sin ofertas pendientes de verificación</h3><p>Las propuestas nuevas aparecerán en esta bandeja.</p><a class="op-dash-text-link" href="{{ route('offers.index') }}">Explorar las ofertas <x-ac-icon name="arrow" /></a></div>
                    @endforelse
                </div>
                <div class="op-dash-panel-note"><x-ac-icon name="shield" /><p>Verifica el producto, la cantidad, el precio aceptado y las fechas antes de publicar.</p></div>
            </section>
        @endif

        @if($displayPermissions->contains('view-producers'))
            <section class="op-dash-panel" aria-labelledby="coordinator-producers-title">
                <div class="op-dash-panel-heading"><div><p class="op-dash-eyebrow">CONOCE A QUIEN PRODUCE</p><h2 id="coordinator-producers-title">Productores por verificar</h2></div><span class="op-dash-count">{{ $counts['producers_pending'] }}</span></div>
                <div class="op-dash-review-list">
                    @forelse($operationsDashboard['pendingProducers'] as $producer)
                        <a class="op-dash-person-row" href="{{ route('producers.show', $producer) }}"><span class="op-dash-avatar">{{ mb_strtoupper(mb_substr($producer->full_name, 0, 1)) }}</span><span><strong>{{ $producer->full_name }}</strong><small>Registro {{ $producer->created_at?->timezone('America/Lima')->format('d/m/Y') }}</small></span><x-ac-icon name="chevron" /></a>
                    @empty
                        <div class="op-dash-empty op-dash-empty--compact"><span class="op-dash-empty-icon"><x-ac-icon name="users" /></span><h3>Sin verificaciones pendientes</h3><p>Consulta las fichas o registra al siguiente productor.</p>@if($displayPermissions->contains('manage-producers'))<a class="op-dash-text-link" href="{{ route('producers.create') }}">Registrar productor <x-ac-icon name="plus" /></a>@endif</div>
                    @endforelse
                </div>
                <a class="op-dash-panel-footer" href="{{ route('producers.index') }}">Ver los {{ $counts['producers_total'] }} productores registrados <x-ac-icon name="arrow" /></a>
            </section>
        @endif
    </div>

    <div class="op-dash-main-grid">
        @if($displayPermissions->contains('manage-commerce'))
            <section class="op-dash-panel" aria-labelledby="coordinator-orders-title">
                <div class="op-dash-panel-heading"><div><p class="op-dash-eyebrow">ACOPIO Y ENTREGA</p><h2 id="coordinator-orders-title">Sigue cada orden</h2></div><a class="op-dash-text-link" href="{{ route('commerce.index') }}">Ver todas <x-ac-icon name="arrow" /></a></div>
                <div class="op-dash-order-context"><span class="op-dash-tag"><span aria-hidden="true"></span>{{ $modeLabel }}</span><p>{{ $counts['collections_scheduled'] }} acopios programados</p></div>
                @if($operationsDashboard['recentOrders']->isNotEmpty())
                    <div class="op-dash-table-wrap">
                        <table class="op-dash-table">
                            <thead><tr><th>Orden / comprador</th><th>Estado</th><th>Siguiente paso</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                            <tbody>
                                @foreach($operationsDashboard['recentOrders'] as $order)
                                    @php($nextStep = match ($order->status) { 'confirmed' => 'Programar acopio', 'collecting' => 'Registrar recepción', 'ready' => 'Preparar despacho', 'in_transit' => 'Registrar entrega', 'delivered' => 'Revisar cierre', 'closed' => 'Consultar historial', default => 'Revisar orden' })
                                    <tr><td><a class="op-dash-order-code" href="{{ route('commerce.orders.show', $order) }}">{{ $order->code }}</a><span class="op-dash-table-secondary">{{ $order->buyer?->user?->name ?? 'Comprador' }}</span></td><td><span class="op-dash-status op-dash-status--{{ in_array($order->status, ['closed', 'delivered']) ? 'complete' : 'active' }}">{{ $orderLabels[$order->status] ?? $order->status }}</span></td><td class="op-dash-next-step">{{ $nextStep }}</td><td><a class="op-dash-row-arrow" href="{{ route('commerce.orders.show', $order) }}" aria-label="Abrir orden {{ $order->code }}"><x-ac-icon name="arrow" /></a></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="op-dash-empty op-dash-empty--compact"><span class="op-dash-empty-icon"><x-ac-icon name="truck" /></span><h3>El siguiente acopio empieza con una compra</h3><p>Cuando se confirme una orden en este modo, podrás coordinar su recepción y entrega desde aquí.</p></div>
                @endif
            </section>
        @endif

        <aside class="op-dash-coordinator-support" aria-label="Herramientas de coordinación">
            @if(config('platform.internal_communication'))
                <section class="op-dash-message-card"><span class="op-dash-icon-box"><x-ac-icon name="users" /></span><p class="op-dash-eyebrow">ATENCIÓN Y COORDINACIÓN</p><h2>Resuelve el siguiente paso con tu equipo.</h2><p>Atiende consultas de productores y compradores. Todas las respuestas quedan en su cuenta.</p><a class="op-dash-button" href="{{ route('coordination.index') }}">Abrir conversaciones <x-ac-icon name="arrow" /></a></section>
            @elseif($displayPermissions->contains('manage-messaging'))
                <section class="op-dash-message-card"><span class="op-dash-icon-box"><x-ac-icon name="whatsapp" /></span><p class="op-dash-eyebrow">ATENCIÓN AL PRODUCTOR</p><h2>Una conversación puede destrabar el siguiente paso.</h2><p><strong>{{ $counts['conversations_attention'] }}</strong> conversaciones requieren asistencia.</p><a class="op-dash-button" href="{{ route('messaging.index') }}">Abrir conversaciones <x-ac-icon name="arrow" /></a></section>
            @endif
            <section class="op-dash-tools">
                <h2>A mano para tu jornada</h2>
                @if($displayPermissions->contains('manage-commerce'))<a href="{{ route('fulfillment.points') }}"><x-ac-icon name="pin" /><span>Puntos de acopio</span><x-ac-icon name="chevron" /></a>@endif
                @if($displayPermissions->contains('view-catalogs'))<a href="{{ route('catalog.index', 'products') }}"><x-ac-icon name="leaf" /><span>Productos y variedades</span><x-ac-icon name="chevron" /></a>@endif
                @if($displayPermissions->contains('view-lots'))<a href="{{ route('lots.index') }}"><x-ac-icon name="box" /><span>Lotes y disponibilidad</span><x-ac-icon name="chevron" /></a>@endif
            </section>
        </aside>
    </div>
</div>
