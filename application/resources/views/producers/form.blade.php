<x-layout :title="$producer ? 'Editar productor' : 'Registrar productor'">
    <a href="{{ $producer ? route('producers.show', $producer) : route('producers.index') }}" class="text-sm font-semibold underline underline-offset-4">← Volver {{ $producer ? 'a la ficha' : 'a productores' }}</a>
    @php
        $currentLocation = $producer?->currentLocation;
        $selectedProductIds = array_map('strval', (array) old('product_ids', $producer?->products->modelKeys() ?? []));
    @endphp
    <section class="mx-auto mt-7 max-w-3xl rounded-3xl border border-[#dfe4da] bg-white p-7 sm:p-9">
        <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Registro asistido · Ficha privada</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ $producer ? 'Editar productor' : 'Conozcamos al productor' }}</h1>
        <p class="mt-3 text-sm leading-6 text-[#6f7d66]">{{ $producer ? 'Los cambios de nombre o tipo de un productor verificado requieren una nueva revisión. Las ubicaciones anteriores se conservan en su historial.' : 'Empieza con cuatro datos. El productor quedará pendiente hasta que un miembro del equipo verifique su información.' }}</p>
        <form method="POST" action="{{ $producer ? route('producers.update', $producer) : route('producers.store') }}" autocomplete="off" class="mt-7 space-y-6">
            @csrf @if($producer) @method('PATCH') @endif
            <x-field name="full_name" label="Nombre completo" :value="$producer?->full_name" required maxlength="150" />
            @unless($producer)
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field name="dni" label="DNI" required inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" hint="Ocho dígitos, incluidos los ceros iniciales." />
                    <x-field name="phone" label="Teléfono" type="tel" required maxlength="20" hint="Celular peruano de 9 dígitos o número con + y código de país." />
                </div>
            @else
                <p class="rounded-xl bg-[#f3f5ee] p-4 text-sm leading-6 text-[#627057]">El DNI registrado se conserva. Para actualizar el teléfono usa «Cambiar teléfono» en la ficha: se comprobará el número actual y se guardará el historial.</p>
            @endunless
            <div><label for="type" class="mb-2 block text-sm font-semibold">Tipo de productor</label><select id="type" name="type" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Selecciona una opción</option>@foreach(\App\Models\Producer::TYPES as $key => $label)<option value="{{ $key }}" @selected(old('type', $producer?->type) === $key)>{{ $label }}</option>@endforeach</select></div>
            <fieldset class="rounded-2xl border border-[#dfe4da] p-5">
                <legend class="px-2 font-semibold">Productos que produce</legend>
                <p class="text-sm leading-6 text-[#6f7d66]">Marca uno o varios. AgroLazo usará estos productos para ayudarle a registrar sus ofertas; si tiene uno solo, no tendrá que elegirlo otra vez.</p>
                <input type="hidden" name="product_ids" value="">
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @forelse($products as $product)
                        <label class="flex items-start gap-3 rounded-xl border border-[#dfe4da] p-3 text-sm">
                            <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array((string) $product->id, $selectedProductIds, true)) class="mt-1 accent-[#22583e]">
                            <span>{{ $product->name }}@unless($product->isUsable())<span class="block text-xs text-[#788373]">Referencia anterior inactiva; no se ofrecerá en el catálogo.</span>@endunless</span>
                        </label>
                    @empty
                        <p class="text-sm text-[#6f7d66]">Todavía no hay productos activos en el catálogo.</p>
                    @endforelse
                </div>
                <p class="mt-3 text-xs leading-5 text-[#6f7d66]">Si aún no conoces sus productos, puedes completar este dato después desde su ficha.</p>
            </fieldset>
            <details class="rounded-2xl border border-[#dfe4da] p-5" @if($producer || $errors->any()) open @endif>
                <summary class="cursor-pointer font-semibold">Datos adicionales <span class="ml-2 text-xs font-normal text-[#788373]">Opcionales</span></summary>
                <div class="mt-5 space-y-5">
                    <x-field name="association" label="Asociación u organización" :value="$producer?->association" maxlength="150" />
                    <x-field name="email" label="Correo electrónico" type="email" :value="$producer?->email" maxlength="254" />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div><label for="sex" class="mb-2 block text-sm font-semibold">Sexo</label><select id="sex" name="sex" class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Sin registrar</option>@foreach(['female' => 'Femenino', 'male' => 'Masculino', 'unspecified' => 'Prefiere no indicar'] as $key => $label)<option value="{{ $key }}" @selected(old('sex', $producer?->sex) === $key)>{{ $label }}</option>@endforeach</select></div>
                        <x-field name="birth_date" label="Fecha de nacimiento" type="date" :value="$producer?->birth_date?->format('Y-m-d')" :max="now()->timezone('America/Lima')->toDateString()" />
                    </div>
                </div>
            </details>
            <details class="rounded-2xl border border-[#dfe4da] p-5" @if($currentLocation || $errors->any()) open @endif>
                <summary class="cursor-pointer font-semibold">Ubicación <span class="ml-2 text-xs font-normal text-[#788373]">Opcional</span></summary>
                <div class="mt-5 space-y-5">
                    <div><label for="location_id" class="mb-2 block text-sm font-semibold">Localidad del catálogo</label><select id="location_id" name="location_id" class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Sin localidad asignada</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) old('location_id', $currentLocation?->location_id) === (string) $location->id)>{{ $location->name }} · {{ \App\Models\Location::TYPES[$location->type] }}{{ $location->parent ? ' · '.$location->parent->name : '' }}{{ !$location->isUsable() ? ' (referencia anterior inactiva)' : '' }}</option>@endforeach</select><p class="mt-2 text-xs leading-5 text-[#6f7d66]">Selecciona la ubicación más precisa disponible. AgroLazo la usará al preparar sus ofertas y el productor podrá cambiarla si su producto está en otro lugar.</p></div>
                    <x-field name="community" label="Comunidad o sector" :value="$currentLocation?->community" maxlength="150" />
                    <x-field name="address" label="Dirección o referencia" :value="$currentLocation?->address" maxlength="500" />
                    <div class="grid gap-5 sm:grid-cols-2"><x-field name="latitude" label="Latitud" :value="$currentLocation?->latitude" inputmode="decimal" placeholder="-15.840200" /><x-field name="longitude" label="Longitud" :value="$currentLocation?->longitude" inputmode="decimal" placeholder="-70.021900" /></div>
                    <x-location-picker />
                    <p class="text-sm leading-6">Marca la entrada donde recogeremos los productos. Si estás registrando al productor desde otra dirección, selecciona su terreno en el mapa en lugar de usar tu ubicación actual. En su ficha, registra su autorización de «Uso de coordenadas para planificar acopio» para poder agruparlo con productores cercanos.</p>
                    <p class="text-xs leading-5 text-[#6f7d66]">Registra ambas coordenadas o deja las dos vacías. Usa punto decimal. Estos datos forman parte de la ficha privada.</p>
                </div>
            </details>
            @if($producer)<x-field name="reason" label="Motivo de la corrección" required minlength="5" maxlength="500" hint="Describe el cambio sin repetir el DNI ni el teléfono." />@endif
            <div class="flex flex-wrap items-center gap-4 border-t border-[#e6ebdf] pt-6"><button type="submit" class="rounded-xl bg-[#22583e] px-6 py-3 font-semibold text-white hover:bg-[#173f2c]">{{ $producer ? 'Guardar cambios' : 'Registrar productor' }}</button><a class="text-sm underline underline-offset-4" href="{{ $producer ? route('producers.show', $producer) : route('producers.index') }}">Cancelar</a></div>
        </form>
    </section>
</x-layout>
