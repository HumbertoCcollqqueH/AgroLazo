<x-layout title="Lotes comerciales">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Agrupación de ofertas</p><h1 class="mt-3 text-3xl font-semibold">Lotes comerciales</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-[#6f7d66]">Cada lote separa cantidades de ofertas compatibles. Pausar conserva las porciones; cancelar libera el saldo que no tenga compromisos.</p></div>
        @can('manage-lots')<a class="rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white" href="{{ route('lots.create') }}">Formar lote →</a>@endcan
    </div>
    <form method="GET" class="mb-6 flex flex-wrap items-end gap-4">
        <div><label for="status" class="mb-2 block text-sm font-semibold">Estado del lote</label><select id="status" name="status" class="rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Todos</option>@foreach(\App\Models\Lot::STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div>
        <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 text-sm font-semibold">Filtrar</button>
        <a class="px-2 py-3 text-sm underline" href="{{ route('marketplace.index') }}">Ver marketplace</a>
    </form>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($lots as $lot)
            <a class="rounded-2xl border border-[#dfe4da] bg-white p-6 transition hover:border-[#8fa980] focus:outline-2 focus:outline-offset-2 focus:outline-[#22583e]" href="{{ route('lots.show', $lot) }}">
                <div class="flex flex-wrap justify-between gap-3"><span class="text-xs font-semibold tracking-widest text-[#819077]">{{ $lot->code }}</span><span class="rounded-full bg-[#edf2e7] px-3 py-1 text-xs">{{ \App\Models\Lot::STATUSES[$lot->status] }}</span></div>
                <h2 class="mt-4 text-xl font-semibold">{{ $lot->product->name }}</h2>
                <p class="mt-2 text-sm text-[#6f7d66]">{{ $lot->location->name }} · {{ $lot->available_from->format('d/m/Y') }} – {{ $lot->available_until->format('d/m/Y') }}</p>
                <p class="mt-4 text-sm font-semibold">Revisar composición →</p>
            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-[#cdd8c7] p-8 text-sm leading-6 text-[#6f7d66]">Todavía no hay lotes con este filtro. Para formar uno necesitas ofertas publicadas, cantidades libres y condiciones aceptadas vigentes.</div>
        @endforelse
    </div>
    <div class="mt-6">{{ $lots->links() }}</div>
</x-layout>

