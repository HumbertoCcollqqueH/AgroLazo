@can('confirm-payments')
    @php($payoutProfile = app(\App\Services\ProducerPayoutProfiles::class)->current(auth()->user(), $producer))
    <section class="mt-5 rounded-2xl border border-[#dfe4da] bg-white p-5">
        <h3 class="font-semibold">Cuenta de cobro del productor</h3>
        @if($payoutProfile)
            <dl class="mt-3 space-y-2 text-sm"><div><dt class="text-[#6f7d66]">Titular declarado</dt><dd class="font-semibold">{{ $payoutProfile->holder_name }}</dd></div><div><dt class="text-[#6f7d66]">Celular Yape</dt><dd class="font-semibold">{{ $payoutProfile->yape_phone }}</dd></div></dl>
            @if($payoutProfile->qr_path)<details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Ver QR privado</summary><img src="{{ route('producer.payout-profile.qr', $payoutProfile) }}" alt="QR de cobro del productor" width="220" height="220" class="mt-3 max-h-56 w-56 max-w-full rounded-xl object-contain" loading="lazy"></details>@endif
            <p class="mt-3 text-xs leading-5 text-[#6f7d66]">Versión {{ $payoutProfile->version }} · {{ $payoutProfile->created_at->timezone('America/Lima')->format('d/m/Y H:i') }}. Datos declarados por el productor; comprueba el destinatario antes de transferir. Autorizar un desembolso conserva esta versión y no acredita su pago.</p>
        @else
            <p class="mt-3 text-sm leading-6">El productor aún no guardó su cuenta de cobro. Puede registrarla en Mi ficha desde la web o la app.</p>
        @endif
    </section>
@endcan
