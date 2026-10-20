<x-layout title="Cobros por revisar">
    <div class="mb-7 flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-widest text-[#53634f]">Control de ingresos</p><h1 class="mt-2 text-3xl font-semibold">Cobros por revisar</h1><p class="mt-3 max-w-2xl text-sm leading-7">Consulta la evidencia y confirma el abono en una sola revisión. El saldo y el aviso al comprador se actualizan al guardar.</p></div>
        <a class="rounded-xl border border-[#cdd8c7] bg-white px-5 py-3 text-sm font-semibold" href="{{ route('payments.methods') }}">Cuenta receptora y QR →</a>
    </div>
    <div class="mb-6 grid gap-4 sm:grid-cols-2"><div class="rounded-2xl bg-[#14243b] p-6 text-white"><p class="text-sm">Registros pendientes</p><p class="mt-2 text-3xl font-semibold">{{ $pendingCount }}</p></div><div class="rounded-2xl border border-[#dfe4da] bg-white p-6"><p class="text-sm">Importe declarado por verificar</p><p class="mt-2 text-3xl font-semibold">S/ {{ \App\Services\Money::format($pendingAmount) }}</p><p class="mt-2 text-xs">Todavía no se considera dinero recibido.</p></div></div>
    <form method="GET" class="mb-7 grid items-end gap-4 rounded-2xl border border-[#dfe4da] bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-field name="q" label="Buscar comprador u orden" :value="request('q')" maxlength="100" placeholder="Nombre, correo o código" />
        <x-commerce-select name="status" label="Estado">@foreach(['pending'=>'Por verificar','confirmed'=>'Confirmados','rejected'=>'Requieren corrección','all'=>'Todos'] as $value=>$label)<option value="{{ $value }}" @selected(request('status','pending')===$value)>{{ $label }}</option>@endforeach</x-commerce-select>
        <x-commerce-select name="method" label="Medio de pago"><option value="">Todos los medios</option>@foreach($methods as $method)<option value="{{ $method->id }}" @selected((string)request('method')===(string)$method->id)>{{ $method->name }}</option>@endforeach</x-commerce-select>
        <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Filtrar cobros</button>
    </form>
    <div class="space-y-6">
        @forelse($payments as $payment)
            <article id="payment-{{ $payment->id }}" class="rounded-2xl border border-[#dfe4da] bg-white p-5 sm:p-7">
                <header class="flex flex-wrap justify-between gap-4"><div><h2 class="text-xl font-semibold">{{ $payment->order->buyer->user->name }}</h2><p class="mt-2 text-sm">{{ $payment->order->code }} · {{ $payment->method->name }} · <x-commerce-status :value="$payment->status" /></p></div><div class="text-xl font-semibold">S/ {{ \App\Services\Money::format($payment->amount_minor) }}</div></header>
                <div class="mt-5 grid gap-6 lg:grid-cols-2">
                    <div class="min-w-0"><dl class="space-y-3 rounded-xl bg-[#f5f7f1] p-5 text-sm"><div><dt class="text-xs text-[#53634f]">Número de operación</dt><dd class="mt-1 break-all font-semibold">{{ $payment->operation_reference }}</dd></div><div><dt class="text-xs text-[#53634f]">Fecha del pago</dt><dd class="mt-1">{{ $payment->occurred_at->setTimezone('America/Lima')->format('d/m/Y H:i') }} · Perú</dd></div><div><dt class="text-xs text-[#53634f]">Receptor conservado en el registro</dt><dd class="mt-1 break-words">{{ $payment->recipient_snapshot['recipient'] ?? 'Consulta la orden' }}</dd></div></dl>
                        @forelse($payment->proofs as $proof)
                            <div class="mt-4 rounded-xl border border-[#dfe4da] p-3">
                                @if(in_array($proof->mime, ['image/jpeg','image/png','image/webp'], true))<a href="{{ route('payments.proof.preview',$proof) }}" target="_blank" rel="noopener"><img class="mx-auto max-h-80 max-w-full object-contain" loading="lazy" src="{{ route('payments.proof.preview',$proof) }}" alt="Comprobante adjunto del pago {{ $payment->id }}"></a>@endif
                                <a class="mt-3 inline-block text-sm underline" href="{{ route('payments.proof',$proof) }}">Descargar comprobante #{{ $proof->id }}</a>
                            </div>
                        @empty<p class="mt-4 text-sm">Sin archivo adjunto. Usa el movimiento de la cuenta receptora para contrastar la operación.</p>@endforelse
                        <a class="mt-5 inline-block text-sm font-semibold underline" href="{{ route('commerce.orders.show', $payment->order) }}#payment-{{ $payment->id }}">Ver orden, entregas y saldo →</a>
                    </div>
                    <div>@if(in_array($payment->status, ['pending','proof_uploaded','under_review']))@include('commerce.partials.payment-review', ['payment'=>$payment])@else<p class="rounded-xl bg-[#f5f7f1] p-5 text-sm leading-7">Este registro ya fue revisado. Su decisión y responsable se conservan en el historial de la orden.</p>@endif</div>
                </div>
            </article>
        @empty<div class="rounded-2xl border border-dashed border-[#cdd8c7] bg-white p-10 text-center"><h2 class="text-xl font-semibold">No hay cobros en esta vista</h2><p class="mt-3 text-sm">Los pagos que registren los compradores aparecerán aquí. Puedes cambiar los filtros para consultar el historial.</p></div>@endforelse
    </div>
    <div class="mt-6">{{ $payments->links() }}</div>
</x-layout>
