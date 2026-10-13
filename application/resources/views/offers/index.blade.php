<x-layout title="Ofertas agrícolas">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Del campo a una propuesta verificable</p><h1 class="mt-3 text-3xl font-semibold">Ofertas agrícolas</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-[#6f7d66]">Revisa cantidades, disponibilidad y condiciones con el productor antes de publicar. El saldo libre de las ofertas publicadas puede agruparse en lotes comerciales.</p></div>
        @can('manage-offers')<a class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white" href="{{ route('offers.create') }}">Registrar oferta +</a>@endcan
    </div>
    <section class="rounded-3xl border border-[#dfe4da] bg-white p-7">
        <form method="GET" class="mb-6 flex flex-wrap items-end gap-4">
            <div><label class="mb-2 block text-sm font-semibold" for="status">Estado</label><select class="rounded-xl border border-[#cdd8c7] p-3" id="status" name="status"><option value="">Todos</option>@foreach(\App\Models\Offer::STATUSES as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div><label class="mb-2 block text-sm font-semibold" for="product_id">Producto</label><select class="rounded-xl border border-[#cdd8c7] p-3" id="product_id" name="product_id"><option value="">Todos</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <button class="rounded-xl border border-[#c4d1bb] bg-[#f1f5ec] px-5 py-3">Filtrar</button><a class="py-3 text-sm underline" href="{{ route('offers.index') }}">Limpiar</a>
        </form>
        <div class="divide-y divide-[#e9ede3]">@forelse($offers as $offer)
            <article class="flex flex-wrap items-center justify-between gap-4 py-5"><div><h2 class="text-lg font-semibold"><a href="{{ route('offers.show', $offer) }}">Oferta #{{ $offer->id }} · {{ $offer->product->name }}</a></h2><p class="mt-2 text-sm text-[#6f7d66]">{{ $offer->producer->full_name }} · {{ $offer->location->name }}</p><p class="mt-2 text-sm">{{ $offer->normalized_quantity ? $offer->normalized_quantity.' '.$offer->baseUnit->code : 'Cantidad pendiente de conversión' }} · Disponible hasta {{ $offer->available_until->format('d/m/Y') }}</p></div><div class="flex items-center gap-4"><span class="rounded-full bg-[#edf2e7] px-3 py-2 text-xs font-semibold">{{ \App\Models\Offer::STATUSES[$offer->status] }}</span><a class="text-sm font-semibold underline" href="{{ route('offers.show', $offer) }}">Ver oferta →</a></div></article>
        @empty<div class="py-12 text-center"><h2 class="text-lg font-semibold">Todavía no hay ofertas con estos filtros</h2><p class="mt-3 text-sm text-[#6f7d66]">Empieza con una propuesta del productor. Su publicación requiere revisión y condiciones aceptadas.</p></div>@endforelse</div>
        <div class="mt-6">{{ $offers->links() }}</div>
    </section>
</x-layout>
