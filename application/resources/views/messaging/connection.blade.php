<x-layout title="Conexión de WhatsApp">
    <div class="mb-8"><p class="text-sm font-semibold text-[#658254]">ADMINISTRACIÓN</p><h1 class="mt-2 text-3xl font-bold">Conectar WhatsApp</h1><p class="mt-3 max-w-3xl text-[#687761]">Conecta el número receptor y habilita las respuestas para tus productores. Registrar un número no envía mensajes: el productor debe escribir primero.</p></div>
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border border-[#dfe4da] bg-white p-6" data-whatsapp-connection data-integration="{{ $connection?->integration }}" data-status-url="{{ route('whatsapp.status') }}">
            <h2 class="text-xl font-bold">1. Vincular el número receptor</h2>
            <form method="POST" action="{{ route('whatsapp.prepare') }}" class="mt-5 space-y-4">@csrf
                <label class="block font-semibold" for="integration">Tipo de conexión</label>
                <select id="integration" name="integration" class="w-full rounded-lg border border-[#d5dfce] p-3">
                    <option value="WHATSAPP-BUSINESS" @selected(($connection?->integration ?? 'WHATSAPP-BUSINESS') === 'WHATSAPP-BUSINESS')>API oficial de Meta mediante Evolution</option>
                    <option value="WHATSAPP-BAILEYS" @selected($connection?->isQr())>Vincular con QR mediante Evolution / Baileys</option>
                </select>
                <p class="text-sm text-[#687761]">El QR vincula WhatsApp como un dispositivo y usa Baileys, una conexión no oficial. La API oficial de Meta utiliza credenciales de empresa y no se conecta escaneando este QR. Una conexión existente conserva su tipo.</p>
                <button type="submit" class="rounded-lg bg-[#22583e] px-5 py-3 font-semibold text-white">{{ $connection ? 'Revisar conexión / renovar QR' : 'Preparar conexión' }}</button>
            </form>
            <p class="mt-5 font-semibold" data-connection-state role="status" aria-live="polite">Consultando estado…</p>
            <img data-connection-qr hidden alt="Código QR para vincular el número receptor de WhatsApp" width="300" height="300" class="mx-auto mt-4 max-w-full">
            <p class="mt-3 text-sm text-[#687761]" data-connection-hint>Abre WhatsApp en tu teléfono → Dispositivos vinculados → Vincular un dispositivo. Escanea el QR cuando aparezca aquí.</p>
            <button type="button" data-connection-refresh class="mt-3 text-sm font-semibold underline">Actualizar estado</button>
        </section>
        <section class="rounded-2xl border border-[#dfe4da] bg-white p-6">
            <h2 class="text-xl font-bold">2. Activar las respuestas</h2>
            @if($connection && ! $connection->automation_enabled)
                <div role="status" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"><strong>Las respuestas automáticas están pausadas.</strong><p class="mt-1">Vincular el número no activa el asistente. Revisa el aviso y pulsa «Activar respuestas» para atender a los productores que escriban.</p></div>
            @endif
            <p class="mt-3 text-sm text-[#687761]">Primero registra el teléfono del productor en <a class="underline" href="{{ route('producers.index') }}">Productores</a>. Los números desconocidos no reciben respuesta. Los suspendidos, rechazados y archivados tampoco. El primer mensaje solicita autorización; después puede escribir VENDER, ESTADO, AYUDA o BAJA.</p>
            @if($connection)
                <form method="POST" action="{{ route('whatsapp.automation') }}" class="mt-5 space-y-4">@csrf
                    <label for="notice_text" class="block font-semibold">Aviso que recibirá el productor</label>
                    <textarea id="notice_text" name="notice_text" rows="5" maxlength="1600" class="w-full rounded-lg border border-[#d5dfce] p-3">{{ old('notice_text', $connection->notice_text) }}</textarea>
                    <label class="flex gap-3 text-sm"><input type="checkbox" name="notice_approved" value="1" @checked(old('notice_approved', $connection->notice_approved)) aria-describedby="notice-approval-help">He revisado el aviso y representa el uso de los datos en esta operación.</label>
                    <p id="notice-approval-help" class="text-sm text-[#687761]">Marca esta casilla antes de activar las respuestas. Puedes desactivarlas sin marcarla.</p>
                    @error('notice_approved')<p role="alert" class="text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
                    <p class="text-sm font-semibold">Respuestas: {{ $connection->automation_enabled ? 'activadas' : 'desactivadas' }}</p>
                    <button name="enabled" value="1" class="rounded-lg bg-[#22583e] px-4 py-3 font-semibold text-white">Activar respuestas</button>
                    <button name="enabled" value="0" class="rounded-lg border border-[#d5dfce] px-4 py-3 font-semibold">Desactivar respuestas</button>
                </form>
            @else
                <p class="mt-5 rounded-lg bg-[#f3f6ee] p-4 text-sm">Prepara la conexión para configurar el aviso y activar las respuestas.</p>
            @endif
            <p class="mt-5 text-sm text-[#687761]">Tras conectar, registra un teléfono de prueba, activa las respuestas y envía HOLA desde ese teléfono. Revisa el resultado en <a class="underline" href="{{ route('messaging.index') }}">Mensajería</a>. Desactivar las respuestas impide nuevos envíos; no deshace mensajes ya enviados.</p>
        </section>
    </div>
</x-layout>
