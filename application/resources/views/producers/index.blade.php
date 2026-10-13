<x-layout title="Productores">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl">
            <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Personas que hacen crecer el campo</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">Productores</h1>
            <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Registra a cada productor y acompaña su verificación. Su ficha y datos de contacto están disponibles solo para el equipo autorizado.</p>
        </div>
        @can('manage-producers')<a href="{{ route('producers.create') }}" class="rounded-xl bg-[#22583e] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173f2c]">Registrar productor +</a>@endcan
    </div>
    <section class="rounded-3xl border border-[#dfe4da] bg-white p-5 sm:p-7">
        <form method="GET" action="{{ route('producers.index') }}" class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1fr_auto]">
            <div><label for="q" class="mb-2 block text-sm font-semibold">Buscar por nombre</label><input id="q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" class="w-full rounded-xl border border-[#cdd8c7] px-4 py-3"></div>
            <div><label for="status" class="mb-2 block text-sm font-semibold">Estado</label><select id="status" name="status" class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Todos los estados</option>@foreach(\App\Models\Producer::STATUSES as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="type" class="mb-2 block text-sm font-semibold">Tipo de productor</label><select id="type" name="type" class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Todos los tipos</option>@foreach(\App\Models\Producer::TYPES as $key => $label)<option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
            <button type="submit" class="self-end rounded-xl border border-[#c4d1bb] bg-[#f1f5ec] px-5 py-3 font-semibold">Buscar</button>
        </form>
        <div class="mb-2 flex items-center justify-between text-xs text-[#6f7d66]"><p>{{ $producers->total() }} {{ $producers->total() === 1 ? 'productor registrado' : 'productores registrados' }}</p>@if(array_filter($filters))<a href="{{ route('producers.index') }}" class="underline underline-offset-4">Limpiar filtros</a>@endif</div>
        <div class="divide-y divide-[#e9ede3]">
            @forelse($producers as $producer)
                <article class="flex flex-wrap items-center justify-between gap-4 py-6">
                    <div class="min-w-0 flex-1">
                        <h2 class="break-words text-lg font-semibold"><a class="hover:underline" href="{{ route('producers.show', $producer) }}">{{ $producer->full_name }}</a></h2>
                        <p class="mt-1 text-sm text-[#6f7d66]">{{ \App\Models\Producer::TYPES[$producer->type] }}</p>
                        <p class="mt-3 text-xs text-[#788373]">DNI ••••••{{ substr($producer->dni, -2) }} <span class="mx-2">·</span> Teléfono •••••••{{ substr($producer->phone, -3) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-4"><span class="rounded-full px-3 py-1.5 text-xs font-semibold {{ $producer->status === 'verified' ? 'bg-[#eaf1e3] text-[#4c6c3c]' : ($producer->status === 'pending_verification' ? 'bg-amber-50 text-amber-800' : 'bg-stone-100 text-stone-600') }}">{{ \App\Models\Producer::STATUSES[$producer->status] }}</span><a href="{{ route('producers.show', $producer) }}" class="text-sm font-semibold underline underline-offset-4" aria-label="Ver ficha de {{ $producer->full_name }}">Ver ficha →</a></div>
                </article>
            @empty
                <div class="py-12 text-center"><h2 class="text-lg font-semibold">{{ array_filter($filters) ? 'No hay productores con estos filtros' : 'El primer vínculo empieza aquí' }}</h2><p class="mx-auto mt-3 max-w-md text-sm leading-6 text-[#6f7d66]">{{ array_filter($filters) ? 'Prueba con otro nombre, estado o tipo de productor.' : 'Para registrar un productor necesitas su nombre completo, DNI, teléfono y tipo de productor.' }}</p></div>
            @endforelse
        </div>
        <div class="mt-6">{{ $producers->links() }}</div>
    </section>
    <p class="mt-5 text-xs leading-5 text-[#7b8673]">Cada alta comienza pendiente de verificación. Para publicar una oferta, verifica al productor y registra su aceptación de las condiciones económicas.</p>
</x-layout>
