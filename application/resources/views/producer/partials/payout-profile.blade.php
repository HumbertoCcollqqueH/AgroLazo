@if($producer)
    @php($payoutProfile = app(\App\Services\ProducerPayoutProfiles::class)->current(auth()->user(), $producer))
    <section class="pp-card mt-6" id="datos-de-cobro">
        <p class="pp-kicker">MI CUENTA DE COBRO</p>
        <h2 class="mt-2 text-xl font-semibold">Recibe tus liquidaciones por Yape</h2>
        <p class="mt-3 text-sm leading-6">Guarda el titular y el celular de tu Yape. Puedes adjuntar tu QR para que AgroLazo prepare el pago de tus ventas. Solo tú y el equipo autorizado de pagos pueden ver estos datos.</p>
        <form method="POST" action="{{ route('producer.payout-profile.save') }}" enctype="multipart/form-data" class="pp-form mt-5">
            @csrf
            <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="current_version" value="{{ $payoutProfile?->version ?? 0 }}">
            <div class="pp-two">
                <x-field name="holder_name" label="Titular que aparece en Yape" :value="$payoutProfile?->holder_name ?? $producer->full_name" required minlength="3" maxlength="150" autocomplete="name" />
                <x-field name="yape_phone" label="Celular de Yape" :value="$payoutProfile?->yape_phone" required type="tel" inputmode="numeric" pattern="9[0-9]{8}" maxlength="9" placeholder="9 dígitos" autocomplete="tel-national" />
            </div>
            @if($payoutProfile?->qr_path)
                <div class="rounded-2xl border border-[#dfe4da] p-4">
                    <p class="text-sm font-semibold">Tu QR guardado</p>
                    <img src="{{ route('producer.payout-profile.qr', $payoutProfile) }}" alt="QR de tu cuenta Yape" width="220" height="220" class="mt-3 max-h-56 w-56 max-w-full rounded-xl object-contain" loading="lazy">
                    <label class="pp-check mt-3"><input type="checkbox" name="remove_qr" value="1"> Retirar este QR de mi ficha actual</label>
                </div>
            @endif
            <label>QR de Yape <span class="text-sm font-normal">(opcional)</span><input type="file" name="qr" accept="image/jpeg,image/png,image/webp" class="mt-2 block w-full rounded-xl border border-[#cdd8c7] p-3"><span class="mt-2 block text-xs font-normal leading-5">JPG, PNG o WebP, hasta 2 MB. Puedes elegirlo de tu galería. Si cambias el titular o el celular, vuelve a adjuntar el QR correspondiente.</span></label>
            <label class="pp-check"><input type="checkbox" name="account_confirmed" value="1" required> Confirmo que el titular, celular y QR corresponden a mi cuenta para recibir pagos.</label>
            <p class="text-xs leading-5 text-[#6f7d66]">Guardar un QR no confirma una transferencia. Las liquidaciones y los pagos realizados aparecen en Mis ventas. Los cambios conservan el historial de los desembolsos anteriores.</p>
            <button class="pp-button">Guardar mi cuenta de cobro</button>
        </form>
    </section>
@endif
