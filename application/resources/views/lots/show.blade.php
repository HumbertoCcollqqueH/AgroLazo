<x-layout :title="$lot->code">
    <a href="{{ route('lots.index') }}" class="text-sm font-semibold underline underline-offset-4">← Lotes</a>
    <div class="mt-5 flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-semibold tracking-widest text-[#819077]">{{ $lot->code }} · LOTE COMERCIAL</p><h1 class="mt-3 text-3xl font-semibold">{{ $lot->product->name }}</h1><p class="mt-3 text-sm text-[#6f7d66]">{{ $lot->variety->name }} · {{ $lot->qualityGrade->name }}</p></div>
        <span class="rounded-full bg-[#e9eddf] px-4 py-2 text-sm font-semibold">{{ \App\Models\Lot::STATUSES[$lot->status] }} · versión {{ $lot->version }}</span>
    </div>
    <section class="mt-6 rounded-2xl border border-[#dfe4da] bg-white p-6">
        <p class="text-sm">{{ $locationLabel }}</p><p class="mt-2 text-sm text-[#6f7d66]">Ventana común: {{ $lot->available_from->format('d/m/Y') }} – {{ $lot->available_until->format('d/m/Y') }}</p>
        @if($marketable)
            <p class="mt-5 text-2xl font-semibold">{{ $marketable->available_quantity }} {{ $lot->baseUnit->code }} elegibles</p>
            <p class="mt-2 text-sm">{{ $marketable->producer_count }} {{ (int) $marketable->producer_count === 1 ? 'productor' : 'productores' }} · S/ {{ $marketable->unit_price_min }} – {{ $marketable->unit_price_max }} / {{ $lot->baseUnit->code }}</p>
            @if($lot->status === 'published')<a class="mt-5 inline-block rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white" href="{{ route('marketplace.show', $lot->code) }}">Ver ficha pública →</a>@else<p class="mt-4 text-sm text-[#6f7d66]">La composición es elegible. El estado actual mantiene el lote fuera del marketplace.</p>@endif
        @else
            <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm leading-6 text-amber-900">Este lote no tiene una composición comercial disponible. Revisa la vigencia, las ofertas, sus productores, el catálogo y los acuerdos. No se anuncia en el marketplace; sus porciones permanecen registradas.</p>
        @endif
        <p class="mt-5 text-xs leading-5 text-[#6f7d66]">Lote virtual: estas cantidades todavía no acreditan recepción física, pesaje, acopio ni entrega. Los precios son un rango orientativo del producto; no incluyen los cargos finales del comprador.</p>
    </section>
    <section class="mt-8 rounded-2xl border border-[#dfe4da] bg-white p-6" id="mapa-acopio">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold tracking-widest text-[#6f7d66]">SUPERVISIÓN DEL ACOPIO</p><h2 class="mt-2 text-2xl font-semibold">El origen de este lote, en el mapa</h2></div><span class="rounded-full bg-[#e9eddf] px-4 py-2 text-sm font-semibold">{{ $originPoints->count() }} puntos registrados</span></div>
        <p class="mt-3 text-sm leading-6">Agrupamos el mismo producto, variedad, calidad y localidad, con disponibilidad compatible. La separación máxima permitida entre dos productores es {{ $originInspection['limit_km'] }} km.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-3"><div class="rounded-xl bg-[#f5f6f0] p-4"><span class="block text-xs text-[#6f7d66]">Separación observada</span><strong class="mt-1 block text-xl">{{ count($originInspection['coordinates']) > 1 ? number_format($originInspection['max_distance_km'], 1).' km' : 'Sin comparar' }}</strong></div><div class="rounded-xl bg-[#f5f6f0] p-4"><span class="block text-xs text-[#6f7d66]">Ubicación pendiente</span><strong class="mt-1 block text-xl">{{ count($originInspection['missing_producer_ids']) }} productores</strong></div><div class="rounded-xl bg-[#f5f6f0] p-4"><span class="block text-xs text-[#6f7d66]">Revisión de proximidad</span><strong class="mt-1 block text-lg">{{ count($originInspection['missing_producer_ids']) ? 'Datos incompletos' : ($originInspection['allowed'] ? 'Dentro del rango' : 'Revisar separación') }}</strong></div></div>
        @if($originPoints->isNotEmpty())
            <div class="lot-origin-map mt-5" data-lot-origin-map data-points="{{ $originPoints->toJson() }}">
                <div class="lot-map-toolbar"><span>Selecciona un productor para localizarlo</span><div><button type="button" data-origin-zoom="1" aria-label="Acercar mapa">+</button><button type="button" data-origin-zoom="-1" aria-label="Alejar mapa">−</button><button type="button" data-origin-fit>Ver todos</button></div></div>
                <canvas data-origin-canvas tabindex="0" role="img" aria-label="Mapa de los productores del lote. Usa los botones de productores para centrar el mapa; flechas para desplazarte."></canvas>
                <p class="lot-map-attribution">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> · Distancias en línea recta; no representan rutas por carretera.</p>
                <div class="lot-map-producers">@foreach($originPoints as $point)<button type="button" data-origin-point="{{ $loop->index }}" aria-pressed="false"><b>{{ $loop->iteration }}</b><span>{{ $point['label'] }}<small>Oferta #{{ $point['offer_id'] }}</small></span></button>@endforeach</div>
                <p data-origin-status class="mt-3 text-sm" role="status">Todos los puntos del lote. Puedes mover y ampliar el mapa.</p>
            </div>
        @else
            <p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Registra el punto de recojo y la autorización de ubicación en las fichas de los productores para ver el mapa.</p>
        @endif
        <p class="mt-4 text-xs leading-5 text-[#6f7d66]">La agrupación comercial no significa que el producto ya se haya recogido. Al confirmar una compra, coordinación programa el punto de acopio y los horarios de recojo. Estas ubicaciones exactas son privadas para el equipo operativo.</p>
    </section>
    <h2 class="mt-8 text-xl font-semibold">Composición y origen privados</h2>
    @can('manage-traceability')<a class="mt-4 inline-block underline" href="{{ route('trace.manage', $lot) }}">Revisar publicación de trazabilidad y QR</a>@endcan
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        @foreach($lot->portions as $portion)
            <article class="rounded-2xl border border-[#dfe4da] bg-white p-5">
                <a class="font-semibold underline underline-offset-4" href="{{ route('offers.show', $portion->offer) }}">Oferta #{{ $portion->offer_id }} · {{ $portion->offer->producer->full_name }}</a>
                @php($origin = $portion->offer->producer->currentLocation)
                @php($origin = $origin?->location_id === $portion->offer->location_id ? $origin : null)
                <p class="mt-3 text-sm leading-6">{{ $origin?->community ?: 'Comunidad pendiente' }} · {{ $origin?->location?->name ?? $lot->location->name }}<br>{{ $origin?->address ?: 'Dirección de recojo pendiente de registrar' }}</p>
                <a class="mt-2 inline-block text-sm font-semibold underline" href="{{ route('producers.show', $portion->offer->producer) }}">Ver ficha del productor →</a>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt>Aportación original</dt><dd>{{ $portion->quantity }} {{ $lot->baseUnit->code }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Liberado a la oferta</dt><dd>{{ $portion->released_quantity }} {{ $lot->baseUnit->code }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Retirado del mercado</dt><dd>{{ $portion->retired_quantity }} {{ $lot->baseUnit->code }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Saldo de la porción</dt><dd>{{ $portion->availableQuantity() }} {{ $lot->baseUnit->code }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>Bruto aceptado</dt><dd>S/ {{ $portion->agreement->gross_unit_price }}</dd></div>
                </dl>
                <p class="mt-4 text-xs leading-5 text-[#6f7d66]">Oferta versión {{ $portion->offer_version }} · Acuerdo #{{ $portion->agreement_id }} · {{ \App\Models\Offer::STATUSES[$portion->offer->status] }} · Productor {{ \App\Models\Producer::STATUSES[$portion->offer->producer->status] }}</p>
            </article>
        @endforeach
    </div>
    @can('manage-lots')
        @if(count($transitions))
            <form method="POST" action="{{ route('lots.transition', $lot) }}" class="mt-8 rounded-2xl border border-[#dfe4da] bg-white p-6">
                @csrf<input type="hidden" name="version" value="{{ $lot->version }}">
                <h2 class="text-xl font-semibold">Revisar estado</h2>
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <div><label for="result" class="mb-2 block text-sm font-semibold">Acción</label><select id="result" name="result" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3">@foreach($transitions as $status)<option value="{{ $status }}">{{ \App\Models\Lot::STATUSES[$status] }}</option>@endforeach</select></div>
                    <x-field name="reason" label="Motivo de la decisión" minlength="5" maxlength="1000" required />
                </div>
                <p class="mt-4 text-xs leading-5 text-[#6f7d66]">Pausar conserva las porciones. Cancelar libera el saldo sin operaciones vinculadas y conserva el historial; no se puede reactivar un lote cancelado.</p>
                <button class="mt-5 rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white">Registrar decisión</button>
            </form>
        @endif
    @endcan
    <h2 class="mt-8 text-xl font-semibold">Historial del lote</h2>
    <ol class="mt-4 divide-y divide-[#dfe4da]">
        @foreach($lot->histories as $history)
            <li class="py-4 text-sm"><p class="font-semibold">{{ match($history->action) { 'created' => 'Lote formado', 'expired' => 'Disponibilidad vencida', 'available_balance_grouped' => 'Saldo disponible agrupado en otro lote', default => 'Estado actualizado' } }} · versión {{ $history->lot_version }}</p><p class="mt-2 leading-6 text-[#6f7d66]">{{ $history->reason }}</p><p class="mt-2 text-xs text-[#819077]">{{ $history->actor?->name ?? 'Sistema' }} · {{ $history->occurred_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}</p></li>
        @endforeach
    </ol>
</x-layout>

