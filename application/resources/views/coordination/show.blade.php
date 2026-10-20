<x-layout :title="$thread->subject">
    <div class="chat-page" data-chat data-user-id="{{ auth()->id() }}" data-thread-id="{{ $thread->id }}" data-realtime-key="{{ config('broadcasting.connections.reverb.key') }}" data-messages-url="{{ route('coordination.messages', $thread) }}" data-seen-url="{{ route('coordination.seen', $thread) }}" data-peer-label="{{ $operator ? 'La persona' : 'El equipo' }}">
        <a class="pp-link" href="{{ route('coordination.index') }}">← Conversaciones</a>
        <header class="chat-heading"><span class="chat-avatar">{{ mb_substr($operator ? $thread->user->name : 'AgroLazo', 0, 1) }}</span><div><p class="pp-kicker">CONVERSACIÓN #{{ $thread->id }}</p><h1>{{ $thread->subject }}</h1><span class="chat-connection" data-chat-connection>Conectando…</span></div></header>
        @if($operator && $participantContext)@include('coordination.participant', ['participantContext' => $participantContext])@else<p class="chat-help">Equipo AgroLazo · Tu conversación es privada con el equipo autorizado.</p>@endif
        <section class="chat-window" aria-label="Mensajes de la conversación">
            <div class="chat-history" data-chat-history tabindex="0" aria-label="Historial de mensajes">
                <button class="chat-older" type="button" data-chat-older @if($messages->count() < 40) hidden @endif>Ver mensajes anteriores</button>
                <div data-chat-messages role="log" aria-live="polite" aria-relevant="additions">
                @foreach($messages as $message)<article class="chat-bubble {{ $message->user_id === auth()->id() ? 'is-own' : '' }}" data-message-id="{{ $message->id }}" data-created-at="{{ $message->created_at->toIso8601String() }}"><strong>{{ $message->user->name }}</strong><p>{{ $message->body }}</p><time>{{ $message->created_at->timezone('America/Lima')->format('d/m H:i') }}{{ $message->user_id === auth()->id() ? ' · Enviado' : '' }}</time></article>@endforeach
                </div>
                <p class="chat-empty" data-chat-empty @if($messages->count()) hidden @endif>Escribe el primer mensaje.</p>
            </div>
            <button class="chat-new" type="button" data-chat-new hidden>Ver mensajes nuevos ↓</button>
            <form method="POST" action="{{ route('coordination.reply', $thread) }}" class="chat-composer" data-chat-composer>@csrf<input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}"><label class="sr-only" for="chat-body">Escribe tu mensaje</label><textarea id="chat-body" name="body" rows="1" required maxlength="4000" placeholder="Escribe tu mensaje…">{{ old('body') }}</textarea><button class="pp-button" type="submit">Enviar <span aria-hidden="true">↑</span></button><p class="chat-send-status" data-chat-send-status role="status">Pulsa Enviar para compartir tu mensaje.</p></form>
        </section>
    </div>
</x-layout>
