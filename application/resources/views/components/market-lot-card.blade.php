@props(['lot'])
@php
    $visual = app(\App\Services\ProductVisual::class)->forProduct($lot->product);
    $price = fn ($value) => number_format((float) $value, max(2, strlen(rtrim(explode('.', (string) $value)[1] ?? '', '0'))), '.', ',');
    $quantity = rtrim(rtrim(bcadd((string) $lot->available_quantity, '0', 3), '0'), '.');
    $isAvailable = $lot->available_from->toDateString() <= now('America/Lima')->toDateString();
@endphp
<article class="ac-lot-card" data-product-card data-product-code="{{ $lot->code }}">
    <div class="ac-lot-photo">
        <a href="{{ route('marketplace.show', $lot->code) }}" tabindex="-1" aria-hidden="true"><img src="{{ asset($visual['image']) }}" alt="" width="720" height="540" loading="lazy" decoding="async"><span class="ac-photo-action">Conocer este lote <x-ac-icon name="arrow" /></span></a>
        <span class="ac-stock-badge"><span></span>{{ $isAvailable ? 'Disponible hoy' : 'Disponible pronto' }}</span>
        <button type="button" class="ac-favorite" data-favorite="{{ $lot->code }}" data-product-name="{{ $lot->product->name }}" aria-label="Guardar {{ $lot->product->name }} en favoritos" aria-pressed="false" hidden><x-ac-icon name="heart" /></button>
        <span class="ac-photo-caption">Imagen referencial</span>
    </div>
    <div class="ac-lot-content">
        <p class="ac-lot-origin"><x-ac-icon name="pin" />{{ $lot->location->name }} <span>· {{ $lot->code }}</span></p>
        <h3><a href="{{ route('marketplace.show', $lot->code) }}">{{ $lot->product->name }}</a></h3>
        <p class="ac-lot-variety">{{ $lot->variety->name }} · {{ $lot->qualityGrade->name }}</p>
        @if($lot->buyer_distance_km !== null)<p class="mt-2 text-sm font-semibold text-[#22583e]">Aprox. {{ max(1, (int) ceil($lot->buyer_distance_km)) }} km de tu destino</p>@endif
        <div class="ac-lot-price"><strong>S/ {{ $price($lot->unit_price_min) }}@if(bccomp((string) $lot->unit_price_min, (string) $lot->unit_price_max, 6) !== 0) – {{ $price($lot->unit_price_max) }}@endif</strong><span>/ {{ $lot->baseUnit->code }}</span></div>
        <p class="ac-price-note">Precio estimado del producto</p>
        <div class="ac-lot-facts"><span><x-ac-icon name="box" /><b>{{ $quantity }} {{ $lot->baseUnit->code }}</b> disponibles</span><span><x-ac-icon name="clock" />Hasta {{ $lot->available_until->format('d/m/Y') }}</span></div>
        <a href="{{ route('marketplace.show', $lot->code) }}" class="ac-button ac-button--outline ac-card-cta">{{ auth()->user()?->can('view-lots') ? 'Supervisar lote' : 'Ver lote y cotizar' }} <x-ac-icon name="arrow" /></a>
    </div>
</article>
