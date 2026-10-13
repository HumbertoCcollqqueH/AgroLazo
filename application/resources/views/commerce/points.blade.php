<x-layout title="Puntos de acopio">
    <a class="text-sm underline" href="{{ route('commerce.index') }}">← Operaciones</a><h1 class="mb-8 mt-5 text-3xl font-semibold">Puntos de acopio</h1>
    <div class="grid gap-6 lg:grid-cols-2"><x-commerce-panel title="Puntos registrados">
        @forelse($points as $point)<article class="mb-5 rounded-xl bg-[#f5f7f1] p-5"><h3 class="font-semibold">{{ $point->name }}</h3><p class="mt-3 text-sm">{{ $point->address }} · {{ $point->location->name }}</p><p class="mt-3 text-sm">Capacidad por franja: {{ $point->capacity }} {{ $point->baseUnit->code }}.</p><p class="mt-2 text-sm">Horario diario: {{ $point->opens_at }}–{{ $point->closes_at }} · Perú.</p><p class="mt-2 text-xs">{{ $point->latitude === null ? 'Sin coordenadas; selección asistida.' : 'Coordenadas registradas para aproximar distancia.' }}</p></article>@empty<p>No hay puntos registrados. Añade una ubicación identificable con capacidad y horario.</p>@endforelse
        {{ $points->links() }}
    </x-commerce-panel><x-commerce-panel title="Registrar punto">
        <form method="POST" action="{{ route('fulfillment.points.store') }}" class="space-y-4"><x-commerce-key />
            <x-field name="name" label="Nombre del punto" required maxlength="160" />
            <x-field name="description" label="Descripción y condiciones de acceso" required minlength="5" />
            <x-field name="address" label="Dirección y referencia exacta" required minlength="10" />
            <x-commerce-select name="location_id" label="Localidad registrada">@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }} · {{ $location->type }}</option>@endforeach</x-commerce-select>
            <x-commerce-select name="base_unit_id" label="Unidad de capacidad">@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code }}</option>@endforeach</x-commerce-select>
            <x-field name="capacity" label="Capacidad simultánea por franja" type="number" min="0.001" step="0.001" required />
            <div class="grid grid-cols-2 gap-4"><x-field name="opens_at" label="Abre (Perú)" type="time" value="08:00" required /><x-field name="closes_at" label="Cierra (Perú)" type="time" value="18:00" required /></div>
            <x-location-picker />
            <div class="grid grid-cols-2 gap-4"><x-field name="latitude" label="Latitud" type="number" step="0.000001" min="-90" max="90" /><x-field name="longitude" label="Longitud" type="number" step="0.000001" min="-180" max="180" /></div>
            <button class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Guardar punto</button>
        </form>
    </x-commerce-panel></div>
</x-layout>

