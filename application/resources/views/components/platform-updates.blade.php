@auth
<div class="platform-updates" data-platform-updates="{{ route('coordination.updates') }}" data-realtime-key="{{ config('broadcasting.connections.reverb.key') }}" data-platform-account="{{ auth()->id() }}" data-platform-notice="{{ auth()->user()->notifications()->latest('created_at')->latest('id')->value('id') }}" role="status" aria-live="polite" hidden><a href="{{ route('notifications.index') }}"><strong data-platform-summary>Tienes novedades en AgroLazo.</strong><span>Abrir avisos →</span></a><button type="button" data-dismiss-notices aria-label="Ocultar aviso">×</button></div>
@endauth

