<x-layout title="Trazabilidad de origen">
    <div class="max-w-3xl mx-auto">
        <p class="text-sm uppercase tracking-widest text-[#668358]">{{ $trace['synthetic'] && config('commerce.show_test_labels') ? 'Demostración con datos sintéticos' : 'Ficha de trazabilidad' }}</p>
        <h1 class="mt-4 text-4xl font-semibold">{{ $trace['product'] }} · {{ $trace['variety'] }}</h1>
        <p class="mt-3 text-lg text-[#6f7d66]">{{ $trace['code'] }} · {{ $trace['kind'] === 'physical_lot' ? 'Lote físico' : 'Lote comercial' }}</p>
        <div class="my-8 rounded-2xl bg-[#e8efdf] p-6">
            <p class="font-semibold">{{ $trace['physical_receipt_confirmed'] ? 'Origen vinculado a recepciones y pesajes registrados' : 'Composición comercial registrada' }}</p>
            <p class="mt-2 text-sm">{{ $trace['physical_receipt_confirmed'] ? 'La cantidad corresponde a mercancía aceptada y consolidada en el acopio.' : 'Esta ficha describe la agrupación de ofertas. Aún no acredita recepción física ni existencia de un embarque.' }}</p>
        </div>
        <dl class="grid grid-cols-2 gap-6">
            <div><dt class="text-sm text-[#6f7d66]">Cantidad de la ficha</dt><dd class="mt-2 text-2xl font-semibold">{{ $trace['quantity'] }} {{ $trace['unit'] }}</dd></div>
            <div><dt class="text-sm text-[#6f7d66]">Procedencia general</dt><dd class="mt-2 text-xl">{{ $trace['region'] }}</dd></div>
            <div><dt class="text-sm text-[#6f7d66]">Calidad registrada</dt><dd class="mt-2">{{ $trace['quality'] }}</dd></div>
            <div><dt class="text-sm text-[#6f7d66]">Productores de origen</dt><dd class="mt-2">{{ $trace['source_count'] }}</dd></div>
        </dl>
        <x-commerce-panel title="Recorrido registrado" class="mt-8">
            <ol class="space-y-5">
                @foreach($trace['milestones'] as $milestone)
                    <li class="border-l-2 border-[#98b887] pl-4"><p class="text-xs text-[#6f7d66]">{{ $milestone['date'] }}</p><p class="mt-1 font-semibold">{{ ['commercial_composition' => 'Composición comercial', 'physical_consolidation' => 'Recepción y consolidación física', 'delivered' => 'Entrega registrada', 'returned' => 'Devolución registrada', 'lost' => 'Pérdida registrada'][$milestone['event']] ?? 'Movimiento registrado' }}</p><p class="mt-1 text-sm">{{ $milestone['quantity'] }} {{ $trace['unit'] }}</p></li>
                @endforeach
            </ol>
        </x-commerce-panel>
        <p class="mt-6 text-sm text-[#6f7d66]">Ficha publicada el {{ \Carbon\CarbonImmutable::parse($trace['recorded_at'])->setTimezone('America/Lima')->format('d/m/Y H:i') }} (Perú). Refleja los registros a esa fecha; no es una certificación externa ni una oferta de stock disponible.</p>
    </div>
</x-layout>

