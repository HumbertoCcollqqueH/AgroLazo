<x-layout :title="'Conversación #'.$conversation->id">
    <a class="text-sm font-semibold underline" href="{{ route('messaging.index') }}">← Volver a mensajería</a>
    <div class="my-7 flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">{{ strtoupper($conversation->channel) }}{{ $conversation->provider === 'fake' ? ' · Simulación local' : '' }}</p><h1 class="mt-3 text-3xl font-semibold">{{ $conversation->producer?->full_name ?? 'Prerregistro #'.$conversation->id }}</h1><p class="mt-3 text-sm text-[#6f7d66]">{{ $conversation->maskedPhone() }} · Consentimiento: {{ ['pending' => 'pendiente', 'granted' => 'autorizado', 'declined' => 'baja registrada'][$conversation->consent] }}</p></div>
        @if($conversation->producer)
            <a class="rounded-xl border border-[#c4d1bb] bg-white px-5 py-3 font-semibold" href="{{ route('producers.show', $conversation->producer) }}">Verificar / ver productor →</a>
        @endif
    </div>
    <div class="grid items-start gap-6 lg:grid-cols-3">
        <section class="rounded-3xl border border-[#dfe4da] bg-white p-6 lg:col-span-2">
            <h2 class="mb-5 text-xl font-semibold">Mensajes</h2>
            <div class="space-y-4">
                @foreach($messages->reverse() as $message)
                    <article class="max-w-[95%] rounded-2xl p-4 {{ $message->direction === 'inbound' ? 'mr-auto bg-[#f1f3ed]' : 'ml-auto bg-[#e5efde]' }}">
                        <p class="text-xs font-bold text-[#6f7d66]">{{ ($message->metadata['internal_note'] ?? false) ? 'Nota interna · no enviada' : ($message->direction === 'inbound' ? 'Productor' : 'AgroLazo') }}</p>
                        <p class="mt-2 whitespace-pre-line break-words text-sm leading-6">{{ $message->body }}</p>
                        <p class="mt-3 text-xs text-[#6f7d66]">{{ $message->created_at->timezone('America/Lima')->format('d/m H:i') }} · {{ $conversation->provider === 'fake' && $message->status === 'accepted' ? 'Respuesta simulada' : (\App\Models\Message::STATUS_LABELS[$message->status] ?? $message->status) }}{{ $message->error_code ? ' · '.(\App\Models\Message::ERROR_LABELS[$message->error_code] ?? 'Revisar respuesta del proveedor') : '' }}</p>
                        @if($message->direction === 'inbound' && $message->received_at)
                            <p class="mt-2 text-xs text-[#6f7d66]">Enviado por el productor: {{ $message->received_at->timezone('America/Lima')->format('d/m H:i:s') }} · Recibido en AgroLazo: {{ $message->created_at->timezone('America/Lima')->format('d/m H:i:s') }}.</p>
                            @if($message->created_at->timestamp - $message->received_at->timestamp >= 60)
                                <p class="mt-2 text-xs text-amber-800">Llegó con {{ (int) floor(($message->created_at->timestamp - $message->received_at->timestamp) / 60) }} minutos de demora antes de entrar al sistema.</p>
                            @endif
                            @if(($message->metadata['ignored_reason'] ?? null) === 'human_assigned')
                                <p class="mt-2 text-xs text-amber-800">Sin respuesta automática: la conversación estaba en atención humana.</p>
                            @endif
                            @if(($message->metadata['ignored_reason'] ?? null) === 'automation_disabled')
                                <p class="mt-2 text-xs font-semibold text-amber-800">Sin respuesta automática: WhatsApp estaba pausado cuando llegó este mensaje.</p>
                            @endif
                        @endif
                        @if($conversation->channel === 'sms' && $message->direction === 'outbound')
                            <p class="mt-2 text-xs text-[#6f7d66]">{{ $sms->estimate($message->body)['segments'] }} segmentos · {{ $sms->estimate($message->body)['encoding'] }} · límite configurado {{ config('messaging.max_sms_segments') }}</p>
                        @endif
                        @if($conversation->provider === 'fake' && $message->direction === 'outbound' && $message->external_id)
                            <form method="POST" action="{{ route('messaging.receipt', $conversation) }}" class="mt-3 flex flex-wrap gap-2">
                                @csrf
                                <input type="hidden" name="message_id" value="{{ $message->id }}">
                                <select name="status" aria-label="Recibo simulado del mensaje {{ $message->id }}" class="rounded border border-[#cdd8c7] bg-white p-2 text-xs"><option value="delivered">Entregado</option><option value="read">Leído</option><option value="failed">Fallido</option></select><button class="text-xs font-semibold underline">Simular recibo</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
            <div class="mt-6">{{ $messages->links() }}</div>
            @if($conversation->provider === 'fake' && app()->environment(['local', 'testing']) && config('messaging.simulator_enabled'))
                <form method="POST" action="{{ route('messaging.simulate') }}" class="mt-6 border-t border-[#e5eadf] pt-5">
                    @csrf
                    <input type="hidden" name="phone" value="{{ $conversation->phone }}"><input type="hidden" name="channel" value="{{ $conversation->channel }}"><input type="hidden" name="external_id" value="{{ old('external_id', (string) \Illuminate\Support\Str::uuid()) }}">
                    <label class="mb-2 block text-sm font-semibold" for="body">Siguiente mensaje del productor de prueba</label><textarea id="body" name="body" required maxlength="1600" rows="2" class="w-full rounded-xl border border-[#cdd8c7] p-3">{{ old('body') }}</textarea><button class="mt-3 rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Procesar mensaje</button>
                </form>
            @endif
        </section>
        <aside class="space-y-6">
            @if($conversation->state === 'WAITING_CONSENT' && $conversation->consent === 'pending')
                <section class="rounded-3xl border border-[#dfe4da] bg-white p-6">
                    <h2 class="text-lg font-semibold">Esperando autorización del productor</h2>
                    <p class="mt-3 text-sm leading-6 text-[#6f7d66]">El productor debe responder ACEPTO por {{ $conversation->channel === 'sms' ? 'SMS' : 'WhatsApp' }} para continuar con sus opciones. Estar registrado o verificado no sustituye esa respuesta.</p>
                </section>
            @endif
            @if($conversation->consent === 'granted')
                <section class="rounded-3xl border border-[#dfe4da] bg-white p-6">
                    <h2 class="text-lg font-semibold">Respuesta del coordinador</h2>
                    <p class="mt-3 text-sm leading-6 text-[#6f7d66]">{{ $conversation->provider === 'fake' ? 'Esta respuesta se simula dentro del sistema.' : 'Se enviará al contacto cuando el canal real esté habilitado y cumpla sus condiciones.' }}</p>
                    <form method="POST" action="{{ route('messaging.reply', $conversation) }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="source_key" value="{{ old('source_key', (string) \Illuminate\Support\Str::uuid()) }}">
                        <label class="block text-sm font-semibold" for="staff-body">Texto de la respuesta</label><textarea id="staff-body" name="body" rows="3" maxlength="1600" class="w-full rounded-xl border border-[#cdd8c7] p-3"></textarea>
                        @if($conversation->channel === 'whatsapp' && config('messaging.approved_templates'))
                            <label class="block text-sm font-semibold" for="template-key">O enviar una plantilla aprobada</label><select name="template_key" id="template-key" class="w-full rounded-xl border border-[#cdd8c7] p-3"><option value="">Usar el texto escrito</option>@foreach(config('messaging.approved_templates') as $key => $template)<option value="{{ $key }}">{{ $template['name'] ?? $key }}</option>@endforeach</select><p class="text-xs leading-5 text-[#6f7d66]">Al seleccionar plantilla se envía únicamente su contenido configurado. Fuera de 24 horas, WhatsApp exige plantilla.</p>
                        @endif
                        <button class="rounded-xl bg-[#22583e] px-4 py-3 font-semibold text-white">Poner respuesta en cola</button>
                    </form>
                </section>
            @endif
            <section class="rounded-3xl bg-[#254f3b] p-6 text-white"><h2 class="text-lg font-semibold">Atención del coordinador</h2><p class="mt-3 text-sm leading-6">{{ $conversation->human_assigned ? 'El asistente está en pausa. Atiende al productor y reanúdalo cuando corresponda.' : 'El asistente está activo según el consentimiento del canal.' }}</p><p class="mt-3 break-words text-xs text-[#d0ddcb]">Paso: {{ \App\Models\Conversation::STATE_LABELS[$conversation->state] ?? 'Revisión del coordinador' }}</p>
                <form method="POST" action="{{ route('messaging.assist', $conversation) }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="action" value="{{ $conversation->human_assigned ? 'resume' : 'pause' }}">
                    <label class="block text-sm" for="reason">Motivo / nota interna</label><textarea name="reason" id="reason" rows="2" required minlength="5" maxlength="500" class="w-full rounded-xl bg-white p-3 text-[#243c32]"></textarea><button class="rounded-xl bg-white px-4 py-3 text-sm font-semibold text-[#22583e]">{{ $conversation->human_assigned ? 'Reanudar asistente' : 'Tomar atención' }}</button>
                </form>
            </section>
            @if(!$conversation->producer_id && $conversation->state === 'WAITING_IDENTITY_VERIFICATION' && $conversation->consent === 'granted')
                @can('manage-producers')
                    <section class="rounded-3xl border border-[#dfe4da] bg-white p-6"><h2 class="text-lg font-semibold">Completar identidad</h2><p class="mt-3 text-sm leading-6 text-[#6f7d66]">Captura asistida del coordinador. El DNI se guarda en la ficha privada y no se incorpora al chat. En simulación utiliza datos sintéticos.</p>
                        <form method="POST" action="{{ route('messaging.register', $conversation) }}" class="mt-5 space-y-4">
                            @csrf
                            <x-field name="full_name" label="Nombre completo" :value="$conversation->context['full_name'] ?? ''" required />
                            <x-field name="dni" label="DNI" required minlength="8" maxlength="8" inputmode="numeric" />
                            <div><label class="mb-2 block text-sm font-semibold" for="type">Tipo de productor</label><select name="type" id="type" required class="w-full rounded-xl border border-[#cdd8c7] p-3">@foreach(\App\Models\Producer::TYPES as $key => $label)<option value="{{ $key }}" @selected(($conversation->context['type'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
                            <button class="rounded-xl bg-[#22583e] px-4 py-3 font-semibold text-white">Registrar para verificación</button>
                        </form>
                    </section>
                @endcan
            @endif
            <p class="px-2 text-xs leading-5 text-[#6f7d66]">Un recibo de lectura no acepta una oferta ni sus condiciones. Los resultados de entrega inciertos requieren conciliación; no se reenvían automáticamente.</p>
        </aside>
    </div>
</x-layout>

