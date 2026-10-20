<div id="programar-acopio" class="mt-6 grid gap-6 lg:grid-cols-2">
    <x-commerce-panel title="Acopio y recepción">
        @forelse($collections as $collection)
            <div class="mb-5 rounded-xl bg-[#f5f7f1] p-5 text-sm">
                <p class="font-semibold">Acopio #{{ $collection->id }} · <x-commerce-status :value="$collection->status" /></p>
                <p class="mt-2">{{ $collection->point_snapshot['name'] }} · {{ $collection->point_snapshot['address'] }}</p>
                <p class="mt-2">{{ $collection->starts_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}–{{ $collection->ends_at->setTimezone('America/Lima')->format('H:i') }} · Hora de Perú</p>
                @if($displayPermissions->contains('manage-commerce'))
                    @if($collection->status === 'scheduled')
                        <details class="mt-4"><summary class="cursor-pointer font-semibold">Registrar pesaje y calidad</summary>
                            <form method="POST" action="{{ route('fulfillment.receipts', $collection) }}" class="mt-4 space-y-4">
                                <x-commerce-key />
                                <x-commerce-select name="allocation_id" label="Productor y aportación" required>
                                    @foreach($collection->items as $entry)
                                        <option value="{{ $entry->order_allocation_id }}">#{{ $entry->order_allocation_id }} · {{ $entry->allocation->portion->offer->producer->full_name }}</option>
                                    @endforeach
                                </x-commerce-select>
                                <div class="grid grid-cols-2 gap-4">
                                    <x-field name="expected_quantity" label="Esperado en esta entrega" type="number" min="0" step="0.001" required />
                                    <x-field name="received_quantity" label="Peso recibido" type="number" min="0" step="0.001" required />
                                    <x-field name="accepted_quantity" label="Aceptado" type="number" min="0" step="0.001" required />
                                    <x-field name="rejected_quantity" label="Rechazado" type="number" min="0" step="0.001" required />
                                </div>
                                <x-commerce-select name="quality_status" label="Resultado de calidad">
                                    <option value="accepted">Aceptado</option><option value="partial">Parcialmente rechazado</option><option value="rejected">Rechazado o sin recepción</option>
                                </x-commerce-select>
                                <x-field name="arrived_at" label="Llegada real (hora de Perú)" type="datetime-local" required />
                                <x-field name="notes" label="Control de calidad, pesaje y evidencia de la entrega" required minlength="10" />
                                <p class="text-xs leading-5 text-[#6f7d66]">Recibido = aceptado + rechazado. Un excedente se registra como rechazado o retenido fuera del stock aceptado hasta resolverlo; no aumenta la compra.</p>
                                <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Registrar recepción</button>
                            </form>
                        </details>
                    @endif
                @endif
            </div>
        @empty
            <p class="text-sm text-[#6f7d66]">El equipo todavía no ha programado el acopio.</p>
        @endforelse
        @if($displayPermissions->contains('manage-commerce'))
            @if(in_array($order->status, ['confirmed','collecting']))
                <details @if(! $collections->contains('status', 'scheduled')) open @endif class="mt-6 border-t border-[#dfe4da] pt-5"><summary class="cursor-pointer font-semibold">Programar o cambiar acopio</summary>
                    <p class="mt-4 text-xs leading-6 text-[#6f7d66]">{{ $suggestion['basis'] }}</p>
                    @if($suggestion['missing_producer_ids'])<p class="mt-3 text-sm">Completa el punto de recojo y la autorización para planificar acopio en las fichas de los productores: @foreach($suggestion['missing_producer_ids'] as $producerId)<a class="underline" href="{{ route('producers.show', $producerId) }}">#{{ $producerId }}</a> @endforeach</p>@endif
                    @if(!$suggestion['recommended_point_id'])<p class="mt-3 text-sm">No hay un punto recomendado todavía. Revisa las ubicaciones, la cercanía y la capacidad antes de programar el acopio.</p>@endif
                    <form method="POST" action="{{ route('fulfillment.schedule', $order) }}" class="mt-4 space-y-4">
                        <x-commerce-key /><input type="hidden" name="version" value="{{ $order->version }}">
                        <x-commerce-select name="point_id" label="Punto registrado" required>
                            @foreach($suggestion['points'] as $option)
                                <option value="{{ $option['point']->id }}" @selected((string) old('point_id', $suggestion['recommended_point_id']) === (string) $option['point']->id)>{{ $suggestion['recommended_point_id'] === $option['point']->id ? 'Recomendado · ' : '' }}{{ $option['point']->name }} · capacidad {{ $option['point']->capacity }} {{ $order->quote->baseUnit->code }}{{ $option['max_distance_km'] !== null ? ' · productor más lejano ≈ '.$option['max_distance_km'].' km' : ' · falta ubicación' }}{{ !$option['eligible'] ? ' · requiere revisión' : '' }}</option>
                            @endforeach
                        </x-commerce-select>
                        <a class="inline-block text-xs underline" href="{{ route('fulfillment.points') }}">Registrar un punto de acopio</a>
                        <x-field name="starts_at" label="Inicio (hora de Perú)" type="datetime-local" required />
                        <x-field name="ends_at" label="Fin (hora de Perú)" type="datetime-local" required />
                        <fieldset class="space-y-4 rounded-xl bg-[#f5f7f1] p-4"><legend class="font-semibold">Recojo de cada aportación</legend>
                            <p class="text-xs leading-6">Registra el punto de recojo acordado con el productor y cuándo pasará nuestro transporte. Debe ser antes de la recepción en acopio. Estos datos se enviarán automáticamente a su cuenta de AgroLazo.</p>
                            @foreach($order->items as $orderItem) @foreach($orderItem->allocations as $allocation)
                                @if(bccomp($allocation->quantity, $allocation->released_quantity, 3) > 0)
                                    <div class="space-y-3 border-t border-[#dfe4da] pt-3"><p class="text-sm font-semibold">{{ $allocation->portion->offer->producer->full_name }} · Oferta #{{ $allocation->portion->offer_id }}</p>
                                        @php
                                            $pickupLocation = $allocation->portion->offer->producer->currentLocation;
                                            $pickupReference = $pickupLocation?->location_id === $allocation->portion->offer->location_id ? collect([$pickupLocation?->address, $pickupLocation?->community])->filter()->implode(' · ') : '';
                                        @endphp
                                        <label class="block text-sm">Dirección y referencia de recojo<input class="mt-2 w-full rounded-xl border p-3" name="pickups[{{ $allocation->id }}][address]" value="{{ old('pickups.'.$allocation->id.'.address', $pickupReference) }}" required minlength="10" maxlength="1000"></label>
                                        @if($pickupReference)<p class="text-xs">Referencia tomada de la ficha del productor. Confirma que corresponda a esta producción antes de programar.</p>@endif
                                        <label class="block text-sm">Pasaremos el día y hora (Perú)<input class="mt-2 w-full rounded-xl border p-3" type="datetime-local" name="pickups[{{ $allocation->id }}][at]" value="{{ old('pickups.'.$allocation->id.'.at') }}" required></label>
                                    </div>
                                @endif
                            @endforeach @endforeach
                        </fieldset>
                        <x-field name="reason" label="Motivo, coordinación y accesos confirmados" required minlength="10" />
                        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="location_confirmed" value="1" required>Confirmé el punto y los accesos con los productores; aclaré cualquier ubicación ambigua.</label>
                        <p class="text-xs leading-6 text-[#6f7d66]">Al confirmar se prepara un aviso para cada productor con su producto, cantidad, punto y horario. Puedes consultar su entrega en los avisos de esta orden.</p>
                        <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Confirmar programación y preparar avisos</button>
                    </form>
                </details>
            @endif
        @endif
        <h3 class="mb-3 mt-7 font-semibold">Recepciones registradas</h3>
        @forelse($receipts as $receipt)
            <div class="mb-3 rounded-xl border border-[#dfe4da] p-4 text-sm">
                <p class="font-semibold">Recepción #{{ $receipt->id }} · Aportación #{{ $receipt->order_allocation_id }}</p>
                <p class="mt-2">Esperado {{ $receipt->expected_quantity }} · Recibido {{ $receipt->received_quantity }} · Aceptado {{ $receipt->accepted_quantity }} · Rechazado {{ $receipt->rejected_quantity }} {{ $order->quote->baseUnit->code }}</p>
                @if($displayPermissions->contains('manage-commerce'))
                    <p class="mt-2">{{ $receipt->notes }}</p>
                    <div class="mt-3 flex flex-wrap gap-3">@foreach($receipt->photos as $photo)<a class="underline" href="{{ route('fulfillment.photo', $photo) }}">Foto privada #{{ $photo->id }}</a>@endforeach</div>
                    <details class="mt-3"><summary class="cursor-pointer text-xs font-semibold">Adjuntar fotografía privada</summary><form method="POST" action="{{ route('fulfillment.photos', $receipt) }}" enctype="multipart/form-data" class="mt-3 space-y-3"><x-commerce-key /><input class="w-full text-xs" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required><button class="rounded-lg border border-[#cdd8c7] px-4 py-2 text-xs">Guardar fotografía · Máx. 5 MB</button></form></details>
                @endif
            </div>
        @empty
            <p class="text-sm text-[#6f7d66]">Todavía no hay pesajes.</p>
        @endforelse
    </x-commerce-panel>
    <x-commerce-panel title="Consolidación física y despacho">
        @foreach($physicalLots as $physical)
            <div class="mb-4 rounded-xl bg-[#f5f7f1] p-4 text-sm"><h3 class="font-semibold">{{ $physical->code }}</h3>@foreach($physical->items as $physicalItem)<p class="mt-2">Porción física #{{ $physicalItem->id }}: {{ $physicalItem->quantity }} {{ $order->quote->baseUnit->code }} de recepción #{{ $physicalItem->collection_receipt_id }}.</p>@endforeach</div>
        @endforeach
        @if($displayPermissions->contains('manage-commerce'))
            @if($receipts->isNotEmpty() && ! in_array($order->status, ['closed', 'cancelled']))
                <details class="mb-5"><summary class="cursor-pointer font-semibold">Consolidar recepciones aceptadas</summary>
                    <form method="POST" action="{{ route('fulfillment.consolidate', $order) }}" class="mt-4 space-y-4"><x-commerce-key />
                        @foreach($receipts->where('accepted_quantity', '>', 0) as $receipt)
                            <div class="flex items-center gap-3"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="selected[]" value="{{ $receipt->id }}">Recepción #{{ $receipt->id }}</label><input aria-label="Cantidad de recepción {{ $receipt->id }} a consolidar" class="min-w-0 flex-1 rounded-xl border border-[#cdd8c7] px-3 py-2" type="number" min="0.001" step="0.001" name="quantities[{{ $receipt->id }}]" value="{{ $receipt->accepted_quantity }}"></div>
                        @endforeach
                        <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Crear lote físico</button>
                    </form>
                </details>
            @endif
            @if($physicalLots->isNotEmpty() && ! in_array($order->status, ['closed', 'cancelled']))
                <details class="mb-6"><summary class="cursor-pointer font-semibold">Preparar despacho o retiro</summary>
                    <form method="POST" action="{{ route('fulfillment.shipment', $order) }}" class="mt-4 space-y-4"><x-commerce-key />
                        @foreach($physicalLots as $physical)
                            @foreach($physical->items as $physicalItem)
                                <div class="flex items-center gap-3"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="selected[]" value="{{ $physicalItem->id }}">Porción #{{ $physicalItem->id }}</label><input aria-label="Cantidad física {{ $physicalItem->id }} a despachar" class="min-w-0 flex-1 rounded-xl border border-[#cdd8c7] px-3 py-2" type="number" min="0.001" step="0.001" name="quantities[{{ $physicalItem->id }}]" value="{{ $physicalItem->quantity }}"></div>
                            @endforeach
                        @endforeach
                        <x-field name="carrier" label="Transportista (opcional)" /><x-field name="vehicle" label="Vehículo (opcional)" />
                        <x-field name="cost_minor" label="Costo interno de transporte (céntimos)" hint="Lo cubre la plataforma en las nuevas compras; no modifica el precio del comprador ni el pago al productor." value="0" type="number" min="0" step="1" required />
                        <x-field name="scheduled_at" label="Salida programada (hora de Perú)" type="datetime-local" required />
                        <x-field name="notes" label="Responsable, modalidad de salida y condiciones" required minlength="10" />
                        <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Preparar salida</button>
                    </form>
                </details>
            @endif
        @endif
        @forelse($shipments as $shipment)
            <article class="mb-5 rounded-xl border border-[#dfe4da] p-5 text-sm">
                <h3 class="font-semibold">{{ $shipment->code }} · <x-commerce-status :value="$shipment->status" /></h3><p class="mt-2">Programado: {{ $shipment->scheduled_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}</p>
                @foreach($shipment->items as $shipmentItem)<p class="mt-3">Porción #{{ $shipmentItem->id }} · {{ $shipmentItem->quantity }} {{ $order->quote->baseUnit->code }} cargados; {{ $shipmentItem->delivered_quantity }} entregados; {{ $shipmentItem->returned_quantity }} devueltos; {{ $shipmentItem->lost_quantity }} perdidos.</p>@endforeach
                @if($displayPermissions->contains('manage-commerce'))
                    @if($shipment->status === 'scheduled')
                        <form method="POST" action="{{ route('fulfillment.dispatch', $shipment) }}" class="mt-4 space-y-3"><x-commerce-key /><input type="hidden" name="version" value="{{ $shipment->version }}"><x-field name="notes" label="Constancia de salida y responsable" required minlength="10" /><button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Registrar salida</button></form>
                    @elseif($shipment->status === 'in_transit')
                        <form method="POST" action="{{ route('fulfillment.outcome', $shipment) }}" class="mt-4 space-y-3"><x-commerce-key /><input type="hidden" name="version" value="{{ $shipment->version }}">
                            <x-commerce-select name="item_id" label="Porción en tránsito">@foreach($shipment->items as $shipmentItem)<option value="{{ $shipmentItem->id }}">#{{ $shipmentItem->id }} · {{ $shipmentItem->quantity }} {{ $order->quote->baseUnit->code }}</option>@endforeach</x-commerce-select>
                            <x-commerce-select name="outcome" label="Movimiento"><option value="delivered">Entregado al comprador</option><option value="returned">Devuelto, pendiente de resolución</option><option value="lost">Pérdida documentada</option></x-commerce-select>
                            <x-field name="quantity" label="Cantidad de este movimiento" type="number" min="0.001" step="0.001" required />
                            <x-field name="notes" label="Constancia de entrega o incidencia y responsable" required minlength="10" />
                            <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Registrar movimiento</button>
                        </form>
                    @endif
                @endif
            </article>
        @empty
            <p class="text-sm text-[#6f7d66]">No hay despachos preparados. Se necesita mercancía recibida y consolidada.</p>
        @endforelse
    </x-commerce-panel>
</div>
@if($incidents->isNotEmpty())
    <x-commerce-panel title="Incidencias de mercancía" class="mt-6">
        @foreach($incidents as $incident)
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm"><p class="font-semibold">#{{ $incident->id }} · {{ $incident->kind }} · {{ $incident->quantity }} {{ $order->quote->baseUnit->code }} · <x-commerce-status :value="$incident->status" /></p><p class="mt-2">Aportación #{{ $incident->order_allocation_id }}</p>
                @if($displayPermissions->contains('manage-commerce'))
                    @if($incident->status === 'open')
                        <form method="POST" action="{{ route('fulfillment.resolve', $incident) }}" class="mt-4 space-y-3"><x-commerce-key /><x-field name="resolution" label="Resolución: evidencia de devolución, reposición o ajuste aceptado" required minlength="20" /><button class="rounded-xl border border-amber-300 px-5 py-3 font-semibold">Comprobar y cerrar incidencia</button></form>
                    @else
                        <p class="mt-3">{{ $incident->resolution }}</p>
                    @endif
                @endif
            </div>
        @endforeach
    </x-commerce-panel>
@endif
@if($displayPermissions->contains('manage-commerce'))
    <x-commerce-panel title="Avisos de la operación" class="mt-6">
        <p class="mb-4 text-xs leading-6 text-[#6f7d66]">Cada aviso al productor contiene únicamente su aportación. {{ config('platform.internal_communication') ? 'Los avisos quedan en su cuenta de AgroLazo. Pendiente, disponible y leído son estados distintos.' : 'Un aviso en cola todavía no acredita su entrega al destinatario.' }}</p>
        @if(in_array($order->status, ['confirmed', 'collecting']))
            <form method="POST" action="{{ route('fulfillment.notices.recover', $order) }}" class="mb-5">
                <x-commerce-key />
                <p class="mb-3 text-xs">Para compras anteriores a la actualización, puedes preparar los avisos que aún no existen. No se repiten avisos ya registrados.</p>
                <button class="rounded-xl border border-[#cdd8c7] px-4 py-3 text-sm font-semibold">Preparar avisos de compra faltantes</button>
            </form>
        @endif
        @forelse($notices as $notice)
            @php
                $noticeError = $notice->message?->error_code ?? $notice->error_code;
            @endphp
            <div class="border-b border-[#dfe4da] py-4 text-sm">
                <p>{{ $notice->producer_id ? 'Productor #'.$notice->producer_id : ($notice->user_id === $order->buyer->user_id ? 'Comprador' : 'Coordinación') }} · {{ $notice->message ? (\App\Models\Message::STATUS_LABELS[$notice->message->status] ?? $notice->message->status) : config('commerce.status_labels.'.$notice->status, $notice->status) }}</p>
                <p class="mt-2 whitespace-pre-line">{{ $notice->message?->body ?? $notice->body }}</p>
                @if($notice->status === 'blocked' && ! $notice->message_id)
                    <form method="POST" action="{{ route('fulfillment.notices.retry', $notice) }}" class="mt-3"><x-commerce-key /><button class="rounded-xl border px-4 py-2">Reintentar tras revisar contacto y consentimiento</button></form>
                @endif
                @if($noticeError)
                    <p class="mt-2 text-xs">{{ match ($noticeError) {
                        'contact_or_consent_required' => $order->synthetic ? 'Esta compra es de prueba. Falta una conversación autorizada del productor en el simulador; no se enviará al WhatsApp real.' : 'Falta un contacto actual con autorización para recibir mensajes. Revisa la ficha y Mensajería.',
                        'producer_account_required' => 'Vincula una cuenta activa de productor desde su ficha para entregar el aviso dentro de AgroLazo.', 'internal_channel_only' => 'La comunicación se gestiona dentro de AgroLazo.', 'synthetic_simulator_required' => 'Esta compra es de prueba. Falta una conversación autorizada en el simulador; no se enviará al WhatsApp real.',
                        'inactive_user' => 'La cuenta destinataria está desactivada.',
                        'recipient_access_changed' => 'La persona destinataria ya no tiene acceso a esta operación.',
                        'operational_notice_changed', 'order_changed' => 'El aviso quedó desactualizado por un cambio en la orden. Consulta el aviso más reciente.',
                        'operational_channel_mismatch' => 'El canal del mensaje no corresponde al modo de esta compra.',
                        'schedule_changed' => 'La programación cambió; consulta el aviso más reciente.',
                        default => \App\Models\Message::ERROR_LABELS[$noticeError] ?? 'Requiere revisión: '.$noticeError,
                    } }}</p>
                @endif
            </div>
        @empty
            <p class="text-sm">No hay avisos registrados todavía.</p>
        @endforelse
    </x-commerce-panel>
@endif
