@php($paymentSummary = \App\Http\Controllers\PaymentExperienceController::summary($order, $financialBalance))
<section class="rounded-2xl border border-[#cdd8c7] bg-[#f5f7f1] p-5 sm:p-6" data-payment-live="{{ route('payments.status', $order) }}" aria-label="Resumen de pagos">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-lg font-semibold" data-payment-headline>{{ $paymentSummary['headline'] }}</h3>
        <span class="text-xs text-[#53634f]" data-payment-connection>Actualización automática</span>
    </div>
    <p class="mt-2 max-w-3xl text-sm leading-6" data-payment-hint aria-live="polite">{{ $paymentSummary['hint'] }}</p>
    <dl class="mt-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-4"><dt class="text-xs text-[#53634f]">Total de la orden</dt><dd class="mt-2 text-xl font-semibold">S/ <span data-payment-total>{{ $paymentSummary['total'] }}</span></dd></div>
        <div class="rounded-xl bg-white p-4"><dt class="text-xs text-[#53634f]">Ingreso neto confirmado</dt><dd class="mt-2 text-xl font-semibold">S/ <span data-payment-received>{{ $paymentSummary['received'] }}</span></dd></div>
        <div class="rounded-xl bg-[#22583e] p-4 text-white"><dt class="text-xs">Saldo de la compra</dt><dd class="mt-2 text-xl font-semibold">S/ <span data-payment-balance>{{ $paymentSummary['balance'] }}</span></dd></div>
    </dl>
    <p class="mt-4 text-xs leading-6" data-payment-pending @if(!$paymentSummary['pending_count']) hidden @endif>Por verificar: S/ <span data-payment-pending-amount>{{ $paymentSummary['pending'] }}</span>. Se descontará del saldo al confirmar el ingreso.</p>
</section>
