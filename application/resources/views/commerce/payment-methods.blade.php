<x-layout title="Medios de pago">
    <a class="text-sm underline" href="{{ route('payments.inbox') }}">← Cobros por revisar</a><h1 class="mb-5 mt-5 text-3xl font-semibold">Cuenta receptora y QR</h1>
    <p class="mb-6 max-w-3xl text-sm leading-7">Los compradores pagan a AgroLazo. Configura la cuenta y su titular; para Yape o Plin puedes añadir el QR correspondiente. Después, cada abono requiere una sola comprobación del ingreso en la bandeja de cobros.</p>
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach($methods as $method)
            <x-commerce-panel :title="$method->name">
                <form method="POST" action="{{ route('payments.methods.configure', $method) }}" class="space-y-4">
                    <x-commerce-key /><input type="hidden" name="version" value="{{ $method->version }}">
                    <x-field name="recipient" label="Receptor y cuenta o número verificados" :value="$method->recipient" required minlength="5" />
                    <label class="block text-sm font-semibold">Instrucciones y evidencia de verificación<textarea name="instructions" class="mt-2 w-full rounded-xl border border-[#cdd8c7] p-4 font-normal" required minlength="20" rows="4">{{ $method->instructions }}</textarea></label>
                    <x-commerce-select name="active" label="Disponibilidad"><option value="1" @selected($method->active)>Activo</option><option value="0" @selected(! $method->active)>Desactivado</option></x-commerce-select>
                    <x-commerce-select name="live_ready" label="Receptor comercial"><option value="0" @selected(! $method->live_ready)>Pendiente de verificar</option><option value="1" @selected($method->live_ready)>Verificado para operación comercial</option></x-commerce-select>
                    <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Guardar versión {{ $method->version + 1 }}</button>
                </form>
                @if(in_array($method->code, ['yape', 'plin']))
                    <div class="mt-6 border-t border-[#dfe4da] pt-5">
                        <h3 class="font-semibold">QR de {{ $method->name }}</h3>
                        @if($method->hasCurrentQr())
                            <img class="mx-auto my-4 h-48 w-48 object-contain" width="192" height="192" src="{{ route('payments.methods.qr', ['method'=>$method, 'version'=>$method->version]) }}" alt="QR actual de {{ $method->name }}">
                            <p class="text-xs leading-6">Verificado para los datos actuales. Si cambias la configuración, vuelve a subir y verificar el QR.</p>
                        @elseif($method->qr_path)
                            <p class="mt-3 rounded-xl bg-amber-50 p-4 text-sm">La configuración cambió. El QR anterior ya no se muestra a los compradores; vuelve a verificarlo para la cuenta actual.</p>
                        @else
                            <p class="mt-3 text-sm leading-6">Añade el QR real de la cuenta de AgroLazo para facilitar el pago desde otro dispositivo o la galería.</p>
                        @endif
                        @if($method->live_ready)
                            <form method="POST" action="{{ route('payments.methods.qr.store',$method) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                                <x-commerce-key /><input type="hidden" name="version" value="{{ $method->version }}">
                                <label class="block text-sm font-semibold">Imagen del QR<input class="mt-2 w-full text-sm" name="qr" type="file" accept="image/jpeg,image/png,image/webp" required></label>
                                <p class="text-xs">JPG, PNG o WEBP · Máximo 2 MB.</p>
                                <label class="flex items-start gap-3 text-sm leading-6"><input class="mt-1 h-5 w-5 shrink-0" type="checkbox" name="recipient_verified" value="1" required><span>Comprobé que este QR corresponde al titular y la cuenta configurados arriba.</span></label>
                                <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Guardar QR verificado</button>
                            </form>
                        @else
                            <p class="mt-4 rounded-xl bg-[#f5f7f1] p-4 text-sm">Primero guarda la cuenta receptora como verificada para habilitar la carga del QR.</p>
                        @endif
                    </div>
                @endif
            </x-commerce-panel>
        @endforeach
    </div>
</x-layout>

