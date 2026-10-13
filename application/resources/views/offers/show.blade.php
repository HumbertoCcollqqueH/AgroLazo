<x-layout :title="'Oferta #'.$offer->id">
    <a href="{{ route('offers.index') }}" class="text-sm font-semibold underline">← Volver a ofertas</a>
    <div class="my-7 flex flex-wrap justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Oferta #{{ $offer->id }} · Versión {{ $offer->version }}</p><h1 class="mt-3 text-3xl font-semibold">{{ $offer->product->name }}</h1><p class="mt-3 text-sm text-[#6f7d66]">{{ $offer->producer->full_name }} · {{ $offer->location->name }}</p></div><span class="h-fit rounded-full bg-[#edf2e7] px-4 py-2 text-sm font-semibold">{{ \App\Models\Offer::STATUSES[$offer->status] }}</span></div>
    @can('manage-offers')
        <section class="mb-6 rounded-3xl border border-[#dfe4da] bg-white p-6">
            <h2 class="text-xl font-semibold">Publicación para compradores</h2>
            <div class="mt-4 rounded-xl bg-[#f5f7f0] p-4 text-sm leading-6" role="status">
                @if($visibleLots->isNotEmpty())
                    <p class="font-semibold">Visible para compradores</p>
                    <p>La cantidad publicada ya aparece en Productos.</p>
                    @foreach($visibleLots as $visibleLot)<a class="mr-4 inline-block font-semibold underline" href="{{ route('marketplace.show', $visibleLot->code) }}">Ver {{ $visibleLot->code }} como comprador →</a>@endforeach
                @elseif($offer->available_until->toDateString() < now('America/Lima')->toDateString() || in_array($offer->status, ['expired', 'cancelled', 'rejected']))
                    <p class="font-semibold">No disponible para compradores</p><p>Revisa el estado y la vigencia de la oferta.</p>
                @elseif(! in_array($offer->status, ['verified', 'published']))
                    <p class="font-semibold">Pendiente de aprobar los datos</p><p>Después de verificarlos, se buscará una referencia de mercado para proponer el precio al productor.</p>
                @elseif($latestAgreement === null)
                    <p class="font-semibold">Pendiente de precio de mercado</p><p>La oferta está verificada. Falta una referencia compatible y vigente para enviar al productor el precio por {{ $offer->baseUnit->code }} y el total de su cantidad. La publicación continúa cuando acepte la propuesta.</p>
                @elseif($latestAgreement->status === 'rejected')
                    <p class="font-semibold">El productor rechazó el precio</p><p>Revisa las condiciones con el productor antes de proponer un nuevo acuerdo.</p>
                @elseif(! $latestAgreement->valid_until->isFuture())
                    <p class="font-semibold">El precio acordado o propuesto venció</p><p>Se necesita una nueva propuesta vigente y la aceptación del productor.</p>
                @elseif($latestAgreement->status === 'proposed')
                    <p class="font-semibold">Esperando la confirmación del productor</p><p>La propuesta de precio está registrada. Si la recibió por WhatsApp, puede aceptarla allí para publicar automáticamente. También puedes registrar abajo su respuesta real y completar la publicación.</p>
                @elseif($canPublishBalance)
                    <p class="font-semibold">Precio aceptado: publicación pendiente</p><p>Ya puedes publicar el saldo libre en un solo paso.</p>
                @else
                    <p class="font-semibold">Revisa los lotes de esta oferta</p><p>Su cantidad puede estar agrupada, reservada o no cumplir alguna condición de publicación. Los lotes pausados no se reactivan automáticamente.</p>
                @endif
            </div>
            <ol class="mt-4 list-inside list-decimal space-y-2 text-sm leading-6 text-[#6f7d66]">
                <li><strong>Revisa la oferta:</strong> comprueba el productor, la cantidad, la clasificación y las fechas. Elige «Aprobar datos de la oferta» cuando estén completos.</li>
                <li><strong>Confirmación del precio:</strong> el sistema usa una referencia de mercado compatible y vigente, calcula el total según el peso y solicita la aceptación del productor por WhatsApp.</li>
                <li><strong>Publicación:</strong> con la aceptación, el saldo disponible se publica para compradores. También puedes completar este paso aquí si la aceptación se registró de forma asistida.</li>
            </ol>
            @can('manage-lots')
                @if($canPublishBalance)
                    <form method="POST" action="{{ route('offers.publish', $offer) }}" class="mt-5">@csrf
                        <input type="hidden" name="version" value="{{ $offer->version }}"><input type="hidden" name="agreement_id" value="{{ $activeAgreement->id }}"><input type="hidden" name="source_key" value="{{ old('source_key', (string) \Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="reason" value="Publicación del saldo aprobado para compradores.">
                        <p class="mb-3 text-sm">Se publicarán {{ $freeQuantity }} {{ $offer->baseUnit->code }} en un lote nuevo, con el precio aceptado por el productor.</p>
                        <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Publicar para compradores</button>
                    </form>
                @endif
            @endcan
            @if($relatedLots->isNotEmpty())<p class="mt-4 text-sm">Lotes de esta oferta: @foreach($relatedLots as $relatedLot)<a class="mr-3 font-semibold underline" href="{{ route('lots.show', $relatedLot) }}">{{ $relatedLot->code }} · {{ \App\Models\Lot::STATUSES[$relatedLot->status] }}</a>@endforeach</p>@endif
            <p class="mt-4 text-sm">Si necesitas combinar varias ofertas, usa la <a class="font-semibold underline" href="{{ route('lots.index') }}">agrupación manual en Lotes</a>.</p>
        </section>
    @endcan
    <div class="grid items-start gap-6 lg:grid-cols-2">
        <section class="rounded-3xl border border-[#dfe4da] bg-white p-7">
            <h2 class="text-xl font-semibold">Datos de la propuesta</h2>
            <dl class="mt-5 grid gap-5 text-sm sm:grid-cols-2">
                <div><dt class="text-[#819077]">Cantidad declarada</dt><dd class="mt-1 font-semibold">{{ $offer->quantity }} {{ $offer->unit->name }}</dd></div>
                <div><dt class="text-[#819077]">Cantidad normalizada</dt><dd class="mt-1 font-semibold">{{ $offer->normalized_quantity ? $offer->normalized_quantity.' '.$offer->baseUnit->code : 'Pendiente de equivalencia' }}</dd></div>
                <div><dt class="text-[#819077]">Variedad</dt><dd class="mt-1">{{ $offer->variety?->name ?? 'Pendiente de clasificar' }}</dd></div><div><dt class="text-[#819077]">Calidad</dt><dd class="mt-1">{{ $offer->qualityGrade?->name ?? 'Pendiente de clasificar' }}</dd></div>
                <div><dt class="text-[#819077]">Disponibilidad</dt><dd class="mt-1">{{ $offer->available_from->format('d/m/Y') }} — {{ $offer->available_until->format('d/m/Y') }}</dd></div>
                <div><dt class="text-[#819077]">Precio solicitado por {{ $offer->baseUnit->code }}</dt><dd class="mt-1">{{ $offer->requested_unit_price ? 'S/ '.$offer->requested_unit_price : 'Por acordar' }}</dd></div>
            </dl>
            <p class="mt-5 text-xs leading-5 text-[#6f7d66]">Equivalencia: {{ $offer->conversion_source ?? 'Sin resolver' }}{{ $offer->conversion_factor ? ' · Factor '.$offer->conversion_factor : '' }}{{ $offer->context ? ' · '.$offer->context : '' }}</p>
            @if($offer->notes)<p class="mt-5 whitespace-pre-line text-sm">{{ $offer->notes }}</p>@endif
            <p class="mt-5 rounded-xl bg-[#f5f7f0] p-4 text-sm">{{ $offer->isAvailableForAggregation() ? 'Publicada y con saldo libre para agrupar en lotes.' : 'Sin saldo elegible para nuevas agrupaciones: revisa cantidades, estado, productor, clasificación, vigencia y acuerdo.' }}</p>
            @can('manage-lots')<a class="mt-4 inline-block text-sm font-semibold underline underline-offset-4" href="{{ route('lots.create', ['product_id' => $offer->product_id]) }}">Revisar agrupación de ofertas →</a>@endcan
            @can('manage-offers')@if($offer->canBeEdited())<a href="{{ route('offers.edit', $offer) }}" class="mt-5 inline-block font-semibold underline">Corregir y enviar otra vez a revisión</a>@endif
@endcan
        </section>
        @can('manage-offers')@if($transitions)
            <section class="rounded-3xl bg-[#254f3b] p-7 text-white"><h2 class="text-xl font-semibold">Revisar oferta</h2><p class="mt-3 text-sm leading-6 text-[#d0ddcb]">Publicar requiere productor verificado, datos completos y condiciones aceptadas. Volver de publicada a verificada pausa nuevas agrupaciones.</p>
                <form method="POST" action="{{ route('offers.transition', $offer) }}" class="mt-5 space-y-4">@csrf<input type="hidden" name="version" value="{{ $offer->version }}">
                    <div><label for="result" class="mb-2 block text-sm font-semibold">Decisión</label><select id="result" name="result" required class="w-full rounded-xl bg-white p-3 text-[#243c32]"><option value="">Selecciona una decisión</option>@foreach($transitions as $result)<option value="{{ $result }}" @selected(old('result') === $result)>{{ match($result) { 'verified' => $offer->status === 'published' ? 'Pausar publicación y conservar verificación' : 'Aprobar datos de la oferta', 'published' => 'Habilitar agrupación manual en lotes', 'needs_information' => 'Solicitar información o correcciones', 'rejected' => 'Rechazar oferta', 'cancelled' => 'Cancelar oferta', default => \App\Models\Offer::STATUSES[$result] } }}</option>@endforeach</select></div>
                    <x-field name="reason" label="Motivo de la decisión" required minlength="5" maxlength="1000" />
                    <button class="rounded-xl bg-white px-5 py-3 font-semibold text-[#22583e]">Guardar decisión</button>
                </form>
            </section>
        @endif
@endcan
    </div>
    <section class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7">
        <h2 class="text-xl font-semibold">Condiciones económicas por {{ $offer->baseUnit->code }}</h2>
        <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Precio bruto menos costos a cargo del productor = neto por unidad base. Las condiciones se conservan por versión. Una propuesta o un mensaje leído no equivalen a aceptación.</p>
        @can('manage-offers')@if($offer->status === 'verified')
            <details class="mt-5 rounded-xl bg-[#f5f7f0] p-5"><summary class="cursor-pointer font-semibold">Proponer condiciones</summary>
                <form method="POST" action="{{ route('offers.propose', $offer) }}" class="mt-5 grid gap-4 sm:grid-cols-2">@csrf<input type="hidden" name="version" value="{{ $offer->version }}">
                    <x-field name="gross_unit_price" label="Bruto por unidad base (S/)" required inputmode="decimal" /><x-field name="producer_unit_cost" label="Costos del productor por unidad base (S/)" required inputmode="decimal" hint="Indica 0 si el productor no asume costos." />
                    <x-field name="valid_until" label="Vigencia de las condiciones hasta" type="date" required :max="$offer->available_until->format('Y-m-d')" />
                    <div class="sm:col-span-2"><label for="terms" class="mb-2 block text-sm font-semibold">Desglose de costos y condiciones</label><textarea id="terms" name="terms" required minlength="10" maxlength="2000" rows="3" class="w-full rounded-xl border border-[#cdd8c7] p-3">{{ old('terms') }}</textarea></div>
                    <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Guardar propuesta</button>
                </form>
            </details>
        @endif
@endcan
        @forelse($offer->agreements as $agreement)
            <article class="mt-6 border-t border-[#e5eadf] pt-5"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-semibold">Propuesta {{ $agreement->version }} · Oferta versión {{ $agreement->offer_version }}</h3><span class="text-sm">{{ ['proposed' => 'Pendiente de respuesta', 'accepted' => 'Aceptada', 'rejected' => 'Rechazada'][$agreement->status] }}</span></div>
                <p class="mt-3 text-sm">Bruto S/ {{ $agreement->gross_unit_price }} − Costos S/ {{ $agreement->producer_unit_cost }} = <strong>Neto S/ {{ $agreement->net_unit_price }}</strong></p>
                <p class="mt-3 whitespace-pre-line text-sm leading-6">{{ $agreement->terms }}</p><p class="mt-3 text-xs text-[#788373]">Válida hasta {{ $agreement->valid_until->timezone('America/Lima')->format('d/m/Y H:i') }} (Perú)</p>
                @if($agreement->evidence)<p class="mt-3 whitespace-pre-line text-sm">Constancia: {{ $agreement->evidence }}</p>@endif
                @can('manage-offers')@if($agreement->status === 'proposed' && $offer->status === 'verified' && $loop->first)
                    <form method="POST" action="{{ route('offers.decide', [$offer, $agreement]) }}" class="mt-5 space-y-4">@csrf<input type="hidden" name="version" value="{{ $offer->version }}">
                        <div><label for="decision-{{ $agreement->id }}" class="mb-2 block text-sm font-semibold">Respuesta comunicada por el productor</label><select id="decision-{{ $agreement->id }}" name="decision" required class="rounded-xl border border-[#cdd8c7] p-3"><option value="">Selecciona su respuesta</option><option value="accepted">Aceptó estas condiciones</option><option value="rejected">Rechazó estas condiciones</option></select></div>
                        <div><label for="evidence-{{ $agreement->id }}" class="mb-2 block text-sm font-semibold">Evidencia y referencia de la respuesta</label><textarea id="evidence-{{ $agreement->id }}" name="evidence" required minlength="10" maxlength="2000" rows="2" class="w-full rounded-xl border border-[#cdd8c7] p-3">{{ old('evidence') }}</textarea></div>
                        <button class="rounded-xl border border-[#c4d1bb] px-5 py-3 font-semibold">Registrar respuesta del productor</button>
                    </form>
                @endif
@endcan
            </article>
        @empty<p class="mt-5 text-sm text-[#6f7d66]">Aún no hay condiciones propuestas.</p>@endforelse
    </section>
    <section class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7"><h2 class="text-xl font-semibold">Fotografías privadas</h2>
        <div class="mt-4 space-y-3">@forelse($offer->photos as $photo)<p class="text-sm"><a class="font-semibold underline" href="{{ route('offers.photo', [$offer, $photo]) }}">Descargar foto #{{ $photo->id }}</a> · {{ $photo->caption }}</p>@empty<p class="text-sm text-[#6f7d66]">No se han añadido fotografías.</p>@endforelse</div>
        @can('manage-offers')@unless(in_array($offer->status, ['cancelled', 'expired']))
            <form method="POST" action="{{ route('offers.upload', $offer) }}" enctype="multipart/form-data" class="mt-5 space-y-4">@csrf
                <div><label for="photo" class="mb-2 block text-sm font-semibold">Añadir imagen (JPEG, PNG o WebP, hasta 5 MB)</label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required class="max-w-full text-sm"></div>
                <x-field name="caption" label="Descripción de la foto (opcional)" maxlength="500" /><button class="rounded-xl border border-[#c4d1bb] px-5 py-3 font-semibold">Guardar foto privada</button>
            </form>
        @endunless
@endcan
    </section>
    <section class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7"><h2 class="text-xl font-semibold">Historial de la oferta</h2>
        @foreach($offer->histories as $event)<article class="mt-4 border-t border-[#e5eadf] pt-4"><p class="text-sm font-semibold">{{ $event->reason }}</p><p class="mt-2 text-xs text-[#788373]">{{ $event->actor?->name ?? 'Registro por mensajería / sistema' }} · Versión {{ $event->offer_version }} · {{ $event->occurred_at->timezone('America/Lima')->format('d/m/Y H:i') }}</p>@if(isset($event->changes['status']))<p class="mt-2 text-sm">{{ \App\Models\Offer::STATUSES[$event->changes['status']['before'] ?? ''] ?? 'Sin registro' }} → {{ \App\Models\Offer::STATUSES[$event->changes['status']['after']] }}</p>@endif</article>@endforeach
    </section>
</x-layout>
