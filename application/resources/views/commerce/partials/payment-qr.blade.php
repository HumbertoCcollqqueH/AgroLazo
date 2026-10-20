@if(!$order->synthetic && $method->active && $method->live_ready && $method->hasCurrentQr())
    <div class="rounded-2xl border border-[#dfe4da] bg-white p-5 text-center">
        <p class="font-semibold">QR {{ $method->name }} de AgroLazo</p>
        <img class="mx-auto mt-4 h-auto w-56 max-w-full rounded-xl object-contain" width="224" height="224" loading="lazy" src="{{ route('payments.methods.qr', ['method' => $method, 'version' => $method->version, 'order' => $order->id]) }}" alt="QR de la cuenta receptora {{ $method->name }} de AgroLazo">
        <p class="mt-3 text-xs leading-6">Comprueba el titular y el importe antes de autorizar el pago en tu billetera.</p>
        <a class="mt-3 inline-flex rounded-xl border border-[#cdd8c7] px-4 py-3 text-sm font-semibold" href="{{ route('payments.methods.qr', ['method' => $method, 'version' => $method->version, 'order' => $order->id, 'download' => 1]) }}">Guardar QR</a>
    </div>
@endif
