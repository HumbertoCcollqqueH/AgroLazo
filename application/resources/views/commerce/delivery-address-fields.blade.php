@php($destination = $destination ?? [])
<fieldset class="space-y-4 rounded-2xl border border-[#cdd8c7] bg-[#f5f7f1] p-5" data-delivery-address>
    <legend class="px-2 font-semibold">Lugar exacto de entrega al comprador</legend>
    <p class="text-sm leading-6">Indica dónde debemos llevar el pedido. El contacto y la ubicación solo se muestran al comprador y al equipo autorizado.</p>
    <x-location-picker latitude="delivery_address[latitude]" longitude="delivery_address[longitude]" />
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach(['recipient' => 'Nombre de quien recibe', 'phone' => 'Teléfono de quien recibe', 'department' => 'Departamento', 'province' => 'Provincia', 'district' => 'Distrito', 'street' => 'Calle y número, o dirección rural exacta', 'reference' => 'Referencia y acceso al lugar', 'latitude' => 'Latitud del destino', 'longitude' => 'Longitud del destino'] as $key => $label)
            <div>
                <label class="mb-2 block text-sm font-semibold" for="delivery_{{ $key }}">{{ $label }}</label>
                <input id="delivery_{{ $key }}" name="delivery_address[{{ $key }}]" value="{{ old('delivery_address.'.$key, $destination[$key] ?? '') }}" required
                    type="{{ in_array($key, ['latitude', 'longitude']) ? 'number' : ($key === 'phone' ? 'tel' : 'text') }}"
                    @if(in_array($key, ['latitude', 'longitude'])) step="any" min="{{ $key === 'latitude' ? -90 : -180 }}" max="{{ $key === 'latitude' ? 90 : 180 }}" @endif
                    maxlength="{{ in_array($key, ['street', 'reference']) ? 500 : 160 }}"
                    class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3" @error('delivery_address.'.$key) aria-invalid="true" @enderror>
                @error('delivery_address.'.$key)<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
    <p class="text-sm leading-6">La referencia de acceso la completas tú: por ejemplo, color del portón, piso o entrada de descarga. El mapa no puede conocer esos detalles.</p>
    <p class="text-xs leading-6">Teléfono con código de país, por ejemplo +51912345678. Las coordenadas se completan al marcar el mapa; también puedes ajustarlas manualmente.</p>
</fieldset>
