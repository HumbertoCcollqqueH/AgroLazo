<x-commerce-panel title="Trazabilidad de esta orden" class="mt-6">
    <p class="text-sm">Consulta las aportaciones, los pesajes de recepción y su vínculo con cada consolidación y despacho.</p>
    <a href="{{ route('trace.chain', $order) }}" class="mt-4 inline-block underline">Ver recorrido de origen a entrega</a>
    @if($displayPermissions->contains('manage-traceability'))
        <a href="{{ route('trace.manage', $order->quote->lot) }}" class="mt-4 ml-6 inline-block underline">Revisar ficha pública y QR</a>
    @endif
</x-commerce-panel>

