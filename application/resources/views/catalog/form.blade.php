<x-layout :title="($record ? 'Editar ' : 'Añadir ').$definition['singular']">
    <a href="{{ route('catalog.index', $catalog) }}" class="text-sm font-semibold underline underline-offset-4">← Volver a {{ mb_strtolower($definition['title']) }}</a>
    <section class="mx-auto mt-7 max-w-3xl rounded-3xl border border-[#dfe4da] bg-white p-7 sm:p-9">
        <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Catálogos · {{ $definition['title'] }}</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ $record ? 'Editar' : 'Añadir' }} {{ $definition['singular'] }}</h1>
        <p class="mt-3 text-sm leading-6 text-[#6f7d66]">{{ $record ? 'Puedes ajustar los datos habilitados y desactivar el registro. Sus identificadores y relaciones se conservan para mantener la historia.' : $definition['description'] }}</p>
        @if($catalog === 'conversions' && !$record)<p class="mt-5 rounded-xl bg-[#edf2e7] p-4 text-sm leading-6 text-[#526547]">Al guardar aprobarás el factor indicado. Comprueba la fuente y el contexto: se registrará tu usuario como responsable. Para corregir el factor después, desactiva esta versión y crea otra.</p>@endif
        <form method="POST" action="{{ $record ? route('catalog.update', [$catalog, $record->id]) : route('catalog.store', $catalog) }}" class="mt-7 space-y-5">
            @csrf @if($record) @method('PATCH') @endif
            @foreach($definition['fields'] as $name => $field)
                @php
                    $locked = $record && !($field['mutable'] ?? false);
                    $value = $record?->getAttribute($name);
                    if ($value instanceof \DateTimeInterface) { $value = $value->format('Y-m-d'); }
                @endphp
                <div>
                    <label for="{{ $name }}" class="mb-2 block text-sm font-semibold">{{ $field['label'] }} @if($locked)<span class="text-xs font-normal text-[#819077]">· Permanente</span>@endif</label>
                    @if($field['type'] === 'select')
                        <select id="{{ $name }}" @unless($locked) name="{{ $name }}" @endunless @disabled($locked) @required($field['required'] ?? false) class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3 disabled:bg-[#f4f5ef] disabled:text-[#73816c]">
                            <option value="">Selecciona una opción</option>
                            @foreach($options[$name] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
                        </select>
                    @elseif($field['type'] === 'textarea')
                        <textarea id="{{ $name }}" @unless($locked) name="{{ $name }}" @endunless @disabled($locked) @required($field['required'] ?? false) rows="3" maxlength="2000" class="w-full rounded-xl border border-[#cdd8c7] px-4 py-3 disabled:bg-[#f4f5ef] disabled:text-[#73816c]">{{ old($name, $value) }}</textarea>
                    @else
                        <input id="{{ $name }}" @unless($locked) name="{{ $name }}" @endunless type="{{ $field['type'] }}" value="{{ old($name, $value) }}" @disabled($locked) @required($field['required'] ?? false) @if($name === 'factor') inputmode="decimal" @endif class="w-full rounded-xl border border-[#cdd8c7] px-4 py-3 disabled:bg-[#f4f5ef] disabled:text-[#73816c]">
                    @endif
                    @if(isset($field['hint']))<p class="mt-2 text-xs leading-5 text-[#6f7d66]">{{ $field['hint'] }}</p>@endif
                </div>
            @endforeach
            <div class="rounded-xl bg-[#f6f7f1] p-4">
                <input type="hidden" name="active" value="0">
                <label class="flex items-center gap-3 text-sm font-semibold"><input type="checkbox" name="active" value="1" @checked((bool) old('active', $record?->active ?? true)) class="size-4 accent-[#22583e]">Activo para nuevas operaciones</label>
                <p class="mt-2 text-xs leading-5 text-[#6f7d66]">Sus referencias también deben estar activas. Desmarcar conserva los datos y la historia.</p>
            </div>
            <x-field name="reason" label="Motivo del registro o cambio" required minlength="5" maxlength="500" hint="Explica el origen del dato o por qué haces el cambio. Quedará en auditoría." />
            <div class="flex flex-wrap items-center gap-4 border-t border-[#e6ebdf] pt-6"><button type="submit" class="rounded-xl bg-[#22583e] px-6 py-3 font-semibold text-white hover:bg-[#173f2c]">{{ $catalog === 'conversions' && !$record ? 'Guardar y aprobar equivalencia' : 'Guardar registro' }}</button><a class="text-sm underline underline-offset-4" href="{{ route('catalog.index', $catalog) }}">Cancelar</a></div>
        </form>
    </section>
</x-layout>

