<div data-payment-review="{{ $payment->id }}" class="mt-5 rounded-xl border border-[#cdd8c7] bg-[#f7faf5] p-4">
    <h4 class="font-semibold text-[#22583e]">Una sola verificación del abono</h4>
    <p class="mt-2 text-xs leading-6">Contrasta el monto, el número de operación, la fecha y el receptor con el movimiento recibido en la cuenta de AgroLazo. La captura es evidencia de apoyo.</p>
    <form method="POST" action="{{ route('payments.review', $payment) }}" class="mt-4 space-y-4" data-payment-resolution="{{ $payment->id }}">
        <x-commerce-key />
        <input type="hidden" name="version" value="{{ $payment->version }}">
        <input type="hidden" name="result" value="confirmed">
        <label class="flex items-start gap-3 text-sm leading-6">
            <input type="checkbox" name="funds_verified" value="1" required class="mt-1 h-5 w-5 shrink-0 accent-[#22583e]">
            <span>Comprobé el ingreso real de <strong>S/ {{ \App\Services\Money::format($payment->amount_minor) }}</strong> en la cuenta receptora y coincide con esta operación.</span>
        </label>
        <label for="payment-confirm-notes-{{ $payment->id }}" class="block text-sm font-semibold">Constancia de la comprobación
            <textarea id="payment-confirm-notes-{{ $payment->id }}" name="notes" required minlength="20" maxlength="3000" rows="2" class="mt-2 w-full rounded-xl border border-[#cdd8c7] bg-white p-3 font-normal" placeholder="Movimiento recibido, fecha, monto y referencia que contrastaste."></textarea>
        </label>
        <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Confirmar abono y actualizar saldo</button>
    </form>
    <details class="mt-4 border-t border-[#dfe4da] pt-4">
        <summary class="cursor-pointer text-sm font-semibold">Hay un error o el abono no corresponde</summary>
        <form method="POST" action="{{ route('payments.review', $payment) }}" class="mt-4 space-y-3" data-payment-resolution="{{ $payment->id }}">
            <x-commerce-key />
            <input type="hidden" name="version" value="{{ $payment->version }}">
            <input type="hidden" name="result" value="rejected">
            <label for="payment-reject-notes-{{ $payment->id }}" class="block text-sm font-semibold">Motivo y datos que debe corregir el comprador
                <textarea id="payment-reject-notes-{{ $payment->id }}" name="notes" required minlength="20" maxlength="3000" rows="2" class="mt-2 w-full rounded-xl border border-[#cdd8c7] bg-white p-3 font-normal"></textarea>
            </label>
            <button class="rounded-xl border border-[#cdd8c7] bg-white px-5 py-3 font-semibold">Rechazar registro y avisar</button>
        </form>
    </details>
</div>
