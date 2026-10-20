@if($participantContext)
    <details class="pp-participant rounded-2xl border border-slate-200 bg-white">
        <summary class="flex cursor-pointer list-none items-center gap-3 rounded-2xl p-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-lg font-bold text-emerald-900" aria-hidden="true">{{ mb_substr($participantContext['name'], 0, 1) }}</span>
            <span class="min-w-0 flex-1"><strong class="block break-words">{{ $participantContext['name'] }}</strong><span class="text-xs text-slate-500">{{ $participantContext['role'] }} · Ver ficha y pendientes</span></span>
            @if(count($participantContext['pending']))<span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">{{ count($participantContext['pending']) }} {{ count($participantContext['pending']) === 1 ? 'pendiente' : 'pendientes' }}</span>@endif
            <span aria-hidden="true">⌄</span>
        </summary>
        <div class="space-y-5 border-t border-slate-100 p-4 text-sm sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-3"><strong>Contexto para atender esta conversación</strong><span class="text-xs text-slate-500">Datos al abrir la conversación</span></div>
            @if($participantContext['producer'])
                <section class="rounded-xl bg-slate-50 p-4">
                    <h2 class="font-semibold">Ficha del productor · {{ $participantContext['producer']['status'] }}</h2>
                    @if($participantContext['producer']['name'] !== $participantContext['name'])<p class="mt-2">{{ $participantContext['producer']['name'] }}</p>@endif
                    <p class="mt-2"><strong>Produce:</strong> {{ implode(', ', $participantContext['producer']['products']) ?: 'Sin productos registrados' }}</p>
                    <p class="mt-2"><strong>Recojo:</strong> {{ $participantContext['producer']['location'] ?: 'Dirección pendiente' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $participantContext['producer']['has_coordinates'] ? 'Punto registrado en el mapa.' : 'Falta precisar el punto en el mapa.' }}</p>
                    <a class="pp-link mt-3 inline-block" href="{{ $participantContext['producer']['url'] }}">Abrir ficha completa →</a>
                </section>
            @endif
            @if($participantContext['buyer'])
                <section class="rounded-xl bg-slate-50 p-4">
                    <h2 class="font-semibold">{{ $participantContext['buyer']['type'] }} · {{ $participantContext['buyer']['status'] }}</h2>
                    <p class="mt-2"><strong>Destino habitual:</strong> {{ $participantContext['buyer']['destination'] ?: 'Aún no registrado' }}</p>
                    <p class="mt-1 text-xs text-slate-500">Revisa el destino específico de cada compra antes de programar su entrega.</p>
                </section>
            @endif
            @if($participantContext['pending'])
                <section><h2 class="font-semibold text-amber-900">Qué requiere atención</h2><ul class="mt-3 space-y-2">
                    @foreach($participantContext['pending'] as $pending)<li class="rounded-lg bg-amber-50 px-3 py-2 text-amber-950">{{ $pending['label'] }} @if($pending['url'])<a class="ml-1 font-semibold underline" href="{{ $pending['url'] }}">Revisar</a>@endif</li>@endforeach
                </ul></section>
            @endif
            @if($participantContext['offers'])
                <section><h2 class="font-semibold">Ofertas · {{ $participantContext['offer_count'] }} en su historial</h2><p class="mt-1 text-xs text-slate-500">Se muestran hasta 6, con prioridad a las que requieren atención.</p><div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach($participantContext['offers'] as $offer)
                        <article class="rounded-xl border border-slate-200 p-3"><div class="flex flex-wrap justify-between gap-2"><strong>#{{ $offer['id'] }} · {{ $offer['product'] }}</strong><span class="text-xs text-slate-500">{{ $offer['status'] }}</span></div><p class="mt-2">{{ $offer['quantity'] }} @if($offer['variety'])· {{ $offer['variety'] }}@endif</p><p class="mt-1 text-xs text-slate-500">{{ $offer['availability'] }}</p>@if($offer['price'])<p class="mt-2 font-semibold text-emerald-900">{{ $offer['price'] }}</p>@endif<p class="mt-2">{{ $offer['next'] }}</p><a class="pp-link mt-3 inline-block" href="{{ $offer['url'] }}">Revisar oferta y precio →</a></article>
                    @endforeach
                </div></section>
            @endif
            @if($participantContext['orders'])
                <section><h2 class="font-semibold">Pedidos relacionados · {{ $participantContext['active_order_count'] }} en curso</h2><div class="mt-3 space-y-3">
                    @foreach($participantContext['orders'] as $order)<article class="rounded-xl border border-slate-200 p-3"><div class="flex flex-wrap justify-between gap-2"><strong>{{ $order['code'] }}</strong><span class="text-xs text-slate-500">{{ $order['status'] }}</span></div>@if($order['products'])<p class="mt-2">{{ $order['products'] }}</p>@endif @if($order['delivery'])<p class="mt-1 text-xs text-slate-500">Entrega solicitada: {{ $order['delivery'] }}</p>@endif<p class="mt-2">{{ $order['next'] }}</p><a class="pp-link mt-3 inline-block" href="{{ $order['url'] }}">Abrir seguimiento →</a></article>@endforeach
                </div></section>
            @endif
            @if($participantContext['account_url'])<a class="pp-link inline-block" href="{{ $participantContext['account_url'] }}">Administrar cuenta y permisos →</a>@endif
        </div>
    </details>
@endif
