<x-layout :title="$lot->product->name.' · '.$lot->code">
    @php
        $visual = app(\App\Services\ProductVisual::class)->forProduct($lot->product);
        $price = fn ($value) => number_format((float) $value, max(2, strlen(rtrim(explode('.', (string) $value)[1] ?? '', '0'))), '.', ',');
        $availableQuantity = rtrim(rtrim(bcadd((string) $lot->available_quantity, '0', 3), '0'), '.');
        $availableToday = $lot->available_from->toDateString() <= now('America/Lima')->toDateString();
        $operator = auth()->user()?->hasPermission('view-lots') && auth()->user()?->hasPermission('manage-commerce');
        $minimumQuantity = \App\Services\PurchaseQuantity::minimum($lot->baseUnit);
        $minimumLabel = $minimumQuantity === null ? null : \App\Services\PurchaseQuantity::format($minimumQuantity).' '.$lot->baseUnit->code;
        $canQuote = \App\Services\PurchaseQuantity::allows($lot->baseUnit, (string) $lot->available_quantity);
    @endphp
    <nav aria-label="Ubicación de la página" class="mb-6 flex flex-wrap items-center gap-3 text-xs text-[#606d62]">
        <a class="ac-inline-link" href="{{ route('marketplace.index') }}#catalogo">← Explorar lotes</a><span aria-hidden="true">/</span><span aria-current="page">{{ $lot->product->name }} · {{ $lot->code }}</span>
    </nav>
    <div class="grid items-start gap-7 lg:grid-cols-[1.25fr_1fr] lg:gap-9">
        <div class="min-w-0">
            <section class="overflow-hidden rounded-2xl border border-[#dfe4da] bg-white">
                <div class="ac-detail-gallery">
                    <img src="{{ asset($visual['image']) }}" alt="{{ $visual['alt'] }}" width="720" height="540" fetchpriority="high" class="transition-transform duration-700 hover:scale-[1.03] motion-reduce:transform-none">
                    <span class="ac-stock-badge"><span></span>{{ $availableToday ? 'Disponible hoy' : 'Disponible pronto' }}</span>
                    <span class="ac-photo-caption">Imagen referencial del producto</span>
                </div>
                <div class="ac-detail-heading">
                    <p class="ac-eyebrow">DEL CAMPO A TU NEGOCIO · {{ $lot->code }}</p>
                    <h1>{{ $lot->product->name }}</h1>
                    <p class="mt-3 text-lg text-[#627057]">{{ $lot->variety->name }} <span class="mx-1 text-[#b0baaa]">·</span> {{ $lot->qualityGrade->name }}</p>
                    <p class="mt-4 flex items-start gap-2 text-sm leading-6 text-[#606d62]"><x-ac-icon name="pin" />{{ $locationLabel }}</p>
                    <dl class="mt-6 grid grid-cols-2 gap-5 border-t border-[#e0e5da] pt-6">
                        <div><dt class="text-xs text-[#606d62]">Cantidad disponible</dt><dd class="mt-2 text-2xl font-semibold text-[#153f2d]">{{ $availableQuantity }} <span class="text-base font-normal">{{ $lot->baseUnit->code }}</span></dd></div>
                        <div><dt class="text-xs text-[#606d62]">Productores que aportan</dt><dd class="mt-2 text-2xl font-semibold text-[#153f2d]">{{ $lot->producer_count }} <span class="text-base font-normal">{{ (int) $lot->producer_count === 1 ? 'productor' : 'productores' }}</span></dd></div>
                    </dl>
                    <p class="mt-5 text-xs leading-5 text-[#606d62]">Disponibilidad en esta consulta.@if($minimumLabel) Puedes cotizar una parte del lote desde {{ $minimumLabel }}.@endif</p>
                </div>
            </section>
            <section class="mt-6 rounded-2xl border border-[#dfe4da] bg-white p-7">
                <p class="ac-eyebrow">INFORMACIÓN PARA ELEGIR MEJOR</p>
                <h2 class="mt-3 text-2xl font-semibold">Conoce el origen</h2>
                <dl class="mt-6 grid gap-6 text-sm sm:grid-cols-2">
                    <div><dt class="flex items-center gap-2 text-[#627057]"><x-ac-icon name="pin" />Ubicación de las ofertas</dt><dd class="mt-2 font-semibold leading-6">{{ $locationLabel }}</dd></div>
                    <div><dt class="flex items-center gap-2 text-[#627057]"><x-ac-icon name="leaf" />Calidad registrada</dt><dd class="mt-2 font-semibold leading-6">{{ $lot->qualityGrade->name }}</dd></div>
                    <div><dt class="flex items-center gap-2 text-[#627057]"><x-ac-icon name="clock" />Ventana de disponibilidad</dt><dd class="mt-2 font-semibold leading-6">{{ $lot->available_from->format('d/m/Y') }} – {{ $lot->available_until->format('d/m/Y') }}</dd></div>
                    <div><dt class="flex items-center gap-2 text-[#627057]"><x-ac-icon name="truck" />Entrega</dt><dd class="mt-2 leading-6">Al confirmar eliges la fecha solicitada de entrega. Nosotros cubrimos el transporte y coordinación confirma el horario.</dd></div>
                </dl>
            </section>
            <section class="mt-6 rounded-2xl bg-[#eaf0df] p-7">
                <p class="ac-eyebrow">UNA COMPRA CON INFORMACIÓN CLARA</p>
                <h2 class="mt-3 text-xl font-semibold">Trazabilidad de la composición</h2>
                <ol class="mt-5 space-y-4 text-sm leading-6">
                    <li class="flex items-start gap-3"><x-ac-icon name="shield" /><span>Ofertas de productores verificados, con condiciones aceptadas vigentes.</span></li>
                    <li class="flex items-start gap-3"><x-ac-icon name="box" /><span>Porciones registradas y separadas para este lote comercial.</span></li>
                    <li class="flex items-start gap-3"><x-ac-icon name="clock" /><span>Recepción física, pesaje y acopio pendientes de coordinar.</span></li>
                </ol>
                <p class="mt-6 border-t border-[#cdd9bd] pt-4 text-xs leading-6 text-[#606d62]">El equipo conserva el origen de cada aportación. La consulta pública protege la identidad y los datos de contacto de los productores.</p>
            </section>
        </div>
        <aside class="ac-detail-aside min-w-0">
            @if($operator)
                <section class="mb-5 rounded-2xl bg-[#153f2d] p-7 text-white">
                    <p class="text-xs font-semibold uppercase tracking-wider text-[#d7e8a4]">Control del lote</p><h2 class="mt-3 text-2xl font-semibold">Productores y acopio</h2>
                    <p class="mt-3 text-sm leading-6 text-white/85">Consulta quién aporta, cantidades, direcciones y puntos en el mapa. Revisa la cercanía de los productores y administra la publicación.</p>
                    <a class="ac-button ac-button--light mt-5 w-full" href="{{ route('lots.show', $lot) }}">Gestionar lote y ver productores <x-ac-icon name="arrow" /></a>
                    <a class="mt-4 inline-block text-sm underline" href="{{ route('lots.show', $lot) }}#mapa-acopio">Ver mapa de aportaciones →</a>
                </section>
            @endif
            <section class="rounded-2xl border border-[#dfe4da] bg-white p-7 sm:p-8" data-lot-estimator data-available="{{ $lot->available_quantity }}" data-price-min="{{ $lot->unit_price_min }}" data-price-max="{{ $lot->unit_price_max }}" data-unit-dimension="{{ $lot->baseUnit->dimension }}">
                <p class="ac-eyebrow">{{ $operator ? 'REFERENCIA COMERCIAL' : 'TU PRÓXIMA COMPRA EMPIEZA AQUÍ' }}</p>
                <h2 class="mt-3 text-2xl font-semibold">{{ $operator ? 'Consulta los precios del lote.' : 'Elige cuánto necesitas.' }}</h2>
                <p class="ac-detail-price mt-5 font-semibold">S/ {{ $price($lot->unit_price_min) }}@if(bccomp((string) $lot->unit_price_min, (string) $lot->unit_price_max, 6) !== 0) – {{ $price($lot->unit_price_max) }}@endif <span class="text-sm font-normal tracking-normal text-[#606d62]">/ {{ $lot->baseUnit->code }}</span></p>
                <p class="mt-1 text-xs font-medium text-[#627057]">Precio estimado del producto</p>
                <p class="mt-3 text-xs leading-6 text-[#606d62]">Rango de precios brutos aceptados de las ofertas del lote. El transporte lo cubre la plataforma. Los cargos de servicio y tributos aplicables se detallan al cotizar.</p>
                <form method="GET" action="{{ route('marketplace.show', $lot->code) }}" class="mt-6 border-t border-[#e0e5da] pt-6">
                    <label for="quantity" class="block text-sm font-semibold">Cantidad que necesitas ({{ $lot->baseUnit->code }})</label>
                    <div class="mt-3 flex items-center gap-2">
                        <button type="button" data-quantity-step="-1" aria-label="Reducir cantidad en una unidad" class="size-12 shrink-0 rounded-xl border border-[#cdd8c7] text-xl font-semibold transition-colors hover:bg-[#edf2e7]">−</button>
                        <input type="number" id="quantity" name="quantity" data-estimate-quantity min="{{ $minimumQuantity ?? '0.001' }}" step="{{ $lot->baseUnit->dimension === 'count' ? '1' : '0.001' }}" max="{{ $lot->available_quantity }}" value="{{ is_scalar(request('quantity')) ? request('quantity') : '' }}" required class="min-w-0 w-full rounded-xl border border-[#cdd8c7] px-4 py-3 text-center text-lg" aria-describedby="estimate-help estimate-error">
                        <button type="button" data-quantity-step="1" aria-label="Aumentar cantidad en una unidad" class="size-12 shrink-0 rounded-xl border border-[#cdd8c7] text-xl font-semibold transition-colors hover:bg-[#edf2e7]">+</button>
                    </div>
                    <p id="estimate-help" class="mt-3 text-xs leading-5 text-[#606d62]">@if($minimumLabel) Compra mínima {{ $minimumLabel }}. @endif Hasta {{ $availableQuantity }} {{ $lot->baseUnit->code }}. Estimar no separa ni reserva cantidades.</p>
                    <p id="estimate-error" data-estimate-error role="alert" class="mt-3 text-sm text-red-800">{{ $quantityError }}</p>
                    <div class="mt-5 rounded-xl bg-[#edf2e7] p-5" aria-live="polite">
                        <p class="ac-eyebrow">Rango orientativo</p>
                        <p data-estimate-output class="mt-3 text-2xl font-semibold text-[#153f2d]">{{ $estimate ? 'S/ '.$estimate['minimum'].' – '.$estimate['maximum'] : 'Indica una cantidad' }}</p>
                        <p class="mt-3 text-xs leading-6 text-[#627057]">La selección concreta de ofertas y los cargos finales se resolverán al cotizar. Este cálculo no es una cotización ni un compromiso de precio.</p>
                    </div>
                    <button type="submit" class="ac-button ac-button--outline mt-5 w-full">Actualizar estimación y disponibilidad</button>
                </form>
                <div class="mt-6 border-t border-[#dfe4da] pt-6">
                    @if($operator)
                    <a class="ac-button w-full" href="{{ route('lots.show', $lot) }}">Ir a la gestión del lote <x-ac-icon name="arrow" /></a>
                    <p class="mt-4 text-xs leading-6 text-[#606d62]">Los pedidos se registran a nombre de un comprador autorizado. Desde tu panel puedes supervisar las compras, el acopio y la entrega.</p>
                    @elseif(! $canQuote)
                    <p class="rounded-xl bg-amber-50 p-4 text-sm">{{ $minimumLabel ? 'El saldo de este lote es menor que la compra mínima de '.$minimumLabel.'.' : 'La unidad de venta está pendiente de configuración.' }}</p><a class="mt-4 inline-block text-sm font-semibold underline" href="{{ route('marketplace.index', ['product_id' => $lot->product_id]) }}">Ver otros lotes de {{ $lot->product->name }}</a>
                    @else
                    <a class="ac-button w-full" data-quote-link data-quote-url="{{ route('commerce.quotes.create', $lot) }}" href="{{ route('commerce.quotes.create', ['lot' => $lot, 'quantity' => $quantityError ? null : $quantity]) }}">Cotizar para comprar o reservar <x-ac-icon name="arrow" /></a>
                    <p class="mt-4 flex items-start gap-2 text-xs leading-6 text-[#606d62]"><x-ac-icon name="shield" /><span>Revisa las aportaciones y los cargos antes de confirmar. Necesitas un perfil de comprador autorizado.</span></p>
                    @endif
                </div>
            </section>
            <div class="mt-5 flex items-start gap-3 rounded-xl border border-[#dfe4da] p-5 text-xs leading-6 text-[#606d62]"><x-ac-icon name="truck" /><p><strong class="block text-sm font-semibold text-[#203a2d]">¿Qué sucede después?</strong>Al confirmar la compra, podrás seguir el pedido desde tu panel. El equipo coordina el acopio, la recepción y la entrega.</p></div>
        </aside>
    </div>
</x-layout>
