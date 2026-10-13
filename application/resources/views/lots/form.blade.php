<x-layout title="Formar lote">
    <a href="{{ route('lots.index') }}" class="text-sm font-semibold underline underline-offset-4">← Lotes</a>
    <h1 class="mt-5 text-3xl font-semibold">{{ $suggested ? 'Revisar agrupación sugerida' : 'Formar un lote comercial' }}</h1>
    <p class="mt-4 max-w-3xl text-sm leading-6 text-[#6f7d66]">Selecciona ofertas y cantidades en su unidad base. Deben compartir producto, variedad, calidad y localidad, con una ventana común de disponibilidad y acuerdos vigentes. La agrupación conserva el origen de cada porción.</p>
    @if($suggested)<div class="mt-5 rounded-xl border border-[#cdd8c7] bg-[#edf2e7] p-4 text-sm leading-6">Esta sugerencia no separó cantidades. Revisa y confirma para crear el borrador. Se consideran hasta 500 ofertas candidatas y se proponen como máximo 100; cada una debe encajar en la ventana común.</div>@endif
    <form method="GET" class="my-6 flex flex-wrap items-end gap-3">
        <div><label for="product_id" class="mb-2 block text-sm font-semibold">Producto</label><select name="product_id" id="product_id" class="rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Todos</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>
        <button class="rounded-xl border border-[#cdd8c7] px-4 py-3 text-sm font-semibold">Elegir manualmente</button>
    </form>
    <form method="POST" action="{{ route('lots.store') }}">
        @csrf
        <input type="hidden" name="source_key" value="{{ old('source_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <div class="space-y-4">
            @forelse($rows as $row)
                @php($offer = $row['offer'])
                @php($agreement = $row['agreement'])
                <article class="rounded-2xl border border-[#dfe4da] bg-white p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <label for="selected-{{ $offer->id }}" class="flex items-start gap-3">
                                <input id="selected-{{ $offer->id }}" type="checkbox" name="selected[]" value="{{ $offer->id }}" @checked(collect(old('selected', $suggested ? $rows->pluck('offer.id')->all() : []))->contains(fn ($value) => is_scalar($value) && (string) $value === (string) $offer->id)) class="mt-1 size-5 accent-[#22583e]">
                                <span><span class="block text-lg font-semibold">Oferta #{{ $offer->id }} · {{ $offer->product->name }}</span><span class="mt-1 block text-sm text-[#6f7d66]">{{ $offer->producer->full_name }}</span></span>
                            </label>
                            <p class="mt-3 text-sm">{{ $offer->variety->name }} · {{ $offer->qualityGrade->name }}</p>
                            <p class="mt-2 text-xs leading-5 text-[#6f7d66]">{{ $geography->get($offer->location_id)['label'] ?? $offer->location->name }}<br>{{ $offer->available_from->format('d/m/Y') }} – {{ $offer->available_until->format('d/m/Y') }}</p>
                        </div>
                        <div class="text-sm">
                            <p class="font-semibold">{{ $offer->ungrouped_quantity }} {{ $offer->baseUnit->code }} libres</p>
                            <p class="mt-2">S/ {{ $agreement->gross_unit_price }} / {{ $offer->baseUnit->code }} bruto aceptado</p>
                            <p class="mt-1 text-xs text-[#6f7d66]">Acuerdo vigente hasta {{ $agreement->valid_until->setTimezone('America/Lima')->format('d/m/Y H:i') }}</p>
                        </div>
                    </div>
                    <div class="mt-5 flex flex-wrap items-end gap-4 border-t border-[#edf0e8] pt-4">
                        <div><label for="quantity-{{ $offer->id }}" class="mb-2 block text-sm font-semibold">Aportación de oferta #{{ $offer->id }} ({{ $offer->baseUnit->code }})</label><input id="quantity-{{ $offer->id }}" name="quantities[{{ $offer->id }}]" type="number" min="{{ $offer->baseUnit->dimension === 'count' ? '1' : '0.001' }}" step="{{ $offer->baseUnit->dimension === 'count' ? '1' : '0.001' }}" max="{{ $offer->ungrouped_quantity }}" value="{{ old('quantities.'.$offer->id, $offer->ungrouped_quantity) }}" class="w-48 rounded-xl border border-[#cdd8c7] px-4 py-3"></div>
                        <input type="hidden" name="versions[{{ $offer->id }}]" value="{{ $offer->version }}">
                        <input type="hidden" name="agreements[{{ $offer->id }}]" value="{{ $agreement->id }}">
                        <a class="py-3 text-sm font-semibold underline underline-offset-4" href="{{ route('lots.create', ['anchor_id' => $offer->id]) }}">Sugerir compatibles con igual precio</a>
                        <a class="py-3 text-sm underline underline-offset-4" href="{{ route('lots.create', ['anchor_id' => $offer->id, 'allow_price_variation' => 1]) }}">Sugerir con otros precios aceptados</a>
                    </div>
                </article>
            @empty
                <p class="rounded-2xl border border-dashed border-[#cdd8c7] p-8 text-sm leading-6 text-[#6f7d66]">No hay ofertas elegibles con saldo libre. Publica una oferta con condiciones aceptadas vigentes o revisa los filtros.</p>
            @endforelse
        </div>
        @if($rows->isNotEmpty())
            <section class="mt-6 rounded-2xl border border-[#cdd8c7] bg-[#edf2e7] p-6">
                <label class="flex items-start gap-3 text-sm leading-6"><input type="checkbox" name="allow_price_variation" value="1" @checked(old('allow_price_variation', request('allow_price_variation'))) class="mt-1 size-5 accent-[#22583e]"><span>He revisado los precios y acepto agrupar ofertas con precios brutos diferentes. Se conservará cada acuerdo; el rango no es una cotización final para el comprador.</span></label>
                <div class="mt-5"><x-field name="reason" label="Motivo y revisión de compatibilidad" minlength="5" maxlength="1000" required /></div>
                <p class="mt-4 text-xs leading-5 text-[#6f7d66]">Crear el borrador separa estas cantidades. La publicación requiere una revisión adicional. Se volverán a comprobar los saldos y los acuerdos al guardar.</p>
                <button class="mt-5 rounded-xl bg-[#22583e] px-6 py-3 font-semibold text-white">Crear lote en borrador</button>
            </section>
        @endif
    </form>
    @unless($suggested)<div class="mt-6">{{ $offers->links() }}</div>@endunless
</x-layout>

