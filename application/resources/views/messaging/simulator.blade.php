<x-layout title="Simulador de mensajes">
    <a class="text-sm font-semibold underline" href="{{ route('messaging.index') }}">← Volver a mensajería</a>
    <div class="mt-7 grid items-start gap-8 lg:grid-cols-2">
        <section class="rounded-3xl border border-[#dfe4da] bg-white p-7">
            <p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Laboratorio local</p><h1 class="mt-3 text-3xl font-semibold">Prueba una conversación</h1>
            <p class="mt-4 text-sm leading-6 text-[#6f7d66]">Usa un número y una identidad sintéticos. Los mensajes quedan dentro del sistema y no llegan a teléfonos reales.</p>
            <form method="POST" action="{{ route('messaging.simulate') }}" class="mt-6 space-y-5">
                @csrf
                <input type="hidden" name="external_id" value="{{ old('external_id', (string) \Illuminate\Support\Str::uuid()) }}">
                <x-field name="phone" label="Teléfono de prueba" :value="old('phone', '+51900000003')" required hint="Conserva el mismo número para continuar la conversación." />
                <div><label class="mb-2 block text-sm font-semibold" for="channel">Canal que deseas simular</label><select class="w-full rounded-xl border border-[#cdd8c7] p-3" id="channel" name="channel"><option value="whatsapp">WhatsApp</option><option value="sms" @selected(old('channel') === 'sms')>SMS</option></select></div>
                <div><label class="mb-2 block text-sm font-semibold" for="body">Mensaje del productor</label><textarea id="body" name="body" rows="3" maxlength="1600" required class="w-full rounded-xl border border-[#cdd8c7] p-3">{{ old('body', 'HOLA') }}</textarea></div>
                <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Procesar mensaje de prueba</button>
            </form>
        </section>
        <aside class="rounded-3xl bg-[#254f3b] p-7 text-white"><h2 class="text-xl font-semibold">Un recorrido para empezar</h2><ol class="mt-5 list-inside list-decimal space-y-4 text-sm leading-6 text-[#e0e9da]"><li>Envía HOLA y luego ACEPTO.</li><li>Responde nombre y tipo de productor.</li><li>Completa el DNI sintético mediante el formulario del coordinador y verifica al productor.</li><li>Escribe VENDER o QUINUA 80KG PUNO.</li><li>Completa las preguntas y confirma usando la referencia del resumen.</li><li>Abre Ofertas para revisar los datos y acordar condiciones.</li></ol><p class="mt-6 text-sm leading-6">AYUDA pausa el asistente; BAJA revoca el canal. La simulación de SMS muestra cuántos segmentos ocuparía cada respuesta.</p></aside>
    </div>
</x-layout>

