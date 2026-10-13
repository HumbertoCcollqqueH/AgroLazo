<x-layout title="Dashboard y reportes">
    @php($filters = $snapshot['filters'])
    @php($metrics = $snapshot['metrics'])
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Datos para decidir</p><h1 class="mt-3 text-3xl font-semibold">{{ $snapshot['operator'] ? 'Dashboard de la operación' : 'El recorrido de tus compras' }}</h1><p class="mt-3 text-sm text-[#6f7d66]">Actualizado {{ $snapshot['generated_at'] }} · Hora de Perú · Moneda PEN</p></div>
        <a class="underline" href="{{ route('dashboard') }}">Volver a mi espacio</a>
    </div>
    <form method="GET" action="{{ route('reports.index') }}" class="mb-6 grid gap-4 rounded-2xl border border-[#dfe4da] bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-field name="from" label="Desde" type="date" :value="$filters['from']" required />
        <x-field name="to" label="Hasta" type="date" :value="$filters['to']" required />
        <label class="text-sm font-semibold">Operaciones<select name="data" class="mt-2 w-full rounded-xl border border-[#d5dfce] p-3"><option value="synthetic" @selected($filters['data'] === 'synthetic')>Demostración sintética</option><option value="real" @selected($filters['data'] === 'real')>Operaciones reales</option></select></label>
        <label class="text-sm font-semibold">Informe<select name="report" class="mt-2 w-full rounded-xl border border-[#d5dfce] p-3">@foreach($types as $code => $name)<option value="{{ $code }}" @selected($snapshot['report'] === $code)>{{ $name }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Producto<select name="product_id" class="mt-2 w-full rounded-xl border border-[#d5dfce] p-3"><option value="">Todos</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected($filters['product_id'] == $product->id)>{{ $product->name }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Localidad exacta<select name="location_id" class="mt-2 w-full rounded-xl border border-[#d5dfce] p-3"><option value="">Todas</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($filters['location_id'] == $location->id)>{{ $location->name }}</option>@endforeach</select></label>
        <div class="flex items-end"><button class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Aplicar filtros</button></div>
        <p class="self-end text-xs leading-5 text-[#6f7d66]">Hasta 366 días y 2,000 filas por informe. Las cantidades de unidades distintas se muestran separadas.</p>
    </form>
    <p class="mb-6 rounded-xl bg-[#e9eddf] p-4 text-sm">{{ $filters['data'] === 'synthetic' ? 'DEMOSTRACIÓN: estos importes no representan dinero ni mercancía reales.' : 'Solo operaciones marcadas como reales.' }} Los indicadores corresponden a órdenes confirmadas en el período, con sus ajustes y saldos actuales. No son movimientos de caja ocurridos solamente dentro de esas fechas.</p>
    @php($cards = ['orders' => 'Órdenes', 'sales' => 'Compras con cantidad vigente', 'quantity_kg' => 'kg comprados vigentes', 'gmv_minor' => 'GMV contratado · producto', 'total_minor' => 'Total contratado', 'collected_minor' => 'Cobrado neto de devoluciones', 'buyer_due_minor' => 'Saldo por cobrar', 'buyer_credit_minor' => 'Saldo a favor del comprador', 'delivery_pending' => 'Órdenes por entregar', 'payments_pending' => 'Cobros por revisar', 'refund_pending_minor' => 'Devoluciones comprometidas', 'average_kg' => 'Precio ponderado · S/ por kg'])
    @if($snapshot['operator'])
        @php($cards += ['income_minor' => 'Ingreso pactado de plataforma', 'fee_minor' => 'Servicio antes de descuentos', 'producer_net_minor' => 'Neto productor calculado', 'producer_paid_minor' => 'Desembolsado a productores', 'producer_due_minor' => 'Neto calculado pendiente', 'settlements_draft' => 'Liquidaciones por aprobar', 'receipts_unsettled' => 'Recepciones sin liquidación', 'rejected_kg' => 'kg rechazados', 'active_buyers' => 'Compradores del período', 'repeat_buyers' => 'Compradores con varias compras', 'active_producers' => 'Productores participantes', 'repeat_producers' => 'Productores en varias compras'])
    @endif
    <dl class="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach($cards as $key => $label)<div class="rounded-2xl border border-[#dfe4da] bg-white p-5"><dt class="text-xs leading-5 text-[#6f7d66]">{{ $label }}</dt><dd class="mt-2 break-words text-2xl font-semibold">{{ $metrics[$key] === null ? 'Sin datos' : (str_ends_with($key, '_minor') ? 'S/ '.\App\Services\Money::format($metrics[$key]) : $metrics[$key]) }}</dd></div>@endforeach
    </dl>
    @if($snapshot['operator'])
        <x-commerce-panel title="Padrón e inventario actuales" class="mb-8">
            <p class="mb-4 text-sm text-[#6f7d66]">Estado de ahora, independiente del período y del tipo de operación. Producto y localidad filtran ofertas e inventario; el padrón es global. Los kg disponibles corresponden al marketplace elegible, sin duplicar reservas ni compras.</p>
            <dl class="grid grid-cols-2 gap-5 md:grid-cols-3">@foreach(['producers' => 'Productores registrados', 'verified' => 'Productores verificados', 'buyers' => 'Compradores registrados', 'offers_active' => 'Ofertas activas elegibles', 'offers_pending' => 'Ofertas por revisar', 'available_kg' => 'kg disponibles publicados'] as $key => $label)<div><dt class="text-sm text-[#6f7d66]">{{ $label }}</dt><dd class="mt-1 text-xl font-semibold">{{ $snapshot['inventory'][$key] }}</dd></div>@endforeach</dl>
        </x-commerce-panel>
    @endif
    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        @foreach($snapshot['charts'] as $chart)
            <x-commerce-panel :title="$chart['title']">
                <p class="mb-4 text-xs text-[#6f7d66]">{{ $chart['unit'] }}</p>
                @php($maximum = max([1, ...array_map(fn ($r) => (float) $r['value'], $chart['rows'])]))
                <ul class="space-y-4">@forelse($chart['rows'] as $row)<li><div class="mb-1 flex justify-between gap-3 text-sm"><span>{{ $row['label'] }}</span><strong>{{ $row['value'] }}</strong></div><svg class="h-3 w-full" viewBox="0 0 100 3" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="3" rx="1" fill="#e9eddf"/><rect width="{{ max(0, min(100, (float) $row['value'] / $maximum * 100)) }}" height="3" rx="1" fill="#22583e"/></svg></li>@empty<li class="text-sm text-[#6f7d66]">Sin operaciones para este período.</li>@endforelse</ul>
            </x-commerce-panel>
        @endforeach
    </div>
    <details class="mb-8 rounded-2xl border border-[#dfe4da] bg-white p-5">
        <summary class="cursor-pointer font-semibold">Cómo leer los indicadores</summary>
        <div class="mt-4 space-y-3 text-sm leading-6 text-[#6f7d66]">
            <p>El GMV suma el producto de las compras confirmadas, después de reducciones aceptadas. Separa producto, servicio, logística y tributos. Una reserva no es una venta; una compra confirmada todavía puede estar pendiente de entrega o cobro.</p>
            <p>El ingreso pactado de plataforma es el servicio al comprador menos el descuento financiado por la plataforma. No incluye todo el dinero recaudado ni atribuye a AgroLazo los costos descontados al productor. No representa utilidad neta: faltan costos operativos completos.</p>
            <p>El neto productor utiliza únicamente la última versión de cada liquidación. El desembolsado incluye pagos confirmados de todas sus versiones. El neto calculado puede contener borradores; las recepciones aún sin liquidar se indican aparte.</p>
            <p>Recompras y productores recurrentes cuentan quienes aparecen en más de una compra vigente del período seleccionado; no expresan una tasa de retención ni causalidad. Los rechazos son kg recibidos y rechazados, no kilos prometidos que faltaron.</p>
            <p>Mejora frente a venta alternativa: sin base comparable. Las referencias de mercado son brutas y no prueban el neto que habría recibido cada productor; no se atribuye una mejora económica sin evidencia comparable.</p>
        </div>
    </details>
    @if($snapshot['operator'])
        <x-commerce-panel title="Mapa de coordenadas para coordinación" class="mb-8">
            <p class="mb-4 text-sm leading-6 text-[#6f7d66]">Vista geográfica de coordenadas actuales, sin mapas externos. El origen de ofertas y lotes usa la ubicación del productor con consentimiento vigente; no acredita posición de la mercancía. Acopios usan su ubicación registrada. Compradores: sin coordenadas capturadas. Máximo 200 productores y 200 acopios; filtra la localidad para acotar.</p>
            @if(count($snapshot['map']))
                @php($minLat = min(array_column($snapshot['map'], 'latitude')) - .01)
                @php($maxLat = max(array_column($snapshot['map'], 'latitude')) + .01)
                @php($minLon = min(array_column($snapshot['map'], 'longitude')) - .01)
                @php($maxLon = max(array_column($snapshot['map'], 'longitude')) + .01)
                <svg viewBox="0 0 720 380" class="w-full rounded-xl bg-[#f6f5f0]" role="img" aria-label="Mapa de coordenadas registradas, norte arriba y este a la derecha">
                    <path d="M45 20V335H690" stroke="#819077" fill="none"/>
                    <text x="45" y="365" font-size="12">{{ number_format($minLon, 3) }}° longitud</text><text x="570" y="365" font-size="12">{{ number_format($maxLon, 3) }}°</text>
                    <text x="50" y="18" font-size="12">N · {{ number_format($maxLat, 3) }}° latitud</text><text x="50" y="330" font-size="12">{{ number_format($minLat, 3) }}°</text>
                    @foreach($snapshot['map'] as $point)<circle cx="{{ 60 + ((float)$point['longitude'] - $minLon) / ($maxLon - $minLon) * 610 }}" cy="{{ 35 + ($maxLat - (float)$point['latitude']) / ($maxLat - $minLat) * 275 }}" r="{{ $point['kind'] === 'Productor' ? 7 : 4 }}" fill="{{ ['Productor' => '#22583e', 'Oferta' => '#b68c38', 'Lote' => '#416e9c', 'Acopio' => '#b84b43'][$point['kind']] }}" opacity=".75"><title>{{ $point['kind'] }} · {{ $point['label'] }}</title></circle>@endforeach
                </svg>
                <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Ver puntos y coordenadas</summary><ul class="mt-3 space-y-2 text-sm">@foreach($snapshot['map'] as $point)<li>{{ $point['kind'] }} · {{ $point['label'] }} · {{ $point['latitude'] }}, {{ $point['longitude'] }}</li>@endforeach</ul></details>
            @else
                <p class="rounded-xl bg-[#f6f5f0] p-6 text-sm">Todavía no hay coordenadas autorizadas para estos filtros. Puedes operar y planificar acopio sin GPS.</p>
            @endif
        </x-commerce-panel>
    @endif
    <x-commerce-panel :title="'Informe: '.$snapshot['table']['title']" class="mb-8">
        <p class="mb-4 text-sm text-[#6f7d66]">{{ $snapshot['table']['basis'] }}</p>
        <form method="POST" action="{{ route('reports.export') }}" class="mb-5 flex flex-wrap items-end gap-3">
            @csrf
            <x-commerce-key />
            @foreach($filters as $key => $value)
                @if($value !== null)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <input type="hidden" name="report" value="{{ $snapshot['report'] }}">
            <label class="text-sm font-semibold">Formato<select name="format" class="ml-2 rounded-lg border border-[#d5dfce] p-2"><option value="xlsx">Excel (.xlsx)</option><option value="csv">CSV</option><option value="pdf">PDF</option></select></label>
            <button class="rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white">Preparar descarga privada</button>
        </form>
        <p class="mb-4 text-xs text-[#6f7d66]">{{ count($snapshot['table']['rows']) }} filas. Vista previa de las primeras 50; la descarga incluye todas. Se recalcula al generar, usando estos mismos filtros. Las descargas caducan en 24 horas.</p>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr>@foreach($snapshot['table']['headers'] as $header)<th class="whitespace-nowrap border-b border-[#dfe4da] p-3">{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse(array_slice($snapshot['table']['rows'], 0, 50) as $row)<tr>@foreach($row as $value)<td class="whitespace-nowrap border-b border-[#edf0e9] p-3">{{ $value }}</td>@endforeach</tr>@empty<tr><td class="p-6" colspan="{{ count($snapshot['table']['headers']) }}">Sin registros para los filtros elegidos.</td></tr>@endforelse</tbody></table></div>
    </x-commerce-panel>
    <x-commerce-panel title="Tus exportaciones recientes">
        <a class="mb-4 inline-block text-sm underline" href="{{ request()->fullUrl() }}">Actualizar estado</a>
        <ul class="divide-y divide-[#dfe4da]">@forelse($exports as $export)<li class="flex flex-wrap justify-between gap-3 py-4 text-sm"><span>{{ \App\Services\ReportService::TYPES[$export->report] }} · {{ strtoupper($export->format) }} · {{ $export->created_at->setTimezone('America/Lima')->format('d/m H:i') }}</span>@if($export->status === 'ready' && $export->expires_at->isFuture())<a class="font-semibold underline" href="{{ route('reports.download', $export) }}">Descargar · {{ $export->row_count }} filas</a>@else<span>{{ $export->expires_at->isPast() ? 'Caducada' : (['pending' => 'En cola', 'failed' => 'No se pudo generar; revisa filtros y permisos y solicita otra', 'expired' => 'Caducada'][$export->status] ?? $export->status) }}</span>@endif</li>@empty<li class="py-4 text-sm text-[#6f7d66]">Aún no has solicitado exportaciones.</li>@endforelse</ul>
    </x-commerce-panel>
</x-layout>

