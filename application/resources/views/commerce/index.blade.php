@php($displayPermissions = auth()->user()->permissionCodes())
<x-layout :title="$operator ? 'Pedidos y logística' : 'Compras y cotizaciones'">
    @if($operator)
        <header class="mb-7 flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-widest text-slate-500">Seguimiento de la operación</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Pedidos y logística</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Abre un pedido para coordinar acopio, revisar la recepción y acompañar la entrega. Cada operación conserva su historial.</p></div><a class="inline-flex items-center gap-2 rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white" href="{{ route('fulfillment.points') }}"><x-ac-icon name="pin" />Puntos de acopio</a></header>
        <div class="mb-6 flex flex-wrap gap-3"><span class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm"><strong>{{ $orders->total() }}</strong> pedidos registrados</span><a class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold" href="{{ route('reports.index', ['report' => 'logistics']) }}"><x-ac-icon name="truck" />Reporte de logística</a>@if($displayPermissions->contains('confirm-payments'))<a class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold" href="{{ route('reports.index', ['report' => 'payments']) }}"><x-ac-icon name="wallet" />Cobros y conciliación</a>@endif</div>
    @else
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Gestión comercial</p><h1 class="mt-3 text-3xl font-semibold">Mis compras y cotizaciones</h1></div><a class="rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white" href="{{ route('marketplace.index') }}">Explorar lotes</a></div>
    @endif
    @if(config('commerce.show_test_labels') && ! config('commerce.live_enabled'))<p class="mb-6 rounded-xl bg-[#e9eddf] p-4 text-sm">Compras en modo de prueba: los avisos al productor se procesan en el simulador y no se envían por WhatsApp o SMS reales. No se deben realizar pagos ni entregas reales.</p>@endif
    @if($notifications->isNotEmpty())
        <x-commerce-panel title="Mis avisos recientes" class="mb-6">
            <ul class="space-y-3">@foreach($notifications as $notification)<li class="text-sm"><a class="underline" href="{{ $notification->data['url'] }}">{{ $notification->data['body'] }}</a><time class="ml-2 text-xs text-[#6f7d66]">{{ $notification->created_at->setTimezone('America/Lima')->format('d/m H:i') }}</time></li>@endforeach</ul>
        </x-commerce-panel>
    @endif
    <x-commerce-panel title="Órdenes">
        <div class="divide-y divide-[#dfe4da]">@forelse($orders as $order)<a class="flex flex-wrap justify-between gap-3 py-5 hover:underline" href="{{ route('commerce.orders.show', $order) }}"><span class="font-semibold">{{ $order->code }} @if($order->synthetic && config('commerce.show_test_labels'))<small>· Prueba</small>@endif</span><span><x-commerce-status :value="$order->status" /> · S/ {{ \App\Services\Money::format($order->total_minor) }}</span></a>@empty<p class="py-6 text-[#6f7d66]">{{ $operator ? 'Todavía no hay pedidos. Cuando un comprador confirme su compra, podrás coordinarla desde aquí.' : 'Todavía no hay órdenes. Empieza cotizando una cantidad del marketplace.' }}</p>@endforelse</div>
        <div class="mt-5">{{ $orders->links() }}</div>
    </x-commerce-panel>
    <x-commerce-panel title="Cotizaciones recientes" class="mt-6">
        <div class="divide-y divide-[#dfe4da]">@forelse($quotes as $quote)<a class="flex flex-wrap justify-between gap-3 py-4 hover:underline" href="{{ route('commerce.quotes.show', $quote) }}"><span>{{ $quote->code }} · {{ $quote->quantity }} {{ $quote->baseUnit->code }}</span><span>S/ {{ \App\Services\Money::format($quote->total_minor) }} · {{ $quote->status === 'issued' && $quote->expires_at->isPast() ? 'Vencida' : config('commerce.status_labels.'.$quote->status, $quote->status) }}</span></a>@empty<p class="text-[#6f7d66]">{{ $operator ? 'Aquí podrás consultar las últimas cotizaciones de los compradores.' : 'Las cotizaciones que emitas aparecerán aquí.' }}</p>@endforelse</div>
    </x-commerce-panel>
</x-layout>

