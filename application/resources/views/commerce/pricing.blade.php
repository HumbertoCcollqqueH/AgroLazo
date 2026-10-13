<x-layout title="Reglas y referencias de precios">
    <a class="text-sm underline" href="{{ route('commerce.index') }}">← Operaciones</a><h1 class="mb-8 mt-5 text-3xl font-semibold">Precios transparentes</h1>
    <p class="mb-6 text-sm leading-7">Cada regla nueva conserva un historial independiente. Los descuentos se financian con el cargo de plataforma; no reducen el precio aceptado por el productor. Registrar una referencia no la convierte en precio oficial.</p>
    <x-commerce-panel title="Precio automático al productor" class="mb-6">
        <p class="text-sm leading-7">{{ $automaticPrices['enabled'] ? 'Activado' : 'Desactivado' }}. Después de verificar la oferta, el asistente propone un precio por kg y calcula el total con el peso registrado. El productor acepta por WhatsApp antes de que el producto se publique para compradores.</p>
        <p class="mt-2 text-sm">Se utiliza una referencia mayorista de la misma variedad, sin descuentos por volumen ni deducciones automáticas. No representa un precio observado en la chacra del productor. La propuesta informa el mercado, la fecha y la fuente; transporte cubierto por la plataforma, sin flete al productor ni al comprador.</p>
        <p class="mt-2 text-sm">Consulta cada hora · Antigüedad máxima: {{ config('market-pricing.max_age_days') }} días · Última consulta: {{ $automaticPrices['checked_at'] ? \Carbon\CarbonImmutable::parse($automaticPrices['checked_at'])->setTimezone('America/Lima')->format('d/m/Y H:i') : 'Pendiente' }}.</p>
        @if($automaticPrices['error'])<p class="mt-3 text-sm text-amber-800">{{ $automaticPrices['error']['message'] }}</p>@endif
        <div class="mt-4 grid gap-3 md:grid-cols-3">
            @forelse($automaticPrices['prices'] as $price)
                <div class="rounded-xl bg-[#f5f7f1] p-4 text-sm"><p class="font-semibold">{{ $price['product_label'] }} · S/ {{ number_format((float) $price['unit_price'], 2) }}/kg</p><p class="mt-2">{{ $price['market'] }} · {{ \Carbon\CarbonImmutable::parse($price['observed_on'])->format('d/m/Y') }}</p><p class="mt-2">{{ $price['fresh'] ? 'Disponible como referencia' : 'Antigua: no se usa para proponer precios' }}</p><a class="mt-2 inline-block underline" href="{{ $price['source'] }}" target="_blank" rel="noopener noreferrer">Consultar fuente</a></div>
            @empty<p class="text-sm">Todavía no hay una consulta disponible. Si falta una fuente vigente o la clasificación no coincide, la oferta espera revisión; no se asigna un precio inventado.</p>@endforelse
        </div>
    </x-commerce-panel>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-commerce-panel title="Reglas disponibles">
            @foreach($policies as $policy)<div class="mb-4 rounded-xl bg-[#f5f7f1] p-4 text-sm"><h3 class="font-semibold">{{ $policy->name }} · {{ $policy->synthetic ? 'Sintética' : 'Comercial' }}</h3><p class="mt-2">{{ $policy->disclosure }}</p><p class="mt-2">{{ $policy->tax_basis }}</p><p class="mt-2">Servicio por {{ $policy->baseUnit->code }}: S/ {{ $policy->fee_unit_price }} · Transporte cubierto por la plataforma en nuevas cotizaciones · Asignación: {{ $policy->allocation_rule }}</p></div>@endforeach
            <details class="mt-5"><summary class="cursor-pointer font-semibold">Registrar otra regla</summary><form class="mt-5 space-y-4" method="POST" action="{{ route('commerce.pricing.policies') }}"><x-commerce-key />
                <x-field name="name" label="Nombre de la regla" required maxlength="160" />
                <x-commerce-select name="base_unit_id" label="Unidad base">@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code }}</option>@endforeach</x-commerce-select>
                <x-commerce-select name="allocation_rule" label="Selección de aportaciones"><option value="fifo">FIFO: primera aportación incorporada al lote</option><option value="lowest_price">Menor precio; desempate FIFO</option></x-commerce-select>
                <x-field name="fee_unit_price" label="Servicio por unidad (soles)" value="0.000000" type="number" min="0" step="0.000001" required />
                <input type="hidden" name="logistics_minor" value="0"><p class="rounded-xl bg-emerald-50 p-4 text-sm">Transporte a cargo de la plataforma. Registra el costo interno al programar el despacho; no se añade al cobro del comprador.</p>
                <x-field name="discount_threshold" label="Cantidad mínima para descuento" value="0" type="number" min="0" step="0.001" required />
                <x-field name="discount_minor" label="Descuento total de plataforma (céntimos)" value="0" type="number" min="0" step="1" required />
                <x-field name="tax_bps" label="Tributo sobre producto y cargos netos (puntos base)" value="0" type="number" min="0" max="10000" step="1" required hint="100 puntos base = 1 %. Requiere fundamento revisado; no se configura una obligación tributaria automáticamente." />
                <x-field name="tax_basis" label="Fundamento y alcance del tributo, o supuesto sintético" required minlength="10" />
                <x-field name="disclosure" label="Conceptos, responsables, destinatarios y alcance de entrega" required minlength="10" />
                <x-commerce-select name="delivery_mode" label="Modalidad"><option value="delivery">Entrega en dirección cotizada</option></x-commerce-select>
                <x-commerce-select name="synthetic" label="Tipo de operación"><option value="1">Solo pruebas sintéticas</option><option value="0">Comercial: requiere habilitación del sistema</option></x-commerce-select>
                <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Guardar nueva regla</button>
            </form></details>
        </x-commerce-panel>
        <x-commerce-panel title="Registrar referencia manual">
            <form class="space-y-4" method="POST" action="{{ route('commerce.pricing.references') }}"><x-commerce-key />
                <x-commerce-select name="product_id" label="Producto">@foreach($products as $entry)<option value="{{ $entry->id }}">{{ $entry->name }}</option>@endforeach</x-commerce-select>
                <x-commerce-select name="variety_id" label="Variedad">@foreach($varieties as $entry)<option value="{{ $entry->id }}">{{ $entry->product->name }} · {{ $entry->name }}</option>@endforeach</x-commerce-select>
                <x-commerce-select name="quality_grade_id" label="Calidad">@foreach($qualities as $entry)<option value="{{ $entry->id }}">{{ $entry->product->name }} · {{ $entry->name }}</option>@endforeach</x-commerce-select>
                <x-commerce-select name="location_id" label="Localidad">@foreach($locations as $entry)<option value="{{ $entry->id }}">{{ $entry->name }} · {{ $entry->type }}</option>@endforeach</x-commerce-select>
                <x-commerce-select name="unit_id" label="Unidad base">@foreach($units as $entry)<option value="{{ $entry->id }}">{{ $entry->code }}</option>@endforeach</x-commerce-select>
                <x-field name="unit_price" label="Precio por unidad (PEN)" type="number" step="0.000001" min="0.000001" required />
                <x-field name="market" label="Mercado observado" required />
                <x-field name="observed_on" label="Fecha observada" type="date" required />
                <x-field name="source" label="Fuente: URL o identificación del documento verificable" required minlength="10" />
                <x-commerce-select name="synthetic" label="Origen"><option value="1">Ejemplo sintético identificado</option><option value="0">Observación real documentada</option></x-commerce-select>
                <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Registrar referencia</button>
            </form>
        </x-commerce-panel>
    </div>
    <x-commerce-panel title="Historial de referencias" class="mt-6">@forelse($references as $reference)<div class="border-b border-[#dfe4da] py-4 text-sm"><p class="font-semibold">{{ $reference->product->name }} · S/ {{ $reference->unit_price }} / {{ $reference->unit->code }} · {{ $reference->synthetic ? 'Sintético' : 'Manual' }}</p><p class="mt-2">{{ $reference->market }} · {{ $reference->observed_on->format('d/m/Y') }}</p><p class="mt-2 break-words">{{ $reference->source }}</p></div>@empty<p class="text-sm">Todavía no se han registrado referencias.</p>@endforelse<div class="mt-5">{{ $references->links() }}</div></x-commerce-panel>
</x-layout>

