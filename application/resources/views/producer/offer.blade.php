<x-layout :title="'Oferta #'.$offer->id">
    <a class="pp-link" href="{{ route('producer.home') }}">← Mi producción</a>
    <div class="pp-section-heading"><div><p class="pp-kicker">OFERTA #{{ $offer->id }}</p><h1>{{ $offer->product->name }}</h1><span class="pp-badge">{{ \App\Models\Offer::STATUSES[$offer->status] }}</span></div><a class="pp-button pp-button--outline" href="{{ route('coordination.index') }}">Conversar con el equipo</a></div>
    @php
        $closed = in_array($offer->status, ['cancelled', 'expired', 'rejected']) || $offer->available_until->toDateString() < now('America/Lima')->toDateString();
        $ready = ! $closed && $offer->status === 'verified' && $agreement?->status === 'proposed' && $agreement->valid_until->isFuture();
    @endphp
    <section class="pp-info mb-6" role="status">
        @if($closed)<strong>Esta oferta ya no recibe nuevas compras.</strong><p>Las ventas ya confirmadas mantienen su seguimiento. Puedes preparar otra oferta con fechas actuales.</p>
        @elseif($offer->status === 'published' && $agreement && ! $agreement->valid_until->isFuture())<strong>El precio necesita una actualización.</strong><p>Esta propuesta venció y ya no permite nuevas compras. Las ventas confirmadas conservan sus condiciones. Conversa con el equipo para revisar la cantidad restante.</p>
        @elseif($offer->status === 'published')<strong>Tu oferta está publicada.</strong><p>Terminaste el proceso. Te avisaremos aquí cuando compren tu aportación y cuando se programe el recojo.</p>
        @elseif($ready)<strong>Solo falta tu confirmación.</strong><p>El equipo ya revisó tu oferta. Revisa el precio y el total; al aceptar, se publicará automáticamente. No necesitas esperar otra aprobación.</p>
        @elseif($offer->status === 'needs_information')<strong>Necesitamos que completes algunos datos.</strong><p>Revisa la observación en el historial, corrige la oferta y vuelve a enviarla. También puedes escribir al equipo desde Conversaciones.</p>
        @elseif($agreement?->status === 'rejected')<strong>Solicitaremos otra propuesta.</strong><p>Tu oferta está guardada sin publicar. Conversa con el equipo para acordar el precio que necesitas.</p>
        @else<strong>{{ $offer->status === 'verified' ? 'Los datos ya están revisados.' : 'El equipo está revisando tu oferta.' }}</strong><p>Prepararemos el precio para que puedas aceptarlo y publicar en un solo paso. Recibirás un aviso dentro de AgroLazo.</p>@endif
    </section>
    <div class="pp-two">
        <section class="pp-card"><h2>Lo que ofreces</h2><dl class="pp-details"><div><dt>Cantidad</dt><dd>{{ $offer->quantity }} {{ $offer->unit->code }}</dd></div><div><dt>Variedad</dt><dd>{{ $offer->variety?->name ?? 'Por definir' }}</dd></div><div><dt>Calidad</dt><dd>{{ $offer->qualityGrade?->name ?? 'Por definir' }}</dd></div><div><dt>Localidad</dt><dd>{{ $offer->location->name }}</dd></div><div><dt>Disponible</dt><dd>{{ $offer->available_from->format('d/m/Y') }} al {{ $offer->available_until->format('d/m/Y') }}</dd></div></dl></section>
        <section class="pp-card pp-price"><p class="pp-kicker">PRECIO PARA TI</p><h2>{{ $agreement ? 'Tu propuesta económica' : 'Precio pendiente' }}</h2>
            @if($agreement)
                <p class="pp-price-value">S/ {{ number_format((float) $agreement->net_unit_price, 2) }}<small> / {{ $offer->baseUnit->code }}</small></p>
                <p>Total por {{ $offer->normalized_quantity }} {{ $offer->baseUnit->code }}: <strong>S/ {{ \App\Services\Money::format(\App\Services\Money::line($offer->normalized_quantity, $agreement->net_unit_price)) }}</strong></p>
                <p class="mt-3 text-sm">Precio válido hasta {{ $agreement->valid_until->timezone('America/Lima')->format('d/m/Y H:i') }} (Perú)</p>
                <details class="mt-4 text-sm leading-6"><summary class="cursor-pointer font-semibold">Ver condiciones completas</summary><p class="mt-3">{{ $agreement->terms }}</p></details>
                @if($ready)
                    <p class="mt-5 text-sm leading-6">Al aceptar, autorizas publicar esta cantidad con el precio indicado. AgroLazo organizará el transporte si se confirma una compra.</p>
                    <form method="POST" action="{{ route('producer.agreements.decide', [$offer, $agreement]) }}" class="pp-form mt-5">@csrf<input type="hidden" name="version" value="{{ $offer->version }}"><button class="pp-button" name="decision" value="accepted">Aceptar precio y publicar</button><button class="pp-button pp-button--outline" name="decision" value="rejected">Necesito otro precio</button></form>
                @elseif(! $closed)
                    <p class="pp-info mt-5">{{ $agreement->status === 'accepted' ? ($offer->status === 'published' ? 'Precio aceptado y oferta publicada. Puedes seguir tus ventas desde Mi producción.' : 'Precio aceptado. El equipo está revisando el saldo de publicación; puedes consultar desde Conversaciones.') : ($agreement->status === 'rejected' ? 'No aceptaste esta propuesta. Puedes acordar otra desde Conversaciones.' : 'Este precio ya venció. Solicita una propuesta actualizada desde Conversaciones.') }}</p>
                @endif
            @elseif(! $closed)<p class="mt-3 leading-6">Aquí verás el precio por unidad y el total antes de aceptar. Si falta algo, el equipo te lo pedirá dentro de AgroLazo.</p>@endif
            @if($closed)<a class="pp-button mt-5" href="{{ route('producer.offers.create') }}">Crear otra oferta</a>@endif
        </section>
    </div>
    @if($offer->canBeEdited())<section class="pp-info mt-6"><strong>Puedes corregir esta oferta.</strong><p>Actualiza los datos observados y envíalos de nuevo a revisión.</p><a class="pp-button mt-3" href="{{ route('producer.offers.edit', $offer) }}">Corregir los datos</a></section>@endif
    <section class="pp-card mt-6"><h2>Fotos de tu producto</h2><p>Imágenes privadas para que el equipo revise tu oferta. JPG, PNG o WebP, hasta 5 MB por imagen y ocho fotos por oferta.</p><div class="pp-product-grid mt-5">@foreach($offer->photos as $photo)<figure><img class="rounded-xl" src="{{ route('producer.offers.photo', [$offer, $photo]) }}" alt="{{ $photo->caption ?: 'Foto de la oferta' }}" loading="lazy"><figcaption class="text-sm mt-2">{{ $photo->caption }}</figcaption></figure>@endforeach</div>@unless(in_array($offer->status, ['cancelled', 'expired']))<form method="POST" enctype="multipart/form-data" action="{{ route('producer.offers.photos', $offer) }}" class="pp-form mt-5">@csrf<label>Selecciona una foto<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label><x-field name="caption" label="Descripción de la foto" maxlength="500" /><button class="pp-button">Añadir foto</button></form>@endunless</section>
    <details class="pp-card mt-6"><summary class="cursor-pointer font-semibold">Historial de la oferta</summary><ol class="pp-timeline mt-4">@foreach($offer->histories as $history)<li><time>{{ $history->occurred_at->timezone('America/Lima')->format('d/m/Y H:i') }}</time><p>{{ $history->reason }}</p></li>@endforeach</ol></details>
</x-layout>
