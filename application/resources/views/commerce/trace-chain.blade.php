<x-layout :title="'Recorrido · '.$order->code">
    <a href="{{ route('commerce.orders.show', $order) }}" class="text-sm underline">← Orden {{ $order->code }}</a>
    <h1 class="mt-5 text-3xl font-semibold">Del origen a la entrega</h1>
    <p class="mt-3 mb-8 text-[#6f7d66]">Cada recepción conserva su vínculo con la aportación comprada. Las cantidades rechazadas y las pérdidas permanecen en el historial.</p>
    <div class="space-y-6">
        @foreach($chain as $entry)
            <x-commerce-panel :title="'Aportación #'.$entry['allocation_id']">
                <p>{{ $entry['quantity'] }} originales · {{ $entry['released_quantity'] }} reducidos</p>
                @isset($entry['producer'])<p class="mt-3 font-semibold">{{ $entry['producer'] }} · Oferta #{{ $entry['offer_id'] }} · Acuerdo #{{ $entry['agreement_id'] }} · Versión {{ $entry['offer_version'] }}</p>@endisset
                <div class="mt-6 grid gap-6 lg:grid-cols-3">
                    <div><h3 class="font-semibold">1. Recepción</h3>@forelse($entry['receipts'] as $receipt)<p class="mt-3 text-sm">Pesaje #{{ $receipt['id'] }} · {{ $receipt['accepted_quantity'] }} aceptados · {{ $receipt['rejected_quantity'] }} rechazados</p>@empty<p class="mt-3 text-sm">Pendiente de recepción.</p>@endforelse</div>
                    <div><h3 class="font-semibold">2. Consolidación</h3>@forelse($entry['physical_lots'] as $physical)<p class="mt-3 text-sm">{{ $physical['code'] }} · {{ $physical['quantity'] }} del pesaje #{{ $physical['receipt_id'] }}</p>@empty<p class="mt-3 text-sm">Pendiente de consolidación.</p>@endforelse</div>
                    <div><h3 class="font-semibold">3. Despacho y entrega</h3>@forelse($entry['shipments'] as $shipment)<p class="mt-3 text-sm">{{ $shipment['code'] }} · {{ $shipment['quantity'] }} despachados · {{ $shipment['delivered_quantity'] }} entregados · {{ $shipment['returned_quantity'] }} devueltos · {{ $shipment['lost_quantity'] }} perdidos</p>@empty<p class="mt-3 text-sm">Pendiente de despacho.</p>@endforelse</div>
                </div>
            </x-commerce-panel>
        @endforeach
    </div>
</x-layout>

