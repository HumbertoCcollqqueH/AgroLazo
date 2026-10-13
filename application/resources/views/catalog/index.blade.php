<x-layout :title="$definition['title']">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl"><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Base de la operación</p><h1 class="mt-3 text-3xl font-semibold tracking-tight">Catálogo de {{ mb_strtolower($definition['title']) }}</h1><p class="mt-3 text-sm leading-6 text-[#6f7d66]">{{ $definition['description'] }}</p></div>
        @can('manage-catalogs')<a href="{{ route('catalog.create', $catalog) }}" class="rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173f2c]">Añadir {{ $definition['singular'] }} <span aria-hidden="true">+</span></a>@endcan
    </div>
    <nav aria-label="Catálogos" class="mb-7 flex flex-wrap gap-2">
        @foreach($registry->definitions() as $key => $item)
            <a href="{{ route('catalog.index', $key) }}" @if($key === $catalog) aria-current="page" @endif class="rounded-full border px-4 py-2 text-sm font-semibold {{ $key === $catalog ? 'border-[#22583e] bg-[#22583e] text-white' : 'border-[#d5dfce] bg-white text-[#627057] hover:bg-[#edf2e7]' }}">{{ $item['title'] }}</a>
        @endforeach
    </nav>
    @if($catalog === 'conversions')
        <section class="mb-7 rounded-2xl border border-[#cfdcbf] bg-[#edf2e7] p-6" aria-labelledby="normalizer-title">
            <h2 id="normalizer-title" class="text-lg font-semibold">Comprobar una cantidad</h2>
            <p class="mt-2 text-sm leading-6 text-[#627057]">El resultado usa la unidad base del producto y hasta tres decimales. Esta comprobación no registra una oferta.</p>
            @if(session('normalization'))
                @php($normalization = session('normalization'))
                <div role="status" class="mt-4 rounded-xl bg-white p-4"><p class="text-lg font-semibold">{{ $normalization['input'] }} = {{ $normalization['quantity'] }} {{ $normalization['unit'] }}</p><p class="mt-2 text-sm text-[#627057]">{{ $normalization['product'] }} · {{ $normalization['source'] }}</p></div>
            @endif
            <form method="POST" action="{{ route('catalog.normalize') }}" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">@csrf
                <div><label for="normalize-product" class="mb-2 block text-sm font-semibold">Producto</label><select id="normalize-product" name="product_id" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-3 py-3"><option value="">Selecciona un producto</option>@foreach($normalizationProducts as $value => $label)<option value="{{ $value }}" @selected(old('product_id') == $value)>{{ $label }}</option>@endforeach</select></div>
                <x-field name="quantity" label="Cantidad" required inputmode="decimal" placeholder="80.000" maxlength="19" />
                <div><label for="normalize-unit" class="mb-2 block text-sm font-semibold">Unidad de origen</label><select id="normalize-unit" name="from_unit_id" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-3 py-3">@foreach($normalizationUnits as $value => $label)<option value="{{ $value }}" @selected(old('from_unit_id') == $value)>{{ $label }}</option>@endforeach</select></div>
                <x-field name="context" label="Contexto (para equivalencias aprobadas)" maxlength="120" placeholder="saco-papa-50kg" />
                <x-field name="date" label="Fecha de referencia" type="date" :value="now()->timezone('America/Lima')->toDateString()" required />
                <div class="flex items-end"><button type="submit" class="w-full rounded-xl border border-[#aebb9e] bg-white px-5 py-3 font-semibold hover:bg-[#f7f9f3]">Calcular equivalencia →</button></div>
            </form>
        </section>
    @endif
    <section class="rounded-3xl border border-[#dfe4da] bg-white p-5 sm:p-7">
        <form method="GET" action="{{ route('catalog.index', $catalog) }}" class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-40 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">{{ $catalog === 'conversions' ? 'Buscar contexto' : 'Buscar nombre' }}</label><input id="q" type="search" name="q" value="{{ request('q') }}" maxlength="100" class="w-full rounded-xl border border-[#cdd8c7] px-4 py-2.5"></div>
            <div><label for="status" class="mb-2 block text-sm font-semibold">Estado</label><select id="status" name="status" class="rounded-xl border border-[#cdd8c7] bg-white px-4 py-2.5"><option value="">Todos</option><option value="active" @selected(request('status') === 'active')>Activo</option><option value="inactive" @selected(request('status') === 'inactive')>Desactivado</option></select></div>
            <button class="rounded-xl border border-[#c4d1bb] bg-[#f1f5ec] px-5 py-2.5 text-sm font-semibold" type="submit">Buscar</button>
            @if(request()->filled('q') || request()->filled('status'))<a href="{{ route('catalog.index', $catalog) }}" class="px-2 py-3 text-sm underline">Limpiar</a>@endif
        </form>
        <div class="divide-y divide-[#e9ede3]">
            @forelse($records as $record)
                <article class="flex flex-wrap items-start justify-between gap-4 py-5">
                    <div class="min-w-0 flex-1">
                        <h2 class="break-words font-semibold">{{ $catalog === 'conversions' ? $record->product->name : $record->name }}</h2>
                        <p class="mt-2 break-words text-sm leading-6 text-[#6f7d66]">{{ $registry->summary($catalog, $record) }}</p>
                        @if($catalog === 'conversions')<p class="mt-2 text-xs leading-5 text-[#788373]">{{ $record->valid_from->format('d/m/Y') }} — {{ $record->valid_until?->format('d/m/Y') ?? 'Sin fecha final' }} · {{ $record->source }}</p>@endif
                        @if($catalog === 'qualities' && $record->criteria)<p class="mt-2 text-sm text-[#788373]">Criterios: {{ $record->criteria }}</p>@endif
                        @if(($catalog === 'products' && !$record->isUsable() && $record->active) || (in_array($catalog, ['varieties', 'qualities', 'conversions']) && !$record->product->isUsable()) || ($catalog === 'locations' && !$record->isUsable() && $record->active))
                            <p class="mt-2 text-xs font-semibold text-amber-800">No disponible para nuevas operaciones: revisa sus referencias.</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3"><span class="rounded-full px-3 py-1.5 text-xs font-semibold {{ $record->active ? 'bg-[#eaf1e3] text-[#4c6c3c]' : 'bg-stone-100 text-stone-600' }}">{{ $record->active ? 'Activo' : 'Desactivado' }}</span>@can('manage-catalogs')<a href="{{ route('catalog.edit', [$catalog, $record->id]) }}" class="text-sm font-semibold underline underline-offset-4" aria-label="Editar {{ $catalog === 'conversions' ? $record->context : $record->name }}">Editar</a>@endcan</div>
                </article>
            @empty
                <div class="py-12 text-center"><p class="text-lg font-semibold">No hay registros para mostrar</p><p class="mt-3 text-sm text-[#6f7d66]">Revisa los filtros o añade el primer registro de este catálogo.</p></div>
            @endforelse
        </div>
        <div class="mt-6">{{ $records->links() }}</div>
    </section>
    <p class="mt-5 text-xs leading-5 text-[#7b8673]">Desactivar impide su uso en nuevas operaciones y conserva las referencias existentes. Las equivalencias comerciales quedan asociadas a su fuente y presentación.</p>
</x-layout>

