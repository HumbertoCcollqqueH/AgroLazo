@if($destination)
    <dl class="space-y-3 text-sm leading-6">
        <div><dt class="font-semibold">Recibe</dt><dd>{{ $destination['recipient'] }} · {{ $destination['phone'] }}</dd></div>
        <div><dt class="font-semibold">Destino</dt><dd>{{ $destination['street'] }}<br>{{ $destination['district'] }}, {{ $destination['province'] }}, {{ $destination['department'] }}</dd></div>
        <div><dt class="font-semibold">Referencia y acceso</dt><dd>{{ $destination['reference'] }}</dd></div>
        <div><dt class="font-semibold">Punto exacto</dt><dd>{{ $destination['latitude'] }}, {{ $destination['longitude'] }} · <a class="underline" href="https://www.google.com/maps?q={{ rawurlencode($destination['latitude'].','.$destination['longitude']) }}" target="_blank" rel="noopener noreferrer">Abrir ubicación en el mapa</a></dd></div>
    </dl>
@else
    <p class="rounded-xl bg-amber-50 p-4 text-sm">Falta completar el destino: dirección, contacto receptor y punto exacto en el mapa. No se puede programar el despacho hasta completarlo.</p>
@endif
