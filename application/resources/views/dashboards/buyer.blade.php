@php
    $buyer = auth()->user()->buyer;
    $firstName = \Illuminate\Support\Str::before(auth()->user()->name, ' ');
    $profileLabel = match ($buyer?->status) {
        'approved' => 'Perfil aprobado',
        'suspended' => 'Perfil suspendido',
        default => 'Perfil en revisión',
    };
@endphp
<div class="ac-dashboard">
    <section class="ac-dashboard-welcome" aria-labelledby="buyer-welcome">
        <div class="ac-dashboard-welcome-copy">
            <p class="ac-eyebrow">TU ESPACIO EN AGROLAZO</p>
            <h1 id="buyer-welcome">Hola, {{ $firstName }}.<br>Lo bueno viene <em>del campo.</em></h1>
            <p>Encuentra tu próximo lote y sigue cada paso de tus compras. Todo lo que necesitas, en un solo lugar.</p>
            <div class="ac-dashboard-actions">
                <a class="ac-button ac-button--light" href="{{ route('marketplace.index') }}">Explorar productos <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
                <a class="ac-dashboard-text-link" href="{{ route('commerce.index') }}">Mis compras <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow" /></span></a>
            </div>
        </div>
        <div class="ac-dashboard-welcome-image">
            <img src="{{ asset('images/marketplace/buyer-market.webp') }}" width="960" height="720" alt="Compra de productos agrícolas peruanos, imagen referencial" decoding="async">
            <span>Del origen a tu negocio.</span>
        </div>
    </section>

    @if($reportSummary)
        <section class="ac-dashboard-summary" aria-labelledby="buyer-summary-heading">
            <div class="ac-section-heading">
                <div>
                    <p class="ac-eyebrow">UNA MIRADA A TU ACTIVIDAD</p>
                    <h2 id="buyer-summary-heading">Tus compras, en cifras</h2>
                    <p>{{ \Carbon\CarbonImmutable::parse($reportSummary['filters']['from'])->format('d/m/Y') }} – {{ \Carbon\CarbonImmutable::parse($reportSummary['filters']['to'])->format('d/m/Y') }} · {{ config('commerce.show_test_labels') && $reportSummary['filters']['data'] === 'synthetic' ? 'Compras de prueba' : 'Mis compras' }}</p>
                </div>
                <a class="ac-inline-link" href="{{ route('reports.index', array_filter($reportSummary['filters'], fn ($value) => $value !== null)) }}">Ver reporte <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
            </div>
            <dl class="ac-dashboard-stats">
                <div class="ac-stat-card">
                    <dt><span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span> Órdenes del período</dt>
                    <dd>{{ $reportSummary['metrics']['orders'] }}</dd>
                    <dd class="ac-stat-note">Tus compras registradas</dd>
                </div>
                <div class="ac-stat-card">
                    <dt><span aria-hidden="true"><x-ac-icon name="shield" /></span> Cantidad vigente</dt>
                    <dd>{{ number_format((float) $reportSummary['metrics']['quantity_kg'], 3, '.', ',') }} <small>kg</small></dd>
                    <dd class="ac-stat-note">Después de ajustes aceptados</dd>
                </div>
                <div class="ac-stat-card">
                    <dt><span aria-hidden="true">S/</span> Total contratado</dt>
                    <dd><small>S/</small> {{ \App\Services\Money::format($reportSummary['metrics']['total_minor']) }}</dd>
                    <dd class="ac-stat-note">Importe vigente del período</dd>
                </div>
                <div class="ac-stat-card ac-stat-card--accent">
                    <dt><span aria-hidden="true"><x-ac-icon name="leaf" /></span> Lotes disponibles</dt>
                    <dd>{{ $buyerLotCount }}</dd>
                    <dd class="ac-stat-note"><a href="{{ route('marketplace.index') }}">Para tu próxima compra <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow" /></span></a></dd>
                </div>
            </dl>
        </section>
    @endif

    <div class="ac-dashboard-columns">
        <section class="ac-dashboard-panel" aria-labelledby="buyer-orders-heading">
            <div class="ac-section-heading">
                <div>
                    <p class="ac-eyebrow">PASO A PASO, CONTIGO</p>
                    <h2 id="buyer-orders-heading">Tus compras recientes</h2>
                    <p>{{ config('commerce.show_test_labels') && ($reportSummary['filters']['data'] ?? 'real') === 'synthetic' ? 'Últimas órdenes de prueba, de cualquier fecha.' : 'Tus últimas órdenes, de cualquier fecha.' }}</p>
                </div>
                <a class="ac-inline-link" href="{{ route('commerce.index') }}">Ver todas <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
            </div>
            <div class="ac-order-list">
                @forelse($buyerOrders as $order)
                    @php
                        $progress = match ($order->status) {
                            'confirmed' => 1,
                            'collecting' => 2,
                            'ready', 'in_transit' => 3,
                            'delivered' => 4,
                            default => 0,
                        };
                        $nextStep = match ($order->status) {
                            'confirmed' => 'Coordinación confirmará el lugar y horario de acopio.',
                            'collecting' => 'Tu producto está en etapa de acopio y pesaje. Revisa los avances en el detalle.',
                            'ready' => 'El producto está listo. Antes de salir, el equipo verificará el pago o crédito y el despacho.',
                            'in_transit' => 'Hay producto en tránsito. Consulta las entregas y cualquier incidencia.',
                            'delivered' => 'Entrega registrada. El equipo está conciliando los saldos para cerrar la compra.',
                            'closed' => 'La compra está cerrada y conciliada. Puedes consultar su historial.',
                            'cancelled' => 'La compra fue cancelada. Consulta los ajustes y devoluciones en el detalle.',
                            default => 'Consulta el detalle para ver la última actualización de tu compra.',
                        };
                    @endphp
                    <article class="ac-order-card" aria-label="Compra {{ $order->code }}">
                        <div class="ac-order-header">
                            <div><a href="{{ route('commerce.orders.show', $order) }}">{{ $order->code }}</a><time datetime="{{ $order->confirmed_at->toIso8601String() }}">{{ $order->confirmed_at->setTimezone('America/Lima')->format('d/m/Y') }}</time></div>
                            <span class="ac-order-status">{{ config('commerce.status_labels.'.$order->status, $order->status) }}</span>
                        </div>
                        <div class="ac-order-products">
                            @foreach($order->items as $item)
                                @php
                                    $currentQuantity = $item->allocations->reduce(fn ($total, $allocation) => bcadd($total, bcsub($allocation->quantity, $allocation->released_quantity, 3), 3), '0.000');
                                @endphp
                                <h3>{{ $item->product_snapshot['product'] }} <span>· {{ $item->product_snapshot['variety'] }}</span></h3>
                                <p>{{ rtrim(rtrim($currentQuantity, '0'), '.') ?: '0' }} {{ $item->baseUnit->code }} vigentes · {{ $item->product_snapshot['location'] }}</p>
                            @endforeach
                            <strong>S/ {{ \App\Services\Money::format($order->total_minor) }}</strong>
                        </div>
                        @if($progress > 0)
                            <ol class="ac-order-progress" aria-label="Etapa actual: {{ config('commerce.status_labels.'.$order->status, $order->status) }}">
                                @foreach(['Compra', 'Acopio', $order->status === 'ready' ? 'Por despachar' : ($order->status === 'in_transit' ? 'En tránsito' : 'Despacho'), 'Entrega'] as $step)
                                    <li class="{{ $loop->iteration < $progress || ($loop->iteration === 4 && $order->status === 'delivered') ? 'is-complete' : ($loop->iteration === $progress ? 'is-current' : '') }}" @if($loop->iteration === $progress) aria-current="step" @endif><span aria-hidden="true">{{ $loop->iteration }}</span>{{ $step }}</li>
                                @endforeach
                            </ol>
                        @endif
                        <div class="ac-order-next"><p>{{ $nextStep }}</p><a class="ac-inline-link" href="{{ route('commerce.orders.show', $order) }}" aria-label="Seguir compra {{ $order->code }}">Seguir compra <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow" /></span></a></div>
                    </article>
                @empty
                    <div class="ac-empty-state">
                        <span class="ac-empty-symbol" aria-hidden="true"><x-ac-icon name="box" /></span>
                        <h3>Tu próxima compra empieza aquí.</h3>
                        <p>Explora los lotes, elige la cantidad que necesitas y revisa el total antes de confirmar. Luego podrás seguirla desde este espacio.</p>
                        <a class="ac-button" href="{{ route('marketplace.index') }}">Encontrar mi primer lote <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow" /></span></a>
                    </div>
                @endforelse
            </div>
        </section>

        <aside class="ac-dashboard-sidebar" aria-label="Tu cuenta y accesos rápidos">
            <section class="ac-account-card">
                <div class="ac-account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
                <p class="ac-eyebrow">TU PERFIL</p>
                <h2>{{ auth()->user()->name }}</h2>
                <p>{{ \App\Models\Buyer::TYPES[$buyer?->type] ?? 'Comprador AgroLazo' }}</p>
                <span class="ac-account-status {{ $buyer?->status === 'approved' ? 'is-approved' : '' }}">{{ $profileLabel }}</span>
                <p class="ac-account-note">{{ match ($buyer?->status) {
                    'approved' => 'Puedes cotizar, reservar y confirmar tus compras.',
                    'suspended' => 'Contacta al equipo para revisar el estado de tu perfil.',
                    default => 'Ya puedes explorar productos. Te habilitaremos las compras cuando el equipo apruebe tu perfil.',
                } }}</p>
                <dl><div><dt>Correo</dt><dd>{{ auth()->user()->email }}</dd></div><div><dt>Teléfono</dt><dd>{{ $buyer?->phone ?: 'No registrado' }}</dd></div></dl>
                <a class="ac-inline-link" href="{{ route('account.edit') }}">Gestionar mi cuenta <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
            </section>
            <nav class="ac-dashboard-links" aria-label="Accesos del comprador">
                <p class="ac-eyebrow">A UN CLIC</p>
                <a href="{{ route('marketplace.index') }}"><span>Explorar productos<small>Disponibilidad y precios</small></span><span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
                <a href="{{ route('commerce.index') }}"><span>Compras y cotizaciones<small>Historial, pagos y entregas</small></span><span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
                <a href="{{ route('reports.index') }}"><span>Mis reportes<small>Consulta y exporta tu actividad</small></span><span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
            </nav>
        </aside>
    </div>

    <section class="ac-dashboard-products" aria-labelledby="buyer-products-heading">
        <div class="ac-section-heading">
            <div><p class="ac-eyebrow">EL CAMPO SIGUE PRODUCIENDO</p><h2 id="buyer-products-heading">Para tu próxima compra</h2><p>Lotes disponibles, con origen y condiciones para consultar.</p></div>
            <a class="ac-inline-link" href="{{ route('marketplace.index') }}">Explorar el mercado <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow ac-dashboard-arrow--diagonal" /></span></a>
        </div>
        <div class="ac-product-grid">
            @forelse($buyerLots as $lot)
                <x-market-lot-card :lot="$lot" />
            @empty
                <div class="ac-empty-state"><span class="ac-empty-symbol" aria-hidden="true"><x-ac-icon name="leaf" /></span><h3>Estamos preparando nuevas oportunidades.</h3><p>Los lotes aparecerán cuando estén verificados, con precio aceptado y disponibilidad para comprar.</p><a class="ac-inline-link" href="{{ route('marketplace.index') }}">Consultar el mercado <span aria-hidden="true"><x-ac-icon name="arrow" class="ac-dashboard-arrow" /></span></a></div>
            @endforelse
        </div>
    </section>
</div>
