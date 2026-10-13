<x-layout :title="$offer ? 'Corregir oferta' : 'Registrar oferta'">
    <a class="text-sm font-semibold underline" href="{{ $offer ? route('offers.show', $offer) : route('offers.index') }}">← Volver a ofertas</a>
    <section class="mx-auto mt-7 max-w-3xl rounded-3xl border border-[#dfe4da] bg-white p-7">
        <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Registro asistido</p><h1 class="mt-3 text-3xl font-semibold">{{ $offer ? 'Corregir oferta #'.$offer->id : 'Registrar una oferta agrícola' }}</h1>
        <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Registra la cantidad tal como la declara el productor. La equivalencia se obtiene del catálogo; una presentación sin factor queda pendiente. Cada corrección exige nueva revisión.</p>
        <form method="POST" action="{{ $offer ? route('offers.update', $offer) : route('offers.store') }}" class="mt-7 space-y-5">@csrf
            @if($offer) @method('PATCH')<input type="hidden" name="version" value="{{ $offer->version }}">@else<input type="hidden" name="source_key" value="{{ old('source_key', (string) \Illuminate\Support\Str::uuid()) }}">@endif
            @unless($offer)<div><label class="mb-2 block text-sm font-semibold" for="producer_id">Productor</label><select id="producer_id" name="producer_id" required class="w-full rounded-xl border border-[#cdd8c7] p-3"><option value="">Selecciona un productor</option>@foreach($producers as $producer)<option value="{{ $producer->id }}" @selected(old('producer_id') == $producer->id)>{{ $producer->full_name }} · {{ \App\Models\Producer::STATUSES[$producer->status] }}</option>@endforeach</select></div>@endunless
            @foreach(['product_id' => ['Producto', $products], 'variety_id' => ['Variedad', $varieties], 'quality_grade_id' => ['Calidad', $qualities], 'location_id' => ['Localidad de origen', $locations], 'unit_id' => ['Unidad declarada', $units]] as $name => [$label, $records])
                <div><label for="{{ $name }}" class="mb-2 block text-sm font-semibold">{{ $label }}</label><select id="{{ $name }}" name="{{ $name }}" @required(!in_array($name, ['variety_id', 'quality_grade_id'])) class="w-full rounded-xl border border-[#cdd8c7] p-3"><option value="">Selecciona una opción</option>@foreach($records as $record)<option value="{{ $record->id }}" @selected((string) old($name, $offer?->getAttribute($name)) === (string) $record->id)>{{ in_array($name, ['variety_id', 'quality_grade_id']) ? $record->product->name.' · ' : '' }}{{ $record->name }}{{ $name === 'location_id' && $record->parent ? ' · '.$record->parent->name : '' }}</option>@endforeach</select></div>
            @endforeach
            <x-field name="quantity" label="Cantidad declarada" :value="$offer?->quantity" required inputmode="decimal" maxlength="19" hint="Usa punto decimal y hasta tres decimales. No uses separadores de millar." />
            <x-field name="context" label="Presentación o contexto de equivalencia" :value="$offer?->context" maxlength="120" hint="Solo si la unidad requiere una equivalencia aprobada, por ejemplo un tipo específico de saco." />
            <div class="grid gap-5 sm:grid-cols-2"><x-field name="available_from" label="Disponible desde" type="date" :value="$offer?->available_from?->format('Y-m-d')" required /><x-field name="available_until" label="Disponible hasta" type="date" :value="$offer?->available_until?->format('Y-m-d')" required /></div>
            <x-field name="requested_unit_price" label="Precio solicitado por unidad base (S/, opcional)" :value="$offer?->requested_unit_price" inputmode="decimal" hint="Es la expectativa del productor. Las condiciones aceptadas se registran después de verificar." />
            <x-field name="notes" label="Observaciones privadas (opcional)" :value="$offer?->notes" maxlength="2000" />
            <x-field name="reason" label="Motivo del registro o corrección" required minlength="5" maxlength="1000" />
            <button class="rounded-xl bg-[#22583e] px-6 py-3 font-semibold text-white">Guardar para revisión</button>
        </form>
    </section>
</x-layout>
