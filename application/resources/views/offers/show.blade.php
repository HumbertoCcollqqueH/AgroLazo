<x-layout :title="'Oferta #'.$offer->id">
    <a href="{{ route('offers.index') }}" class="text-sm font-semibold underline">← Volver a ofertas</a>
    <div class="my-7 flex flex-wrap justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Oferta #{{ $offer->id }} · Versión {{ $offer->version }}</p><h1 class="mt-3 text-3xl font-semibold">{{ $offer->product->name }}</h1><p class="mt-3 text-sm text-[#6f7d66]"><a class="font-semibold underline" href="{{ route('producers.show', $offer->producer) }}">{{ $offer->producer->full_name }}</a> · {{ $offer->location->name }}</p></div><span class="h-fit rounded-full bg-[#edf2e7] px-4 py-2 text-sm font-semibold">{{ \App\Models\Offer::STATUSES[$offer->status] }}</span></div>
    @can('manage-offers')
        <section class="mb-6 rounded-3xl border border-[#dfe4da] bg-white p-6">
            <h2 class="text-xl font-semibold">Una revisión, publicación automática</h2>
            <p class="mt-2 text-sm leading-6 text-[#6f7d66]">Revisa los datos y prepara el precio en un solo paso. El productor acepta desde su cuenta y AgroLazo publica la cantidad disponible, agrupándola con aportaciones compatibles y cercanas. No hay una segunda aprobación del equipo.</p>
            <div class="mt-4 rounded-xl bg-[#f5f7f0] p-4 text-sm leading-6" role="status">
                @if($visibleLots->isNotEmpty())
                    <p class="font-semibold">Visible para compradores</p>
                    <p>La cantidad publicada ya aparece en Productos.</p>
                    @foreach($visibleLots as $visibleLot)<a class="mr-4 inline-block font-semibold underline" href="{{ route('marketplace.show', $visibleLot->code) }}">Ver {{ $visibleLot->code }} como comprador →</a>@endforeach
                @elseif($offer->available_until->toDateString() < now('America/Lima')->toDateString() || in_array($offer->status, ['expired', 'cancelled', 'rejected']))
                    <p class="font-semibold">No disponible para compradores</p><p>Revisa el estado y la vigencia de la oferta.</p>
                @elseif(! in_array($offer->status, ['verified', 'published']))
                    <p class="font-semibold">Pendiente de tu revisión</p><p>Comprueba los datos y aprueba junto con el precio usando el formulario de abajo.</p>
                @elseif($latestAgreement === null)
                    <p class="font-semibold">Pendiente de precio de mercado</p><p>Los datos ya están aprobados. Completa una propuesta de precio abajo; no necesitas volver a verificar esta oferta. El productor podrá aceptarla y publicar.</p>
                @elseif($latestAgreement->status === 'rejected')
                    <p class="font-semibold">El productor rechazó el precio</p><p>Revisa las condiciones con el productor antes de proponer un nuevo acuerdo.</p>
                @elseif(! $latestAgreement->valid_until->isFuture())
                    <p class="font-semibold">El precio acordado o propuesto venció</p><p>Se necesita una nueva propuesta vigente y la aceptación del productor.</p>
                @elseif($latestAgreement->status === 'proposed')
                    <p class="font-semibold">Esperando la confirmación del productor</p><p>Tu revisión terminó. Al pulsar «Aceptar precio y publicar» en su cuenta, el productor completa la publicación. Si ya te respondió dentro de AgroLazo, puedes registrar su respuesta y referencia abajo: la publicación también será automática.</p>
                @elseif($canPublishBalance)
                    <p class="font-semibold">Precio aceptado: publicación pendiente</p><p>Esta aceptación anterior tiene saldo sin publicar. Puedes completar su publicación con el botón de abajo. Las nuevas aceptaciones ya completan este paso automáticamente.</p>
                @else
                    <p class="font-semibold">Revisa los lotes de esta oferta</p><p>Su cantidad puede estar agrupada, reservada o no cumplir alguna condición de publicación. Los lotes pausados no se reactivan automáticamente.</p>
                @endif
            </div>
            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3"><div class="rounded-xl border border-[#dfe4da] p-4"><strong>1. Equipo</strong><p class="mt-1">Revisa datos y precio.</p></div><div class="rounded-xl border border-[#dfe4da] p-4"><strong>2. Productor</strong><p class="mt-1">Acepta el precio y el total.</p></div><div class="rounded-xl border border-[#dfe4da] p-4"><strong>3. AgroLazo · automático</strong><p class="mt-1">Publica y agrupa lo compatible.</p></div></div>
            @can('manage-lots')
                @if($canPublishBalance)
                    <form method="POST" action="{{ route('offers.publish', $offer) }}" class="mt-5">@csrf
                        <input type="hidden" name="version" value="{{ $offer->version }}"><input type="hidden" name="agreement_id" value="{{ $activeAgreement->id }}"><input type="hidden" name="source_key" value="{{ old('source_key', (string) \Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="reason" value="Publicación del saldo aprobado para compradores.">
                        <p class="mb-3 text-sm">Se publicarán {{ $freeQuantity }} {{ $offer->baseUnit->code }} con el precio aceptado. El sistema reunirá los saldos compatibles cuando su ubicación y disponibilidad lo permitan.</p>
                        <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Publicar para compradores</button>
                    </form>
                @endif
            @endcan
            @if($relatedLots->isNotEmpty())<p class="mt-4 text-sm">Lotes de esta oferta: @foreach($relatedLots as $relatedLot)<a class="mr-3 font-semibold underline" href="{{ route('lots.show', $relatedLot) }}">{{ $relatedLot->code }} · {{ \App\Models\Lot::STATUSES[$relatedLot->status] }}</a>@endforeach</p>@endif
            <p class="mt-4 text-sm">La agrupación automática respeta producto, variedad, calidad, precio, fechas y cercanía. Puedes consultar sus aportaciones y el mapa en <a class="font-semibold underline" href="{{ route('lots.index') }}">Lotes</a>.</p>
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
            @if($visibleLots->isNotEmpty())<p class="mt-5 rounded-xl bg-[#f5f7f0] p-4 text-sm">Su cantidad disponible ya forma parte de los lotes de la tienda. Las compras y recojos se coordinan desde Pedidos.</p>@endif
            @can('manage-offers')@if($offer->canBeEdited())<a href="{{ route('offers.edit', $offer) }}" class="mt-5 inline-block font-semibold underline">Corregir y enviar otra vez a revisión</a>@endif
@endcan
        </section>
        @can('manage-offers')
        @if($offer->status === 'pending_verification')
            <section class="rounded-3xl border border-[#cdd8c7] bg-white p-7"><h2 class="text-xl font-semibold">Revisar y enviar el precio</h2><p class="mt-3 text-sm leading-6 text-[#6f7d66]">Comprueba cantidad, variedad, calidad, ubicación y fechas. Solo quedará que el productor confirme el precio.</p>
                <form method="POST" action="{{ route('offers.transition', $offer) }}" class="mt-5 space-y-5">@csrf
                    <input type="hidden" name="version" value="{{ $offer->version }}"><input type="hidden" name="result" value="verified"><input type="hidden" name="producer_unit_cost" value="0">
                    <fieldset class="space-y-4"><legend class="mb-3 font-semibold">Precio para el productor</legend>
                        @if($marketReference)
                            <label class="flex items-start gap-3 rounded-xl border border-[#dfe4da] p-4"><input class="mt-1" type="radio" name="price_mode" value="automatic" @checked(old('price_mode', 'automatic') === 'automatic')><span><strong>Usar la referencia de mercado</strong><span class="mt-1 block text-sm">S/ {{ number_format((float) $marketReference['unit_price'], 2) }} / {{ $offer->baseUnit->code }} · {{ $marketReference['market'] }} · {{ $marketReference['observed_on'] }}</span><span class="mt-1 block text-xs text-[#6f7d66]">El sistema comprueba su vigencia al enviar. Transporte cubierto por AgroLazo.</span></span></label>
                        @else
                            <p class="rounded-xl bg-[#fff7e7] p-4 text-sm leading-6">No hay una referencia vigente para esta clasificación. Escribe un precio revisado para enviarlo junto con tu aprobación. No se inventará un precio de mercado.</p>
                        @endif
                        <label class="flex items-start gap-3"><input class="mt-1" type="radio" name="price_mode" value="manual" @checked(old('price_mode', $marketReference ? 'automatic' : 'manual') === 'manual')><span class="font-semibold">Proponer un precio revisado por el equipo</span></label>
                        <div class="grid gap-4 sm:grid-cols-2"><x-field name="gross_unit_price" :label="'Precio por '.$offer->baseUnit->code.' (S/)'" :value="$offer->requested_unit_price" inputmode="decimal" hint="Se usa al seleccionar el precio del equipo." /><x-field name="valid_until" label="Precio válido hasta" type="date" :value="max($offer->available_from->toDateString(), min($offer->available_until->toDateString(), now('America/Lima')->addDays(7)->toDateString()))" :max="$offer->available_until->toDateString()" /></div>
                        <label class="block text-sm font-semibold">Detalle del precio<textarea name="terms" rows="3" maxlength="2000" class="mt-2 w-full rounded-xl border border-[#cdd8c7] p-3 font-normal">{{ old('terms', 'Precio propuesto por el equipo tras revisar la oferta. Sin descuentos al productor. AgroLazo organiza y cubre el transporte. Al aceptar se publica automáticamente la cantidad disponible.') }}</textarea></label>
                    </fieldset>
                    <x-field name="reason" label="Observación de la revisión" value="Datos de producción, cantidad, clasificación y disponibilidad revisados." required minlength="5" maxlength="1000" />
                    <button class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Aprobar y enviar precio</button><p class="text-xs leading-5 text-[#6f7d66]">El productor recibirá un aviso dentro de AgroLazo con el importe por unidad y el total. Su aceptación publica y agrupa automáticamente.</p>
                </form>
            </section>
        @elseif($offer->status === 'verified' && $latestAgreement?->status === 'proposed' && $latestAgreement->valid_until->isFuture())
            <section class="rounded-3xl border border-[#dfe4da] bg-[#edf2e7] p-7"><h2 class="text-xl font-semibold">Tu revisión ya está completa</h2><p class="mt-3 text-sm leading-6">El productor tiene el precio en su cuenta. Cuando lo acepte, AgroLazo publicará su cantidad y la agrupará automáticamente. Puedes conversar con él si necesita ayuda.</p><a class="mt-5 inline-block rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white" href="{{ route('coordination.index') }}">Abrir Conversaciones</a></section>
        @endif
        @endcan
    </div>
    @can('manage-offers')@if($transitions)
            <details class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-6"><summary class="cursor-pointer font-semibold">Solicitar correcciones, pausar o cancelar</summary><p class="mt-3 text-sm leading-6 text-[#6f7d66]">Usa estas acciones si la oferta necesita atención. La publicación normal se completa al aceptar el precio.</p>
                <form method="POST" action="{{ route('offers.transition', $offer) }}" class="mt-5 space-y-4">@csrf<input type="hidden" name="version" value="{{ $offer->version }}">
                    <div><label for="result" class="mb-2 block text-sm font-semibold">Decisión</label><select id="result" name="result" required class="w-full rounded-xl border border-[#cdd8c7] bg-white p-3 text-[#243c32]"><option value="">Selecciona una decisión</option>@foreach($transitions as $result)@continue($result === 'published' || ($result === 'verified' && $offer->status !== 'published'))<option value="{{ $result }}" @selected(old('result') === $result)>{{ match($result) { 'verified' => 'Pausar nuevas agrupaciones', 'needs_information' => 'Solicitar información o correcciones', 'rejected' => 'Rechazar oferta', 'cancelled' => 'Cancelar oferta', default => \App\Models\Offer::STATUSES[$result] } }}</option>@endforeach</select></div>
                    <label class="block text-sm font-semibold">Motivo de la decisión<textarea name="reason" required minlength="5" maxlength="1000" rows="2" class="mt-2 w-full rounded-xl border border-[#cdd8c7] p-3 font-normal">{{ old('reason') }}</textarea></label>
                    <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold text-[#22583e]">Guardar decisión</button>
                </form>
            </details>
        @endif
@endcan
    <section class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7">
        <h2 class="text-xl font-semibold">Precio y respuesta del productor</h2>
        <p class="mt-3 text-sm leading-6 text-[#6f7d66]">El productor recibe un importe claro por {{ $offer->baseUnit->code }} y el total de su cantidad. Aceptar publica automáticamente; proponer un precio o leer un mensaje todavía no confirma el acuerdo.</p>
        @can('manage-offers')@if($offer->status === 'verified')
            <details class="mt-5 rounded-xl bg-[#f5f7f0] p-5" @if(! $latestAgreement || $latestAgreement->status === 'rejected' || ! $latestAgreement->valid_until->isFuture()) open @endif><summary class="cursor-pointer font-semibold">{{ $latestAgreement ? 'Proponer un nuevo precio' : 'Completar el precio · datos ya aprobados' }}</summary>
                <form method="POST" action="{{ route('offers.propose', $offer) }}" class="mt-5 grid gap-4 sm:grid-cols-2">@csrf<input type="hidden" name="version" value="{{ $offer->version }}">
                    <input type="hidden" name="producer_unit_cost" value="0"><x-field name="gross_unit_price" :label="'Precio por '.$offer->baseUnit->code.' (S/)'" :value="$marketReference['unit_price'] ?? $offer->requested_unit_price" required inputmode="decimal" />
                    <x-field name="valid_until" label="Precio válido hasta" type="date" required :value="max($offer->available_from->toDateString(), min($offer->available_until->toDateString(), now('America/Lima')->addDays(7)->toDateString()))" :max="$offer->available_until->format('Y-m-d')" />
                    <div class="sm:col-span-2"><label for="terms" class="mb-2 block text-sm font-semibold">Detalle del precio</label><textarea id="terms" name="terms" required minlength="10" maxlength="2000" rows="3" class="w-full rounded-xl border border-[#cdd8c7] p-3">{{ old('terms', 'Precio propuesto por el equipo tras revisar la oferta. Sin descuentos al productor. AgroLazo organiza y cubre el transporte. Al aceptar se publica automáticamente.') }}</textarea></div>
                    <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Enviar precio al productor</button>
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
                    <details class="mt-5 rounded-xl border border-[#dfe4da] p-4"><summary class="cursor-pointer font-semibold">El productor ya respondió en AgroLazo · registrar su decisión</summary><p class="mt-3 text-sm leading-6">Usa esta opción únicamente si tienes su respuesta expresa. Incluye la referencia de la conversación. Registrar una aceptación publicará automáticamente la cantidad disponible.</p><form method="POST" action="{{ route('offers.decide', [$offer, $agreement]) }}" class="mt-5 space-y-4">@csrf<input type="hidden" name="version" value="{{ $offer->version }}">
                        <div><label for="decision-{{ $agreement->id }}" class="mb-2 block text-sm font-semibold">Respuesta comunicada por el productor</label><select id="decision-{{ $agreement->id }}" name="decision" required class="rounded-xl border border-[#cdd8c7] p-3"><option value="">Selecciona su respuesta</option><option value="accepted">Aceptó estas condiciones</option><option value="rejected">Rechazó estas condiciones</option></select></div>
                        <div><label for="evidence-{{ $agreement->id }}" class="mb-2 block text-sm font-semibold">Evidencia y referencia de la respuesta</label><textarea id="evidence-{{ $agreement->id }}" name="evidence" required minlength="10" maxlength="2000" rows="2" class="w-full rounded-xl border border-[#cdd8c7] p-3">{{ old('evidence') }}</textarea></div>
                        <button class="rounded-xl border border-[#c4d1bb] px-5 py-3 font-semibold">Registrar respuesta y continuar automáticamente</button>
                    </form></details>
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
