@can('confirm-payments')
    @if($payout->recipient_snapshot)
        @php($payoutRecipient = $payout->recipient_snapshot)
        <details class="mt-4 rounded-xl border border-[#dfe4da] bg-white p-4">
            <summary class="cursor-pointer font-semibold">Destinatario guardado al autorizar</summary>
            <p class="mt-3">{{ $payoutRecipient['holder_name'] }} · Yape {{ $payoutRecipient['yape_phone'] }}</p>
            <p class="mt-2 text-xs leading-5">Versión {{ $payoutRecipient['version'] }} de la cuenta de cobro. Esta versión se conserva aunque el productor actualice su ficha.</p>
            @if($payoutRecipient['qr_sha256'])<img src="{{ route('producer.payout-profile.qr', $payoutRecipient['profile_id']) }}" alt="QR del destinatario al autorizar el desembolso" width="220" height="220" class="mt-3 max-h-56 w-56 max-w-full rounded-xl object-contain" loading="lazy">@endif
        </details>
    @else
        <p class="mt-3 text-xs leading-5 text-[#6f7d66]">Este desembolso conserva el beneficiario y medio indicados en su autorización; no tenía una cuenta de cobro registrada.</p>
    @endif
@endcan
