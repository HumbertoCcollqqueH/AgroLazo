<x-layout title="Publicar trazabilidad">
    <a class="text-sm underline" href="{{ route('lots.show', $lot) }}">← Lote {{ $lot->code }}</a>
    <h1 class="mt-5 text-3xl font-semibold">Trazabilidad pública y QR</h1>
    <p class="mt-3 max-w-3xl text-[#6f7d66]">Revisa la ficha antes de compartirla. Se publica una fotografía de los registros a esta fecha. Una publicación nueva conserva la anterior hasta que la revoques.</p>
    <form method="GET" class="my-7 flex flex-wrap gap-4 items-end">
        <x-commerce-select name="physical_lot_id" label="Origen de la ficha"><option value="">Composición comercial {{ $lot->code }}</option>@foreach($physicalLots as $entry)<option value="{{ $entry->id }}" @selected($physical?->id === $entry->id)>Recepción física {{ $entry->code }}</option>@endforeach</x-commerce-select>
        <button class="rounded-xl border px-5 py-3">Ver vista previa</button>
    </form>
    <x-commerce-panel title="Vista previa de los datos públicos">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt>Identificación</dt><dd class="font-semibold">{{ $preview['code'] }}</dd></div>
            <div><dt>Producto</dt><dd>{{ $preview['product'] }} · {{ $preview['variety'] }}</dd></div>
            <div><dt>Calidad y procedencia general</dt><dd>{{ $preview['quality'] }} · {{ $preview['region'] }}</dd></div>
            <div><dt>Cantidad y fuentes</dt><dd>{{ $preview['quantity'] }} {{ $preview['unit'] }} · {{ $preview['source_count'] }} productores</dd></div>
        </dl>
        <p class="mt-5 text-sm">{{ $physical ? 'La ficha incluye la consolidación física y las cantidades entregadas, devueltas o perdidas registradas hasta ahora.' : 'La ficha indica expresamente que la composición comercial no acredita recepción física.' }}</p>
        <form method="POST" action="{{ route('trace.publish', $lot) }}" class="mt-6 space-y-4"><x-commerce-key />@if($physical)<input type="hidden" name="physical_lot_id" value="{{ $physical->id }}">@endif
            <label class="flex gap-3 text-sm"><input type="checkbox" name="accepted" value="1" required>Revisé estos datos y autorizo su publicación mediante enlace compartible.</label>
            <button class="rounded-xl bg-[#22583e] px-5 py-3 text-white">Publicar ficha y generar QR</button>
        </form>
    </x-commerce-panel>
    <h2 class="mt-10 mb-5 text-2xl font-semibold">Publicaciones</h2>
    <div class="grid gap-6 md:grid-cols-2">
        @forelse($publications as $publication)
            <x-commerce-panel :title="$publication->public_snapshot['code']">
                <p class="text-sm">{{ $publication->published_at->setTimezone('America/Lima')->format('d/m/Y H:i') }} · {{ $publication->revoked_at ? 'Revocada' : 'Disponible mediante enlace' }}</p>
                @if(! $publication->revoked_at)
                    <a href="{{ route('trace.show', $publication->token) }}" class="mt-5 inline-block underline">Abrir ficha pública</a>
                    <img src="{{ route('trace.qr', $publication->token) }}" alt="QR de esta publicación" width="225" height="225" class="my-5 max-w-full">
                    <a class="text-sm underline" href="{{ route('trace.qr', $publication->token) }}" download="agrolazo-{{ $publication->public_snapshot['code'] }}.svg">Descargar QR</a>
                    <p class="mt-3 text-xs break-all text-[#6f7d66]">{{ app(\App\Services\TraceabilityService::class)->publicUrl($publication->token) }}</p>
                    <form method="POST" action="{{ route('trace.revoke', $publication) }}" class="mt-5 space-y-3"><x-commerce-key /><x-field name="reason" label="Motivo de revocación" required minlength="10" /><button class="rounded-xl border px-4 py-2">Revocar enlace y QR</button></form>
                @else
                    <p class="mt-4 text-sm">{{ $publication->revocation_reason }}</p>
                @endif
            </x-commerce-panel>
        @empty
            <p>No hay fichas publicadas.</p>
        @endforelse
    </div>
</x-layout>

