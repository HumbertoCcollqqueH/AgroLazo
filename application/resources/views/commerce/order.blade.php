@php
    $displayPermissions = auth()->user()->permissionCodes();
@endphp
<x-layout :title="$order->code">
    <a class="text-sm underline" href="{{ route('commerce.index') }}">← Compras</a>
    <div class="mb-8 mt-5 flex flex-wrap justify-between gap-5"><div><p class="text-xs uppercase tracking-widest text-[#819077]">{{ $order->synthetic && config('commerce.show_test_labels') ? 'Orden sintética de prueba' : 'Orden' }}</p><h1 class="mt-2 text-3xl font-semibold">{{ $order->code }}</h1><p class="mt-3"><x-commerce-status :value="$order->status" /> · Versión {{ $order->version }}</p></div><div><p class="text-sm text-[#819077]">Total vigente</p><p class="mt-2 text-2xl font-semibold">S/ {{ \App\Services\Money::format($order->total_minor) }}</p><p class="mt-2 text-xs">Original: S/ {{ \App\Services\Money::format($order->original_total_minor) }}</p></div></div>
    @php
        $activeCollection = $collections->firstWhere('status', 'scheduled');
        $hasOpenIncidents = $incidents->contains('status', 'open');
        $operator = $displayPermissions->contains('manage-commerce');
        $nextStep = match (true) {
            $order->status === 'cancelled' => 'Compra cancelada. Revisa los ajustes y cualquier devolución pendiente.',
            $order->status === 'closed' => 'Operación completada: entrega y saldos conciliados.',
            $hasOpenIncidents => 'Hay una incidencia pendiente. Coordinación debe resolver las diferencias antes de completar la operación.',
            $order->status === 'confirmed' => $operator ? 'Programa el punto y horario de acopio. Al guardar, cada productor recibirá los datos de su entrega.' : 'Coordinación está pendiente de confirmar el punto y horario de acopio. Aquí podrás consultar la programación.',
            $order->status === 'collecting' => $operator ? 'Registra el pesaje y la calidad de cada entrega. Después consolida las cantidades aceptadas y prepara el despacho.' : 'El equipo está reuniendo y pesando el producto. Cualquier cambio de tu compra requiere tu aceptación.',
            $order->status === 'ready' => 'Mercancía lista para despacho. Falta comprobar el pago o crédito autorizado y registrar la salida.',
            $order->status === 'in_transit' => 'Mercancía en tránsito. Falta registrar la entrega al comprador.',
            $order->status === 'delivered' => 'Entrega registrada. Falta conciliar el cobro, la liquidación y el pago a cada productor antes de cerrar.',
            default => 'Consulta el historial y los avisos para seguir la operación.',
        };
    @endphp
    <x-commerce-panel title="Qué sigue con tu compra" class="mb-6">
        @if($order->synthetic && config('commerce.show_test_labels'))
            <p class="mb-5 rounded-xl bg-amber-50 p-4 text-sm font-semibold">{{ \App\Services\OperationalNotifier::testWhatsAppEnabled() ? 'Compra de prueba con avisos reales por WhatsApp etiquetados como PRUEBA. No preparar producto, trasladar mercancía ni transferir dinero.' : 'Compra de prueba: avisos únicamente en el simulador, sin traslado ni pago real.' }}</p>
        @endif
        <p class="text-base font-semibold">{{ $nextStep }}</p>
        @if($order->requested_delivery_on)<div class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm"><strong>Entrega solicitada: {{ $order->requested_delivery_on->format('d/m/Y') }}</strong><p class="mt-2">Transporte organizado y cubierto por {{ config('app.name') }}. Coordinación confirma el horario; los productores reciben en su cuenta la fecha solicitada y la programación de recojo.</p></div>@endif
        <ol class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-5">
            <li><strong>1. Compra confirmada</strong><p class="mt-2 text-[#6f7d66]">Las cantidades compradas quedan comprometidas. Se prepara un aviso con la orden, el producto y la cantidad de cada productor.</p></li>
            <li><strong>2. Acopio programado</strong><p class="mt-2 text-[#6f7d66]">Coordinación confirma lugar, dirección y horario; el sistema envía los detalles de entrega.</p></li>
            <li><strong>3. Pesaje y recepción</strong><p class="mt-2 text-[#6f7d66]">Se registra lo recibido, aceptado y rechazado. Solo la mercancía aceptada se consolida para despacho.</p></li>
            <li><strong>4. Despacho y entrega</strong><p class="mt-2 text-[#6f7d66]">Se comprueba el pago o crédito autorizado, se registra la salida y luego la entrega al comprador.</p></li>
            <li><strong>5. Liquidación y cierre</strong><p class="mt-2 text-[#6f7d66]">Se calcula lo correspondiente a cada productor y se confirma su desembolso antes de cerrar la orden.</p></li>
        </ol>
        <div class="mt-5 grid gap-4 rounded-xl bg-[#f5f7f1] p-4 text-sm sm:grid-cols-2">
            <div><strong>Acopio:</strong>
                @if($activeCollection)
                    <p class="mt-2">{{ $activeCollection->point_snapshot['name'] }} · {{ $activeCollection->point_snapshot['address'] }}</p>
                    <p class="mt-1">{{ $activeCollection->starts_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}–{{ $activeCollection->ends_at->setTimezone('America/Lima')->format('H:i') }} · hora de Perú</p>
                @elseif(in_array($order->status, ['confirmed', 'collecting']))
                    <p class="mt-2">Punto y horario pendientes de confirmar.</p>
                @else
                    <p class="mt-2">Consulta las recepciones y el historial de acopio.</p>
                @endif
            </div>
            <div><strong>Pago del comprador:</strong><p class="mt-2">{{ $financialBalance['buyer_balance_minor'] > 0 ? 'Pendiente de verificar: S/ '.\App\Services\Money::format($financialBalance['buyer_balance_minor']).'. Confirmar la compra no acredita el pago.' : ($financialBalance['buyer_balance_minor'] < 0 ? 'Hay un excedente por conciliar. Revisa los cobros y devoluciones.' : 'El importe de la compra está cubierto por ingresos confirmados.') }}</p></div>
        </div>
        @if($operator && in_array($order->status, ['confirmed', 'collecting']))
            <a class="mt-5 inline-block rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white" href="#programar-acopio">{{ $activeCollection ? 'Revisar programación y recepción' : 'Programar acopio' }}</a>
            @if($suggestion['points']->isEmpty())<p class="mt-3 text-sm">Primero <a class="underline" href="{{ route('fulfillment.points') }}">registra un punto de acopio</a> con dirección, capacidad y horario.</p>@endif
        @endif
    </x-commerce-panel>
    <x-commerce-panel title="Dirección de entrega al comprador" class="mb-6">
        @include('commerce.delivery-address-display', ['destination' => $order->delivery_address])
        @if(in_array($order->status, ['confirmed', 'collecting', 'ready']) && $shipments->isEmpty())
            <details class="mt-5" @if(! $order->delivery_address || $errors->has('delivery_address*')) open @endif>
                <summary class="cursor-pointer font-semibold">{{ $order->delivery_address ? 'Corregir destino antes del despacho' : 'Completar destino de esta compra' }}</summary>
                <form method="POST" action="{{ route('commerce.orders.delivery-address', $order) }}" class="mt-5 space-y-5">
                    <x-commerce-key /><input type="hidden" name="version" value="{{ $order->version }}">
                    @include('commerce.delivery-address-fields', ['destination' => $order->delivery_address ?? []])
                    <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Guardar destino de entrega</button>
                </form>
            </details>
        @endif
    </x-commerce-panel>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-commerce-panel title="Producto y cantidades">
            @foreach($order->items as $item)<h3 class="font-semibold">{{ $item->product_snapshot['product'] }} · {{ $item->product_snapshot['variety'] }}</h3><p class="mt-2 text-sm">{{ $item->product_snapshot['location'] }} · {{ $item->quantity }} {{ $item->product_snapshot['unit'] }} originales</p>
                @foreach($item->allocations as $allocation)<div class="mt-4 rounded-xl bg-[#f5f7f1] p-4 text-sm"><p>Aportación #{{ $allocation->id }} · {{ bcsub($allocation->quantity, $allocation->released_quantity, 3) }} {{ $item->product_snapshot['unit'] }} vigentes</p>@if($displayPermissions->contains('manage-commerce'))<p class="mt-2">{{ $allocation->portion->offer->producer->full_name }} · Oferta #{{ $allocation->portion->offer_id }} · S/ {{ $allocation->gross_unit_price }} brutos por unidad</p>@endif</div>@endforeach
            @endforeach
            <p class="mt-6 text-sm">{{ $order->delivery_details }}</p><a class="mt-5 inline-block text-sm underline" href="{{ route('commerce.quotes.show', $order->quote) }}">Ver cotización y condiciones originales</a>
        </x-commerce-panel>
        <x-commerce-panel title="Ajustes acordados">
            @forelse($order->adjustments as $adjustment)<div class="mb-5 rounded-xl border border-[#dfe4da] p-4 text-sm"><p class="font-semibold">Ajuste #{{ $adjustment->id }} · <x-commerce-status :value="$adjustment->status" /></p><p class="mt-2">{{ $adjustment->reason }}</p><p class="mt-2">Reducción: S/ {{ \App\Services\Money::format($adjustment->reduction_minor) }}</p>@foreach($adjustment->items as $change)<p class="mt-2">Aportación #{{ $change->order_allocation_id }}: {{ $change->quantity }} menos. {{ $change->retire ? 'Se retira del inventario comercial.' : 'Se libera producto existente y vendible.' }}</p>@endforeach
                @if($adjustment->status === 'proposed' && $order->buyer->user_id === auth()->id())<form method="POST" action="{{ route('commerce.adjustments.accept', $adjustment) }}" class="mt-4 space-y-3"><x-commerce-key /><label class="flex gap-2"><input type="checkbox" name="accepted" value="1" required>Acepto la reducción de cantidad y el nuevo importe.</label><button class="rounded-xl bg-[#22583e] px-4 py-3 text-white">Aceptar ajuste</button></form>@endif</div>
            @empty<p class="text-sm text-[#6f7d66]">No hay ajustes. Las cantidades se conservan según la compra.</p>@endforelse
            @if($displayPermissions->contains('manage-commerce'))@if(! in_array($order->status, ['closed', 'cancelled']))<details class="mt-6 border-t border-[#dfe4da] pt-5"><summary class="cursor-pointer font-semibold">Proponer reducción o resolver faltante</summary><form method="POST" action="{{ route('commerce.reductions.store', $order) }}" class="mt-5 space-y-4"><x-commerce-key /><input type="hidden" name="version" value="{{ $order->version }}"><x-commerce-select name="allocation_id" label="Aportación" required>@foreach($order->items as $item)@foreach($item->allocations as $allocation)<option value="{{ $allocation->id }}">#{{ $allocation->id }} · {{ $allocation->portion->offer->producer->full_name }}</option>@endforeach @endforeach</x-commerce-select><x-field name="quantity" label="Cantidad a reducir" type="number" step="0.001" min="0.001" required /><x-commerce-select name="retire" label="Destino de la cantidad reducida" required><option value="1">Retirar: faltante o producto no utilizable</option><option value="0">Liberar: producto existente y vendible</option></x-commerce-select><x-field name="reason" label="Motivo y comprobación de la mercancía" required minlength="10" /><button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Proponer al comprador</button></form></details>@endif @endif
        </x-commerce-panel>
    </div>
    @includeIf('commerce.fulfillment')
    @includeIf('commerce.payments')
    @includeIf('commerce.traceability')
    <x-commerce-panel title="Historial comercial" class="mt-6">
        <ol class="space-y-4">@foreach($order->events as $event)<li class="border-l-2 border-[#cdd8c7] pl-4 text-sm"><time class="text-xs text-[#819077]">{{ $event->occurred_at->setTimezone('America/Lima')->format('d/m/Y H:i:s') }}</time><p class="mt-1">{{ config('commerce.event_labels.'.$event->action, 'Actualización registrada') }}</p></li>@endforeach</ol>
    </x-commerce-panel>
</x-layout>
