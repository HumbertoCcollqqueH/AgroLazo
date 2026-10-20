<x-layout title="Avisos">
    <div class="pp-section-heading"><div><p class="pp-kicker">CONECTADOS EN AGROLAZO</p><h1>Avisos</h1><p>Novedades de tus ofertas, compras y entregas.</p></div><button type="button" class="pp-button pp-button--outline" data-refresh-page>Actualizar</button></div>
    <section class="pp-alert-settings" aria-label="Notificaciones del dispositivo">
        <div><strong>Entérate de lo que necesita tu atención</strong><p data-notification-status>Recibe avisos de ofertas, compras, entregas, pagos y respuestas del equipo.</p></div>
        <button type="button" class="pp-button" data-enable-notifications hidden>Activar notificaciones</button>
    </section>
    <section class="pp-card">
        <div class="pp-section-heading"><h2>Mis avisos <span class="pp-count" data-unread-count>{{ $unread }}</span></h2>@if($unread)<form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="pp-text-button">Marcar todos leídos</button></form>@endif</div>
        <nav class="pp-alert-filters" aria-label="Filtrar avisos"><a href="{{ route('notifications.index') }}" @if(!request()->boolean('unread')) aria-current="page" @endif>Todos</a><a href="{{ route('notifications.index', ['unread' => 1]) }}" @if(request()->boolean('unread')) aria-current="page" @endif>Sin leer</a></nav>
        <div class="pp-notices">@forelse($notifications as $notice)
            <article @class(['pp-notice', 'is-unread' => !$notice->read_at])><time>{{ $notice->created_at->timezone('America/Lima')->format('d/m H:i') }}</time><span class="pp-notice-state">{{ $notice->read_at ? 'Leído' : 'Nuevo' }}</span><p>{{ $notice->data['body'] ?? 'Tienes una novedad.' }}</p>
                <div class="flex flex-wrap gap-4 mt-3">@if(!empty($notice->data['url']))<form method="POST" action="{{ route('notifications.open', $notice->id) }}">@csrf<button class="pp-link">Ver detalle →</button></form>@endif @unless($notice->read_at)<form method="POST" action="{{ route('notifications.read', $notice->id) }}">@csrf<button class="pp-text-button">Marcar leído</button></form>@endunless</div>
            </article>
        @empty<p class="pp-empty">{{ request()->boolean('unread') ? 'Estás al día. No tienes avisos pendientes de leer.' : 'Aquí recibirás los avisos de tus operaciones.' }}</p>@endforelse</div>{{ $notifications->links() }}
    </section></x-layout>
