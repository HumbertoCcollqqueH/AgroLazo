<x-layout title="Conversaciones">
    <div class="pp-section-heading"><div><p class="pp-kicker">CERCA DE TI</p><h1>Conversaciones</h1><p>{{ $operator ? 'Conversa con productores y compradores, con su información a mano.' : 'Habla con el equipo de AgroLazo sobre tus ofertas, compras o entregas.' }}</p></div><a class="pp-button" href="#nueva-conversacion">Nueva conversación</a></div>
    <div class="chat-inbox-grid">
        <section class="pp-card" data-conversation-list data-user-id="{{ auth()->id() }}" data-realtime-key="{{ config('broadcasting.connections.reverb.key') }}" data-list-url="{{ route('coordination.conversations', request()->only('q', 'page')) }}">
            <div class="pp-section-heading"><h2>{{ $operator ? 'Bandeja del equipo' : 'Mis conversaciones' }}</h2><span class="chat-connection" data-chat-connection>Conectando…</span></div>
            <form class="pp-search" method="GET"><input name="q" value="{{ request('q') }}" placeholder="Buscar por nombre o asunto" maxlength="100" aria-label="Buscar conversaciones"><button class="pp-button">Buscar</button></form>
            <div class="chat-inbox-list" data-chat-threads>
            @forelse($threads as $thread)
                @php($last = $thread->messages->first())
                @php($pending = $thread->unreadFor(auth()->user(), $operator))
                <a class="chat-thread {{ $pending ? 'is-unread' : '' }}" href="{{ route('coordination.show', $thread) }}"><span class="chat-avatar">{{ mb_substr($operator ? $thread->user->name : 'AgroLazo', 0, 1) }}</span><span class="chat-thread-copy"><strong>{{ $operator ? $thread->user->name : 'Equipo AgroLazo' }}</strong><b>{{ $thread->subject }}</b><small>{{ \Illuminate\Support\Str::limit($last?->body, 180) }}</small></span><time>{{ $last?->created_at->timezone('America/Lima')->format('d/m H:i') }}</time></a>
            @empty<p class="pp-empty">Aún no tienes conversaciones. Escribe tu primera consulta y el equipo podrá responderte aquí.</p>@endforelse
            </div>{{ $threads->links() }}
        </section>
        <section class="pp-card" id="nueva-conversacion"><p class="pp-kicker">EMPECEMOS A CONVERSAR</p><h2>{{ $operator ? 'Contactar a una persona' : '¿En qué podemos ayudarte?' }}</h2><p class="chat-help">Los mensajes quedan guardados en tu cuenta. Puedes volver a esta conversación cuando lo necesites.</p>
            <form method="POST" action="{{ route('coordination.store') }}" class="pp-form mt-5">@csrf<input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
                @if($operator)<label>Productor o comprador<select name="recipient_id"><option value="">Mi equipo</option>@foreach($recipients as $recipient)<option value="{{ $recipient->id }}" @selected((string) old('recipient_id') === (string) $recipient->id)>{{ $recipient->name }} · {{ $recipient->roles->contains('code', 'producer') ? 'Productor' : 'Comprador' }} · {{ $recipient->email }}</option>@endforeach</select></label>@endif
                <x-field name="subject" label="¿Sobre qué quieres conversar?" required maxlength="160" placeholder="Ejemplo: el precio de mi quinua" />
                <label>Mensaje<textarea name="body" rows="4" required maxlength="4000" placeholder="Cuéntanos qué necesitas…">{{ old('body') }}</textarea></label><button class="pp-button">Iniciar conversación →</button>
            </form>
        </section>
    </div>
</x-layout>
