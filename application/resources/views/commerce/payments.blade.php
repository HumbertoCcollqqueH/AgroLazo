<x-commerce-panel title="Cobros y saldo del comprador" class="mt-6">
    @php
        $payments->loadMissing('reviews.actor');
        $pendingPaymentAmount = (int) $payments->whereIn('status', ['pending', 'proof_uploaded', 'under_review'])->sum('amount_minor');
        $hasRejectedPayments = $payments->contains('status', 'rejected');
        $suggestedPaymentAmount = max(0, $financialBalance['buyer_balance_minor'] - $pendingPaymentAmount);
    @endphp
    @if($order->synthetic && config('commerce.show_test_labels'))<p class="mb-5 rounded-xl bg-[#e9eddf] p-4 text-sm font-semibold">PRUEBA SINTÉTICA. No transfieras dinero ni realices pagos reales.</p>@endif
    @include('commerce.partials.payment-live-summary')
    <ol class="mt-5 grid gap-3 text-sm sm:grid-cols-3">
        <li class="rounded-xl bg-[#f5f7f1] p-4"><strong>1. Paga a AgroLazo</strong><p class="mt-2 text-xs leading-6">Usa el receptor indicado para esta compra. El pago reúne las aportaciones de todo el lote.</p></li>
        <li class="rounded-xl bg-[#f5f7f1] p-4"><strong>2. Envíalo una vez</strong><p class="mt-2 text-xs leading-6">Registra la operación y adjunta la captura en el mismo formulario.</p></li>
        <li class="rounded-xl bg-[#f5f7f1] p-4"><strong>3. Recibe la confirmación</strong><p class="mt-2 text-xs leading-6">El equipo verifica el abono una sola vez. Verás el saldo y el aviso dentro de AgroLazo.</p></li>
    </ol>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach($payments as $payment)
            <article id="payment-{{ $payment->id }}" class="rounded-xl border border-[#dfe4da] p-5 text-sm">
                <h3 class="font-semibold">Pago #{{ $payment->id }} · {{ $payment->method->name }} · <span data-payment-state="{{ $payment->id }}"><x-commerce-status :value="$payment->status" /></span></h3>
                <p class="mt-3 text-xl font-semibold">S/ {{ \App\Services\Money::format($payment->amount_minor) }}</p>
                <p class="mt-3">Referencia: {{ $payment->operation_reference }} · {{ $payment->occurred_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}</p>
                <p class="mt-2 text-xs">{{ $payment->recipient_snapshot['recipient'] }}</p>
                <div class="mt-4 flex flex-wrap gap-4">@foreach($payment->proofs as $proof)<a class="underline" href="{{ route('payments.proof', $proof) }}">Comprobante privado #{{ $proof->id }}</a>@endforeach</div>
                @if($payment->status === 'confirmed')
                    <p class="mt-3 font-semibold text-[#22583e]">Abono confirmado {{ $payment->confirmed_at?->setTimezone('America/Lima')->format('d/m/Y H:i') }}. Este importe ya se descontó del saldo.</p>
                @elseif($payment->status === 'rejected')
                    <p class="mt-3 text-amber-900">El registro necesita corrección. {{ $payment->reviews->where('action', 'rejected')->last()?->notes }}</p>
                    <p class="mt-3 text-sm leading-6">No vuelvas a transferir ese monto. Si esta es la operación correcta, puedes pedir que revisemos el mismo pago y completar su evidencia.</p>
                    @if($order->status !== 'closed')
                        <details class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4" data-payment-reopen="{{ $payment->id }}">
                            <summary class="cursor-pointer font-semibold">Solicitar nueva revisión de este pago</summary>
                            <form method="POST" action="{{ route('payments.reopen', $payment) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                                <x-commerce-key /><input type="hidden" name="version" value="{{ $payment->version }}">
                                <p class="text-xs leading-6">Conservamos S/ {{ \App\Services\Money::format($payment->amount_minor) }}, {{ $payment->method->name }}, la fecha y la referencia {{ $payment->operation_reference }}. Los comprobantes que ya enviaste siguen guardados.</p>
                                <label for="payment-reopen-notes-{{ $payment->id }}" class="block text-sm font-semibold">¿Qué información debemos revisar?
                                    <textarea id="payment-reopen-notes-{{ $payment->id }}" name="notes" required minlength="10" maxlength="2000" rows="3" class="mt-2 w-full rounded-xl border border-[#cdd8c7] bg-white p-3 font-normal" placeholder="Por ejemplo: adjunto una captura más clara del movimiento."></textarea>
                                </label>
                                <label for="payment-reopen-proof-{{ $payment->id }}" class="block text-sm font-semibold">Comprobante adicional <span class="font-normal">(opcional)</span>
                                    <input id="payment-reopen-proof-{{ $payment->id }}" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-2 w-full text-sm">
                                </label>
                                <p class="text-xs leading-6">JPG, PNG, WEBP o PDF · Hasta 5 MB. Esta solicitud vuelve a poner el pago en revisión y no acredita fondos.</p>
                                <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Enviar aclaración del mismo pago</button>
                            </form>
                        </details>
                    @endif
                    <p class="mt-3 text-xs leading-6">Si ingresaste mal el monto, el medio, la fecha o el número de operación, <a class="font-semibold underline" href="{{ route('coordination.index', ['q' => $order->code]) }}">consulta con el equipo por la orden {{ $order->code }}</a> para corregirlo con historial.</p>
                @endif
                @if(in_array($payment->status, ['pending','proof_uploaded']))
                    <details class="mt-4" data-payment-proof="{{ $payment->id }}"><summary class="cursor-pointer font-semibold">Adjuntar comprobante</summary>
                        <form method="POST" action="{{ route('payments.proofs', $payment) }}" enctype="multipart/form-data" class="mt-4 space-y-3"><x-commerce-key /><input class="w-full text-xs" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required><p class="text-xs">JPG, PNG, WEBP o PDF · Máximo 5 MB · Acceso privado.</p><button class="rounded-xl border border-[#cdd8c7] px-5 py-3">Subir para revisión</button></form>
                    </details>
                @endif
                @if($displayPermissions->contains('confirm-payments'))
                    @if(in_array($payment->status, ['pending','proof_uploaded','under_review']))
                        @include('commerce.partials.payment-review', ['payment' => $payment])
                    @endif
                    @if(in_array($payment->status, ['confirmed','partially_refunded']) && $order->status !== 'closed')
                        <details class="mt-5"><summary class="cursor-pointer font-semibold">Autorizar devolución parcial o total</summary><form method="POST" action="{{ route('payments.refund', $payment) }}" class="mt-4 space-y-3">
                            <x-commerce-key /><x-field name="amount" label="Monto a devolver (soles)" type="number" min="0.01" step="0.01" required />
                            <x-field name="reason" label="Motivo y verificación del saldo reembolsable" required minlength="20" />
                            <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Autorizar devolución</button>
                        </form></details>
                    @endif
                @endif
                <details class="mt-5 border-t border-[#dfe4da] pt-4"><summary class="cursor-pointer text-xs font-semibold">Historial de este pago</summary>
                    <ol class="mt-3 space-y-3 text-xs leading-6">
                        @foreach($payment->reviews as $review)
                            <li><strong>{{ ['declared' => 'Pago registrado', 'proof_uploaded' => 'Comprobante recibido', 'resubmitted' => 'Nueva revisión solicitada', 'under_review' => 'En revisión', 'confirmed' => 'Abono confirmado', 'rejected' => 'Registro rechazado'][$review->action] ?? config('commerce.event_labels.'.$review->action, 'Actualización registrada') }}</strong> · {{ $review->occurred_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}
                                @if($displayPermissions->contains('confirm-payments'))<p>{{ $review->actor?->name }} · {{ $review->notes }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                </details>
                @foreach($payment->refunds as $refund)
                    <div class="mt-5 rounded-xl bg-[#f5f7f1] p-4"><p>Devolución #{{ $refund->id }} · S/ {{ \App\Services\Money::format($refund->amount_minor) }} · <x-commerce-status :value="$refund->status" /></p><p class="mt-2">{{ $refund->reason }}</p>
                        @if($displayPermissions->contains('confirm-payments'))
                            @if(in_array($refund->status, ['authorized','uncertain']))
                                <details class="mt-3"><summary class="cursor-pointer font-semibold">Registrar resultado de devolución</summary><x-commerce-outcome :action="route('payments.refund.outcome', $refund)" :version="$refund->version" /></details>
                            @endif
                        @endif
                    </div>
                @endforeach
            </article>
        @endforeach
    </div>
    @if(! in_array($order->status, ['closed', 'cancelled']) && $paymentMethods->isNotEmpty() && ($financialBalance['buyer_balance_minor'] > 0 || $displayPermissions->contains('confirm-payments')))
        <details data-payment-entry data-allow-overpayment="{{ $displayPermissions->contains('confirm-payments') ? 'true' : 'false' }}" class="mt-7 border-t border-[#dfe4da] pt-5" @if($suggestedPaymentAmount > 0 && ! $hasRejectedPayments) open @endif><summary class="cursor-pointer font-semibold">{{ $pendingPaymentAmount > 0 || $hasRejectedPayments ? 'Ya realicé otro pago: registrar operación adicional' : 'Registrar pago y comprobante' }}</summary>
            @if($hasRejectedPayments)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm leading-6">Un registro rechazado no significa que debas volver a pagar. Para la misma transferencia, usa «Solicitar nueva revisión» en ese pago. Este formulario es para una operación diferente que ya realizaste.</p>@endif
            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                <form method="POST" action="{{ route('payments.declare', $order) }}" enctype="multipart/form-data" class="space-y-4" data-payment-declaration>
                    <x-commerce-key />
                    <x-commerce-select name="method_id" label="Medio utilizado" required>@foreach($paymentMethods as $method)<option value="{{ $method->id }}" @selected($method->code === $order->payment_method)>{{ $method->name }}</option>@endforeach</x-commerce-select>
                    <x-field name="amount" label="Monto que pagaste (soles)" type="number" min="0.01" step="0.01" :value="$suggestedPaymentAmount > 0 ? \App\Services\Money::format($suggestedPaymentAmount) : null" hint="Confirma el monto real transferido. Se descuentan de la sugerencia los pagos ya enviados a revisión." required />
                    <x-field name="operation_reference" label="Número de operación o constancia presencial" required minlength="3" maxlength="100" />
                    <x-field name="occurred_at" label="Fecha y hora del pago (Perú)" type="datetime-local" :value="now('America/Lima')->format('Y-m-d\TH:i')" required />
                    <label for="payment-proof-{{ $order->id }}" class="block text-sm font-semibold">Comprobante o captura <span class="font-normal">(opcional)</span>
                        <input id="payment-proof-{{ $order->id }}" class="mt-2 w-full text-sm" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf">
                    </label>
                    <p class="text-xs leading-6">JPG, PNG, WEBP o PDF · Hasta 5 MB. El comprobante es privado y puedes adjuntarlo después. Confirmamos el pago al comprobar el dinero recibido.</p>
                    <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Enviar pago para verificar</button>
                </form>
                <div class="space-y-4">
                    @foreach($paymentMethods as $method)
                        @php($instructions = app(\App\Services\ManualPaymentGateway::class)->instructions($method, $order->synthetic))
                        <div class="rounded-xl bg-[#f5f7f1] p-4 text-sm"><h3 class="font-semibold">{{ $method->name }}</h3><p class="mt-2">{{ $instructions['recipient'] }}</p><p class="mt-2">{{ $instructions['instructions'] }}</p>@if(! $method->live_ready && ! $order->synthetic)<p class="mt-2 font-semibold">Receptor sin habilitar.</p>@endif</div>
                        @include('commerce.partials.payment-qr', ['method' => $method, 'order' => $order])
                    @endforeach
                </div>
            </div>
        </details>
    @endif
</x-commerce-panel>
@if($displayPermissions->contains('manage-commerce'))
    <x-commerce-panel title="Liquidaciones de productores" class="mt-6">
        <p class="mb-5 text-sm leading-7">Las liquidaciones usan el peso aceptado y el acuerdo conservado de cada aportación. Los costos se muestran como total pactado cuando el acuerdo original no los desglosa. Cobrar al comprador no registra un desembolso al productor.</p>
        @if($displayPermissions->contains('confirm-payments'))
            @if($order->status !== 'closed')
                <form method="POST" action="{{ route('payments.settlements', $order) }}" class="mb-6 flex flex-wrap items-end gap-4"><x-commerce-key />
                    <x-commerce-select name="producer_id" label="Productor">
                        @foreach($order->items->flatMap(fn ($item) => $item->allocations)->map(fn ($allocation) => $allocation->portion->offer->producer)->unique('id') as $producer)
                            <option value="{{ $producer->id }}">{{ $producer->full_name }}</option>
                        @endforeach
                    </x-commerce-select>
                    <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Calcular revisión de liquidación</button>
                </form>
            @endif
        @endif
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach($settlements as $settlement)
                @php($producerBalance = app(\App\Services\SettlementService::class)->balance($settlement))
                <article class="rounded-xl border border-[#dfe4da] p-5 text-sm">
                    <h3 class="font-semibold">{{ $settlement->producer->full_name }} · Revisión {{ $settlement->version }} · <x-commerce-status :value="$settlement->status" /></h3>
                    @if($displayPermissions->contains('confirm-payments'))
                        @include('producers.partials.payout-profile', ['producer' => $settlement->producer])
                    @endif
                    <p class="mt-3">{{ $settlement->quantity }} {{ $order->quote->baseUnit->code }} aceptados</p>
                    <dl class="mt-4 space-y-2"><div class="flex justify-between"><dt>Bruto</dt><dd>S/ {{ \App\Services\Money::format($settlement->gross_minor) }}</dd></div><div class="flex justify-between"><dt>Costos acordados</dt><dd>S/ {{ \App\Services\Money::format($settlement->agreed_cost_minor) }}</dd></div><div class="flex justify-between font-semibold"><dt>Neto</dt><dd>S/ {{ \App\Services\Money::format($settlement->net_minor) }}</dd></div></dl>
                    <p class="mt-4 text-xs">Revisión vigente: {{ $producerBalance['current_version'] }}. Pagado entre todas las revisiones: S/ {{ \App\Services\Money::format($producerBalance['paid_minor']) }}; autorizado o incierto: S/ {{ \App\Services\Money::format($producerBalance['pending_minor']) }}.</p>
                    @if($producerBalance['current_version'] === $settlement->version)
                        <p class="mt-3 font-semibold">Saldo por pagar: S/ {{ \App\Services\Money::format($producerBalance['remaining_minor']) }}</p>
                        @if($displayPermissions->contains('confirm-payments'))
                            @if($settlement->status === 'draft')
                                <form method="POST" action="{{ route('payments.settlements.approve', $settlement) }}" class="mt-4 space-y-3"><x-commerce-key /><x-field name="notes" label="Revisión del peso, costos y acuerdo del productor" required minlength="20" /><button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Aprobar liquidación</button></form>
                            @elseif($producerBalance['available_to_authorize_minor'] > 0 && $order->status !== 'closed')
                                <details class="mt-4"><summary class="cursor-pointer font-semibold">Autorizar desembolso</summary><form method="POST" action="{{ route('payments.payout', $settlement) }}" class="mt-4 space-y-3"><x-commerce-key /><x-field name="amount" label="Monto al productor (soles)" type="number" min="0.01" step="0.01" required /><x-field name="reason" label="Beneficiario, medio acordado y comprobación de autorización" required minlength="20" /><button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Autorizar monto</button></form></details>
                            @endif
                        @endif
                    @endif
                    <details class="mt-4"><summary class="cursor-pointer text-xs font-semibold">Origen del cálculo</summary>@foreach($settlement->items as $entry)<p class="mt-3 text-xs">Recepción #{{ $entry->collection_receipt_id }} · Acuerdo #{{ $entry->agreement_id }} · {{ $entry->quantity }} {{ $order->quote->baseUnit->code }} · Neto S/ {{ \App\Services\Money::format($entry->net_minor) }}</p><p class="mt-2 text-xs">{{ $entry->terms_snapshot }}</p>@endforeach</details>
                </article>
            @endforeach
        </div>
        @foreach($payouts as $payout)
            <div class="mt-5 rounded-xl bg-[#f5f7f1] p-5 text-sm"><p class="font-semibold">Desembolso #{{ $payout->id }} · {{ $payout->producer->full_name }} · S/ {{ \App\Services\Money::format($payout->amount_minor) }} · <x-commerce-status :value="$payout->status" /></p><p class="mt-2">{{ $payout->reason }}</p>
                @if($displayPermissions->contains('confirm-payments'))
                    @include('commerce.partials.payout-recipient', ['payout' => $payout])
                    @if(in_array($payout->status, ['authorized','uncertain']))
                        <details class="mt-4"><summary class="cursor-pointer font-semibold">Comprobar y registrar resultado</summary><x-commerce-outcome :action="route('payments.payout.outcome', $payout)" :version="$payout->version" /></details>
                    @endif
                @endif
            </div>
        @endforeach
    </x-commerce-panel>
@endif
@if($displayPermissions->contains('confirm-payments'))
    @if($order->status !== 'closed')
        <x-commerce-panel title="Control financiero de la orden" class="mt-6">
            @if($order->deferred_payment_approved_by)<p class="mb-5 text-sm">Pago diferido aprobado hasta S/ {{ \App\Services\Money::format($order->deferred_payment_limit_minor) }}; vence {{ $order->deferred_payment_due_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}.</p>@endif
            <div class="grid gap-6 lg:grid-cols-2">
                <details><summary class="cursor-pointer font-semibold">Autorizar entrega con pago diferido</summary><form method="POST" action="{{ route('payments.defer', $order) }}" class="mt-4 space-y-3"><x-commerce-key /><input type="hidden" name="version" value="{{ $order->version }}"><x-field name="amount" label="Límite pendiente autorizado (soles)" type="number" min="0.01" step="0.01" required /><x-field name="due_at" label="Vencimiento del pago (Perú)" type="datetime-local" required /><x-field name="reason" label="Condiciones aceptadas y autorización administrativa" required minlength="20" /><button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Registrar autorización</button></form></details>
                <details><summary class="cursor-pointer font-semibold">Conciliar y cerrar orden</summary><form method="POST" action="{{ route('payments.close', $order) }}" class="mt-4 space-y-3"><x-commerce-key /><input type="hidden" name="version" value="{{ $order->version }}"><x-field name="notes" label="Constancia de conciliación física y financiera" required minlength="20" /><button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Comprobar saldos y cerrar</button></form><p class="mt-3 text-xs leading-6">El cierre exige entrega o cancelación resuelta, saldo comprador cero, devoluciones conciliadas y obligaciones de productores pagadas.</p></details>
            </div>
        </x-commerce-panel>
    @endif
@endif

