<x-layout :title="$producer->full_name">
    <a href="{{ route('producers.index') }}" class="text-sm font-semibold underline underline-offset-4">← Volver a productores</a>
    <div class="mb-8 mt-7 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0"><p class="text-xs font-bold uppercase tracking-widest text-[#819077]">Ficha privada · Productor #{{ $producer->id }}</p><h1 class="mt-3 break-words text-3xl font-semibold tracking-tight">{{ $producer->full_name }}</h1><p class="mt-3 text-sm text-[#6f7d66]">{{ \App\Models\Producer::TYPES[$producer->type] }}</p></div>
        <span class="rounded-full px-4 py-2 text-sm font-semibold {{ $producer->status === 'verified' ? 'bg-[#eaf1e3] text-[#4c6c3c]' : ($producer->status === 'pending_verification' ? 'bg-amber-50 text-amber-800' : 'bg-stone-200 text-stone-700') }}">{{ \App\Models\Producer::STATUSES[$producer->status] }}</span>
    </div>
    <div class="grid items-start gap-6 lg:grid-cols-[1.35fr_1fr]">
        <section class="rounded-3xl border border-[#dfe4da] bg-white p-7">
            <div class="flex items-center justify-between gap-3"><h2 class="text-xl font-semibold">Datos del productor</h2>@can('update', $producer)<a href="{{ route('producers.edit', $producer) }}" class="text-sm font-semibold underline underline-offset-4">Editar ficha</a>@endcan</div>
            <p class="mt-2 text-xs leading-5 text-[#788373]">Información para la atención del productor. Visible para administración y coordinación.</p>
            <dl class="mt-6 grid gap-5 text-sm sm:grid-cols-2">
                <div><dt class="text-[#819077]">DNI</dt><dd class="mt-1 font-semibold">{{ $producer->dni }}</dd></div>
                <div><dt class="text-[#819077]">Teléfono</dt><dd class="mt-1 font-semibold">{{ $producer->phone }}</dd></div>
                <div><dt class="text-[#819077]">Asociación</dt><dd class="mt-1 break-words">{{ $producer->association ?: 'Sin registrar' }}</dd></div>
                <div><dt class="text-[#819077]">Correo electrónico</dt><dd class="mt-1 break-all">{{ $producer->email ?: 'Sin registrar' }}</dd></div>
                <div><dt class="text-[#819077]">Sexo</dt><dd class="mt-1">{{ ['female' => 'Femenino', 'male' => 'Masculino', 'unspecified' => 'Prefiere no indicar'][$producer->sex] ?? 'Sin registrar' }}</dd></div>
                <div><dt class="text-[#819077]">Fecha de nacimiento</dt><dd class="mt-1">{{ $producer->birth_date?->format('d/m/Y') ?? 'Sin registrar' }}</dd></div>
            </dl>
            <div class="mt-6 rounded-2xl bg-[#f5f7f0] p-5">
                <h3 class="font-semibold">Productos que produce</h3>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($producer->products as $product)
                        <span class="rounded-full border border-[#d7e0cd] bg-white px-3 py-1 text-sm">{{ $product->name }}{{ $product->isUsable() ? '' : ' (inactivo)' }}</span>
                    @empty
                        <p class="text-sm text-[#6f7d66]">Sin productos registrados. Completa este dato en «Editar ficha» para personalizar sus opciones por WhatsApp.</p>
                    @endforelse
                </div>
            </div>
            <p class="mt-7 border-t border-[#e5eadf] pt-4 text-xs leading-5 text-[#788373]">Registrado por {{ $producer->registeredBy->name }} · {{ $producer->created_at->timezone('America/Lima')->format('d/m/Y H:i') }} (Perú)</p>
            @php
                $currentLocation = $producer->currentLocation;
            @endphp
            <div class="mt-6 rounded-2xl bg-[#f5f7f0] p-5">
                <h3 class="font-semibold">Ubicación actual</h3>
                @if($currentLocation)
                    <p class="mt-3 text-sm">{{ $currentLocation->location?->name ?? 'Sin localidad del catálogo' }}{{ $currentLocation->community ? ' · '.$currentLocation->community : '' }}</p>
                    @if($currentLocation->address)<p class="mt-2 text-sm text-[#6f7d66]">{{ $currentLocation->address }}</p>@endif
                    @if($currentLocation->latitude !== null)<p class="mt-2 text-xs text-[#6f7d66]">Coordenadas: {{ $currentLocation->latitude }}, {{ $currentLocation->longitude }}</p>@endif
                @else<p class="mt-3 text-sm text-[#6f7d66]">Todavía no se ha registrado una ubicación.</p>@endif
            </div>
        </section>
        <aside class="space-y-6">
            <section class="rounded-3xl bg-[#254f3b] p-7 text-white">
                <p class="text-xs font-bold uppercase tracking-widest text-[#bed0af]">Verificación del productor</p>
                <h2 class="mt-3 text-2xl font-semibold">{{ $producer->canPublishOffers() ? 'Identidad verificada' : 'Aún sin habilitación para ofertas' }}</h2>
                <p class="mt-3 text-sm leading-6 text-[#d0ddcb]">{{ $producer->canPublishOffers() ? 'El equipo ha registrado la verificación. Cada oferta requiere revisión y condiciones aceptadas antes de publicarse.' : 'Solo los productores verificados podrán presentar ofertas. Cada decisión queda vinculada al responsable y a sus observaciones.' }}</p>
            </section>
            @can('verify', $producer)
                <section class="rounded-3xl border border-[#dfe4da] bg-white p-7">
                    <h2 class="text-xl font-semibold">Registrar una decisión</h2>
                    <p class="mt-2 text-sm leading-6 text-[#6f7d66]">Comprueba la información con el productor antes de verificarlo. Esta revisión es manual.</p>
                    <form method="POST" action="{{ route('producers.verify', $producer) }}" class="mt-5 space-y-4">@csrf
                        <div><label for="result" class="mb-2 block text-sm font-semibold">Decisión</label><select id="result" name="result" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Selecciona una decisión</option>@foreach($allowedTransitions as $result)<option value="{{ $result }}" @selected(old('result') === $result)>{{ \App\Models\Producer::STATUSES[$result] }}</option>@endforeach</select></div>
                        <div><label for="method" class="mb-2 block text-sm font-semibold">Método de verificación</label><select id="method" name="method" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Selecciona un método</option>@foreach(\App\Models\ProducerVerification::METHODS as $key => $label)<option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option>@endforeach</select></div>
                        <div><label for="notes" class="mb-2 block text-sm font-semibold">Observaciones de la revisión</label><textarea id="notes" name="notes" required minlength="5" maxlength="2000" rows="3" class="w-full rounded-xl border border-[#cdd8c7] px-4 py-3">{{ old('notes') }}</textarea></div>
                        <p class="text-xs leading-5 text-[#788373]">Las observaciones son privadas. Archivar cierra la ficha y conserva su historial; después no se podrá editar.</p>
                        <button type="submit" class="w-full rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white hover:bg-[#173f2c]">Guardar decisión</button>
                    </form>
                </section>
            @endcan
        </aside>
    </div>
    <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
        @can('update', $producer)
            <details class="rounded-3xl border border-[#dfe4da] bg-white p-7" @if($errors->hasAny(['current_phone', 'new_phone', 'reason'])) open @endif>
                <summary class="cursor-pointer text-lg font-semibold">Cambiar teléfono</summary>
                <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Confirma el teléfono actual y el nuevo con el productor. El cambio conserva los números anteriores y requiere volver a verificar a un productor verificado.</p>
                <form method="POST" action="{{ route('producers.change-phone', $producer) }}" autocomplete="off" class="mt-5 space-y-4">@csrf @method('PATCH')
                    <x-field name="current_phone" label="Teléfono actual" type="tel" required maxlength="20" />
                    <x-field name="new_phone" label="Teléfono nuevo" type="tel" required maxlength="20" />
                    <x-field name="reason" label="Motivo del cambio de teléfono" required minlength="5" maxlength="500" />
                    <button type="submit" class="rounded-xl border border-[#c4d1bb] bg-[#f1f5ec] px-5 py-3 font-semibold">Guardar nuevo teléfono</button>
                </form>
            </details>
        @endcan
        @can('manage-producers')
            <details class="rounded-3xl border border-[#dfe4da] bg-white p-7" @if($errors->hasAny(['purpose', 'notice_version', 'decision'])) open @endif>
                <summary class="cursor-pointer text-lg font-semibold">Registrar consentimiento</summary>
                <p class="mt-3 text-sm leading-6 text-[#6f7d66]">Registra la respuesta que dio el productor después de presentarle el aviso correspondiente. Cada finalidad mantiene su historial. Esta constancia no envía mensajes.</p>
                <form method="POST" action="{{ route('producers.consent', $producer) }}" class="mt-5 space-y-4">@csrf
                    <div><label for="purpose" class="mb-2 block text-sm font-semibold">Finalidad</label><select id="purpose" name="purpose" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Selecciona una finalidad</option><option value="profile_contact" @selected(old('purpose') === 'profile_contact')>Registro y contacto operativo</option><option value="whatsapp_messages" @selected(old('purpose') === 'whatsapp_messages')>Mensajes por WhatsApp</option></select></div>
                    <x-field name="notice_version" label="Versión del aviso presentado" required maxlength="80" hint="Identificador del aviso que efectivamente se presentó al productor." />
                    <div><label for="decision" class="mb-2 block text-sm font-semibold">Decisión del productor</label><select id="decision" name="decision" required class="w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3"><option value="">Selecciona su respuesta</option><option value="granted" @selected(old('decision') === 'granted')>Aceptó</option><option value="declined" @selected(old('decision') === 'declined')>No aceptó / retiró el consentimiento</option></select></div>
                    <button type="submit" class="rounded-xl border border-[#c4d1bb] bg-[#f1f5ec] px-5 py-3 font-semibold">Guardar constancia</button>
                </form>
            </details>
        @endcan
    </div>
    <section class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7">
        <h2 class="text-xl font-semibold">Historial de verificación</h2>
        <div class="mt-4 divide-y divide-[#e9ede3]">
            @forelse($producer->verifications as $verification)
                <article class="py-4"><div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">{{ \App\Models\Producer::STATUSES[$verification->result] }}</h3><p class="text-xs text-[#788373]">{{ $verification->verified_at->timezone('America/Lima')->format('d/m/Y H:i') }} (Perú)</p></div><p class="mt-2 text-xs text-[#6f7d66]">{{ $verification->verifiedBy->name }} · {{ \App\Models\ProducerVerification::METHODS[$verification->method] }}</p><p class="mt-3 whitespace-pre-line break-words text-sm leading-6">{{ $verification->notes }}</p></article>
            @empty<p class="py-4 text-sm text-[#6f7d66]">Pendiente de la primera revisión.</p>@endforelse
        </div>
    </section>
    <section class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7">
        <h2 class="text-xl font-semibold">Historial de contacto y ubicación</h2>
        <div class="mt-4 divide-y divide-[#e9ede3]">
            @forelse($producer->phoneChanges as $change)
                <article class="py-4"><h3 class="text-sm font-semibold">Cambio de teléfono · {{ $change->changed_at->timezone('America/Lima')->format('d/m/Y H:i') }}</h3><p class="mt-2 text-sm">{{ $change->previous_phone }} → {{ $change->new_phone }}</p><p class="mt-2 break-words text-sm text-[#6f7d66]">{{ $change->reason }}</p><p class="mt-2 text-xs text-[#788373]">{{ $change->changedBy->name }}</p></article>
            @empty<p class="py-4 text-sm text-[#6f7d66]">Sin cambios de teléfono.</p>@endforelse
            @forelse($producer->locations as $historyLocation)
                <article class="py-4"><h3 class="text-sm font-semibold">{{ $historyLocation->location?->name ?? 'Ubicación informada' }}{{ $historyLocation->community ? ' · '.$historyLocation->community : '' }}</h3><p class="mt-2 break-words text-sm text-[#6f7d66]">{{ $historyLocation->address }}</p>@if($historyLocation->latitude !== null)<p class="mt-2 text-xs text-[#6f7d66]">{{ $historyLocation->latitude }}, {{ $historyLocation->longitude }}</p>@endif<p class="mt-2 text-xs text-[#788373]">{{ $historyLocation->valid_from->timezone('America/Lima')->format('d/m/Y H:i') }} — {{ $historyLocation->valid_until?->timezone('America/Lima')->format('d/m/Y H:i') ?? 'Actual' }} · {{ $historyLocation->recordedBy->name }}</p></article>
            @empty<p class="py-4 text-sm text-[#6f7d66]">Sin ubicaciones registradas.</p>@endforelse
        </div>
    </section>
    <details class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7">
        <summary class="cursor-pointer text-xl font-semibold">Historial de correcciones de la ficha</summary>
        <div class="mt-4 divide-y divide-[#e9ede3]">
            @forelse($producer->profileChanges as $change)
                <article class="py-4">
                    <h3 class="text-sm font-semibold">{{ $change->changed_at->timezone('America/Lima')->format('d/m/Y H:i') }} (Perú) · {{ $change->changedBy->name }}</h3>
                    <p class="mt-3 break-words text-sm leading-6">{{ $change->reason }}</p>
                    <dl class="mt-3 space-y-3 text-sm">
                        @foreach($change->changes as $field => $values)
                            @php
                                $fieldLabels = ['full_name' => 'Nombre completo', 'type' => 'Tipo de productor', 'association' => 'Asociación', 'email' => 'Correo electrónico', 'sex' => 'Sexo', 'birth_date' => 'Fecha de nacimiento', 'location_version_id' => 'Ubicación', 'product_ids' => 'Productos que produce', 'status' => 'Estado'];
                                $valueLabels = match($field) { 'type' => \App\Models\Producer::TYPES, 'status' => \App\Models\Producer::STATUSES, 'sex' => ['female' => 'Femenino', 'male' => 'Masculino', 'unspecified' => 'Prefiere no indicar'], default => [] };
                            @endphp
                            <div><dt class="font-semibold text-[#6f7d66]">{{ $fieldLabels[$field] ?? $field }}</dt><dd class="mt-1 break-words">@if($field === 'location_version_id')Ubicación actualizada; consulta sus versiones en el historial de contacto y ubicación.@elseif($field === 'product_ids'){{ collect($values['before'])->map(fn ($id) => $productNames[$id] ?? 'Producto #'.$id)->implode(', ') ?: 'Sin registrar' }} → {{ collect($values['after'])->map(fn ($id) => $productNames[$id] ?? 'Producto #'.$id)->implode(', ') ?: 'Sin registrar' }}@else{{ $valueLabels[$values['before'] ?? ''] ?? $values['before'] ?? 'Sin registrar' }} → {{ $valueLabels[$values['after'] ?? ''] ?? $values['after'] ?? 'Sin registrar' }}@endif</dd></div>
                        @endforeach
                    </dl>
                </article>
            @empty<p class="py-4 text-sm text-[#6f7d66]">Sin correcciones de ficha registradas.</p>@endforelse
        </div>
    </details>
    <section class="mt-6 rounded-3xl border border-[#dfe4da] bg-white p-7">
        <h2 class="text-xl font-semibold">Constancias de consentimiento</h2>
        <p class="mt-2 text-xs leading-5 text-[#788373]">La constancia más reciente de cada finalidad representa la última respuesta registrada.</p>
        <div class="mt-4 divide-y divide-[#e9ede3]">
            @forelse($producer->consents as $consent)
                <article class="py-4"><h3 class="text-sm font-semibold">{{ \App\Models\ProducerConsent::PURPOSES[$consent->purpose] ?? $consent->purpose }} · {{ $consent->decision === 'granted' ? 'Aceptó' : 'No aceptó / retiró' }}</h3><p class="mt-2 text-xs text-[#6f7d66]">Aviso {{ $consent->notice_version }} · Canal {{ ['assisted_web' => 'Registro asistido', 'simulator' => 'Simulación', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'][$consent->channel] ?? $consent->channel }}</p><p class="mt-2 text-xs text-[#788373]">{{ $consent->recorded_at->timezone('America/Lima')->format('d/m/Y H:i') }} (Perú) · {{ $consent->recordedBy?->name ?? 'Respuesta del productor por mensajería' }}</p></article>
            @empty<p class="py-4 text-sm text-[#6f7d66]">No se ha registrado una respuesta del productor.</p>@endforelse
        </div>
    </section>
</x-layout>
