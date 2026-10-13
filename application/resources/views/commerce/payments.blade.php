<x-commerce-panel title="Cobros y saldo del comprador" class="mt-6">
    @if($order->synthetic && config('commerce.show_test_labels'))<p class="mb-5 rounded-xl bg-[#e9eddf] p-4 text-sm font-semibold">PRUEBA SINTÉTICA. No transfieras dinero ni realices pagos reales.</p>@endif
    <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-[#819077]">Ingresos confirmados</dt><dd class="mt-2 text-xl font-semibold">S/ {{ \App\Services\Money::format($financialBalance['received_minor']) }}</dd></div>
        <div><dt class="text-[#819077]">Devoluciones confirmadas</dt><dd class="mt-2 text-xl font-semibold">S/ {{ \App\Services\Money::format($financialBalance['refunded_minor']) }}</dd></div>
        <div><dt class="text-[#819077]">Devoluciones comprometidas</dt><dd class="mt-2 text-xl font-semibold">S/ {{ \App\Services\Money::format($financialBalance['refund_pending_minor']) }}</dd></div>
        <div><dt class="text-[#819077]">{{ $financialBalance['buyer_balance_minor'] < 0 ? 'Excedente a devolver o conciliar' : 'Saldo por cobrar' }}</dt><dd class="mt-2 text-xl font-semibold">S/ {{ \App\Services\Money::format(abs($financialBalance['buyer_balance_minor'])) }}</dd></div>
    </dl>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach($payments as $payment)
            <article class="rounded-xl border border-[#dfe4da] p-5 text-sm">
                <h3 class="font-semibold">Cobro #{{ $payment->id }} · {{ $payment->method->name }} · <x-commerce-status :value="$payment->status" /></h3>
                <p class="mt-3 text-xl font-semibold">S/ {{ \App\Services\Money::format($payment->amount_minor) }}</p>
                <p class="mt-3">Referencia: {{ $payment->operation_reference }} · {{ $payment->occurred_at->setTimezone('America/Lima')->format('d/m/Y H:i') }}</p>
                <p class="mt-2 text-xs">{{ $payment->recipient_snapshot['recipient'] }}</p>
                <div class="mt-4 flex flex-wrap gap-4">@foreach($payment->proofs as $proof)<a class="underline" href="{{ route('payments.proof', $proof) }}">Comprobante privado #{{ $proof->id }}</a>@endforeach</div>
                @if(in_array($payment->status, ['pending','proof_uploaded']))
                    <details class="mt-4"><summary class="cursor-pointer font-semibold">Adjuntar comprobante</summary>
                        <form method="POST" action="{{ route('payments.proofs', $payment) }}" enctype="multipart/form-data" class="mt-4 space-y-3"><x-commerce-key /><input class="w-full text-xs" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required><p class="text-xs">JPG, PNG, WEBP o PDF · Máximo 5 MB · Acceso privado.</p><button class="rounded-xl border border-[#cdd8c7] px-5 py-3">Subir para revisión</button></form>
                    </details>
                @endif
                @if($displayPermissions->contains('confirm-payments'))
                    @if(in_array($payment->status, ['pending','proof_uploaded','under_review']))
                        <details class="mt-5"><summary class="cursor-pointer font-semibold">Revisar cobro</summary><form method="POST" action="{{ route('payments.review', $payment) }}" class="mt-4 space-y-3">
                            <x-commerce-key /><input type="hidden" name="version" value="{{ $payment->version }}">
                            <x-commerce-select name="result" label="Decisión">
                                @if($payment->status === 'under_review')<option value="confirmed">Confirmar ingreso verificado</option>@else<option value="under_review">Iniciar revisión</option>@endif
                                <option value="rejected">Rechazar registro</option>
                            </x-commerce-select>
                            <x-field name="notes" label="Comprobación realizada, evidencia y responsable" required minlength="20" />
                            <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Registrar revisión</button>
                        </form></details>
                    @endif
                    @if(in_array($payment->status, ['confirmed','partially_refunded']) && $order->status !== 'closed')
                        <details class="mt-5"><summary class="cursor-pointer font-semibold">Autorizar devolución parcial o total</summary><form method="POST" action="{{ route('payments.refund', $payment) }}" class="mt-4 space-y-3">
                            <x-commerce-key /><x-field name="amount" label="Monto a devolver (soles)" type="number" min="0.01" step="0.01" required />
                            <x-field name="reason" label="Motivo y verificación del saldo reembolsable" required minlength="20" />
                            <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Autorizar devolución</button>
                        </form></details>
                    @endif
                @endif
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
    @if($order->status !== 'closed')
        <details class="mt-7 border-t border-[#dfe4da] pt-5"><summary class="cursor-pointer font-semibold">Registrar pago o depósito</summary>
            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                <form method="POST" action="{{ route('payments.declare', $order) }}" class="space-y-4">
                    <x-commerce-key />
                    <x-commerce-select name="method_id" label="Medio utilizado" required>@foreach($paymentMethods as $method)<option value="{{ $method->id }}" @selected($method->code === $order->payment_method)>{{ $method->name }}</option>@endforeach</x-commerce-select>
                    <x-field name="amount" label="Monto (soles)" type="number" min="0.01" step="0.01" required />
                    <x-field name="operation_reference" label="Número de operación o constancia presencial" required minlength="3" maxlength="100" />
                    <x-field name="occurred_at" label="Fecha y hora del pago (Perú)" type="datetime-local" required />
                    <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Registrar pendiente de revisión</button>
                </form>
                <div class="space-y-4">
                    @foreach($paymentMethods as $method)
                        @php($instructions = app(\App\Services\ManualPaymentGateway::class)->instructions($method, $order->synthetic))
                        <div class="rounded-xl bg-[#f5f7f1] p-4 text-sm"><h3 class="font-semibold">{{ $method->name }}</h3><p class="mt-2">{{ $instructions['recipient'] }}</p><p class="mt-2">{{ $instructions['instructions'] }}</p>@if(! $method->live_ready && ! $order->synthetic)<p class="mt-2 font-semibold">Receptor sin habilitar.</p>@endif</div>
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

