<x-layout title="Cotizar cantidad">
    @php
        $visual = app(\App\Services\ProductVisual::class)->forProduct($lot->product);
        $availableQuantity = \App\Services\PurchaseQuantity::format((string) $lot->available_quantity);
        $minimumLabel = $minimumQuantity === null ? null : \App\Services\PurchaseQuantity::format($minimumQuantity).' '.$lot->baseUnit->code;
    @endphp
    <a class="text-sm underline" href="{{ route('marketplace.show', $lot->code) }}">← Volver al lote</a>
    <h1 class="mb-7 mt-5 text-3xl font-semibold">Cotiza {{ $lot->product->name }}</h1>
    <section aria-label="Resumen del lote" class="mb-7 overflow-hidden rounded-2xl border border-[#dfe4da] bg-white sm:flex">
        <div class="relative sm:w-64 sm:shrink-0"><img src="{{ asset($visual['image']) }}" alt="{{ $visual['alt'] }}" width="400" height="280" class="h-52 w-full object-cover sm:h-full"><span class="absolute bottom-3 left-3 rounded-lg bg-white/95 px-3 py-1 text-xs">Imagen referencial</span></div>
        <div class="flex-1 p-6 sm:p-7">
            <p class="ac-eyebrow">EL LOTE QUE ESTÁS COTIZANDO · {{ $lot->code }}</p>
            <h2 class="mt-3 text-2xl font-semibold">{{ $lot->product->name }} <span class="text-lg font-normal text-[#627057]">· {{ $lot->variety->name }}</span></h2>
            <p class="mt-2 text-sm text-[#627057]">{{ $lot->qualityGrade->name }} · {{ $locationLabel }}</p>
            <dl class="mt-5 grid grid-cols-2 gap-5 text-sm lg:grid-cols-4">
                <div><dt class="text-[#627057]">Disponible para comprar</dt><dd class="mt-1 text-xl font-semibold">{{ $availableQuantity }} {{ $lot->baseUnit->code }}</dd></div>
                <div><dt class="text-[#627057]">Precio del producto</dt><dd class="mt-1 font-semibold">S/ {{ number_format((float) $lot->unit_price_min, 2) }}@if(bccomp((string) $lot->unit_price_min, (string) $lot->unit_price_max, 6) !== 0) – {{ number_format((float) $lot->unit_price_max, 2) }}@endif / {{ $lot->baseUnit->code }}</dd></div>
                <div><dt class="text-[#627057]">Origen agrupado</dt><dd class="mt-1 font-semibold">{{ $lot->producer_count }} {{ (int) $lot->producer_count === 1 ? 'productor' : 'productores' }}</dd></div>
                <div><dt class="text-[#627057]">Disponibilidad</dt><dd class="mt-1 font-semibold">{{ $lot->available_from->format('d/m/Y') }} – {{ $lot->available_until->format('d/m/Y') }}</dd></div>
            </dl>
        </div>
    </section>
    @if(config('commerce.show_test_labels') && ! config('commerce.live_enabled'))<p class="mb-6 rounded-xl bg-amber-50 p-4 text-sm">{{ \App\Services\OperationalNotifier::testWhatsAppEnabled() ? 'Compra de prueba con avisos reales por WhatsApp marcados como PRUEBA, sin venta ni traslado real.' : 'Compra de prueba con avisos en el simulador, sin venta ni traslado real.' }}</p>@endif
    <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <x-commerce-panel title="Cantidad y condiciones">
            @if(! $canQuote)<div role="status" class="rounded-xl bg-amber-50 p-5"><p class="font-semibold">{{ $minimumLabel ? 'La disponibilidad restante es menor que la compra mínima de '.$minimumLabel.'.' : 'El equipo está revisando la unidad de venta de este lote.' }}</p><p class="mt-2 text-sm">Elige otro lote para continuar con tu compra.</p><a class="mt-4 inline-block font-semibold underline" href="{{ route('marketplace.index', ['product_id' => $lot->product_id]) }}">Ver más lotes de {{ $lot->product->name }}</a></div>
            @elseif($policies->isEmpty())<p>No hay una regla de precios revisada para este lote. El equipo debe registrar los cargos y condiciones antes de cotizar.</p>@else
            <form method="POST" action="{{ route('commerce.quotes.store', $lot) }}" class="space-y-5">
                <x-commerce-key />
                <div>
                    <x-field name="quantity" :label="'¿Cuánto necesitas? ('.$lot->baseUnit->code.')'" :value="$quantity" type="number" :min="$minimumQuantity" :max="$lot->available_quantity" :step="$lot->baseUnit->dimension === 'count' ? '1' : '0.001'" :hint="'Compra desde '.$minimumLabel.' hasta '.$availableQuantity.' '.$lot->baseUnit->code.'. Puedes elegir una parte o toda la cantidad disponible.'" required />
                    <button type="button" data-quote-quantity="{{ $availableQuantity }}" class="mt-3 rounded-lg border border-[#cdd8c7] px-4 py-2 text-sm font-semibold text-[#22583e] hover:bg-[#edf2e7]">Usar todo lo disponible: {{ $availableQuantity }} {{ $lot->baseUnit->code }}</button>
                </div>
                <x-commerce-select name="policy_id" label="Entrega y regla de precio" required>@foreach($policies as $policy)<option value="{{ $policy->id }}" @selected(old('policy_id') == $policy->id)>{{ $policy->synthetic && ! config('commerce.show_test_labels') ? 'Entrega coordinada · '.$lot->baseUnit->code : $policy->name }}{{ $policy->synthetic && config('commerce.show_test_labels') ? ' · Sintética' : '' }}</option>@endforeach</x-commerce-select>
                @if(auth()->user()->buyer?->delivery_address)<p class="rounded-xl bg-white p-4 text-sm">Cargamos tu dirección habitual. Puedes cambiar los datos para esta compra sin modificar tu perfil.</p>@else<p class="text-sm">Puedes guardar tu destino habitual en <a class="underline" href="{{ route('account.edit') }}">Mi perfil</a> para no escribirlo en cada compra.</p>@endif
                @include('commerce.delivery-address-fields', ['destination' => $destination])
                <input type="hidden" name="delivery_details" value="Entrega en el destino indicado en la ficha de dirección.">
                <x-commerce-select name="payment_method" label="Método de pago previsto" required>@foreach(config('commerce.payment_methods') as $code => $name)<option value="{{ $code }}" @selected(old('payment_method') === $code)>{{ $name }}</option>@endforeach</x-commerce-select>
                <button class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Calcular cotización</button>
            </form>@endif
        </x-commerce-panel>
        <x-commerce-panel title="Antes de confirmar">
            <ol class="mb-6 space-y-4 border-b border-[#dfe4da] pb-6 text-sm"><li><strong class="mr-2 text-[#22583e]">1.</strong> Elige la cantidad y el destino de entrega.</li><li><strong class="mr-2 text-[#22583e]">2.</strong> Revisa el precio completo y las condiciones.</li><li><strong class="mr-2 text-[#22583e]">3.</strong> Selecciona la fecha y confirma tu compra.</li></ol>
            <p class="text-sm leading-7">La cotización selecciona aportaciones concretas y conserva sus precios. Podrás revisar producto, servicio, logística, descuento y tributos. Cotizar no reserva inventario.</p>
            @foreach($policies as $policy)<div class="mt-6 border-t border-[#dfe4da] pt-5 text-sm"><h3 class="font-semibold">{{ $policy->synthetic && ! config('commerce.show_test_labels') ? 'Entrega coordinada · '.$lot->baseUnit->code : $policy->name }}</h3><p class="mt-2">{{ $policy->synthetic && ! config('commerce.show_test_labels') ? str_replace(['Prueba: ', 'Retiro coordinado, sin transporte cobrado.'], ['', 'Transporte organizado y cubierto por AgroLazo.'], $policy->disclosure) : $policy->disclosure }}</p><p class="mt-2 text-[#6f7d66]">{{ $policy->synthetic && ! config('commerce.show_test_labels') ? 'Tributos pendientes de revisión comercial.' : $policy->tax_basis }}</p></div>@endforeach
        </x-commerce-panel>
    </div>
</x-layout>

