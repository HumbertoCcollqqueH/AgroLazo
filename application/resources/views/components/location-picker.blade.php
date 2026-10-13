@props(['latitude' => 'latitude', 'longitude' => 'longitude'])
<div class="location-picker" data-location-picker data-latitude-name="{{ $latitude }}" data-longitude-name="{{ $longitude }}" @auth data-reverse-url="{{ route('locations.reverse') }}" @endauth>
    <div class="location-picker-heading"><div><strong>Marca el lugar exacto</strong><p>Busca la zona, acerca el mapa y toca la entrada del lugar. Las coordenadas se completan solas.</p></div><span class="location-picker-badge">Punto de encuentro</span></div>
    <div class="location-picker-actions">
        <button type="button" data-location-detect>◎ Usar mi ubicación actual</button>
        <label>Acercar a una localidad<select data-location-town aria-label="Acercar mapa a localidad"><option value="">Selecciona una zona</option><option value="-15.4997,-70.1333">Juliaca</option><option value="-15.8402,-70.0219">Puno</option><option value="-15.649,-70.321">Cabana</option><option value="-15.642,-70.355">Cabanillas</option><option value="-15.298,-69.978">Taraco</option><option value="-16.086,-69.638">Ilave</option><option value="-14.908,-70.197">Azángaro</option></select></label>
    </div>
    @auth
        <p class="text-xs leading-6">Al marcar un punto consultaremos su dirección en Photon (datos de OpenStreetMap). Se envían solo las coordenadas, sin nombres ni teléfonos. Los datos son sugerencias: revisa el número y las referencias de acceso.</p>
        <p class="text-sm leading-6" data-location-address-status role="status" aria-live="polite"></p>
        <button type="button" class="text-sm underline" data-location-address-retry>Consultar dirección del punto</button>

    @endauth
    <div class="location-map-frame">
        <canvas class="location-canvas" data-location-canvas tabindex="0" role="application" aria-label="Mapa: arrastra para desplazarte, toca para marcar. Con teclado usa flechas para moverte y Enter para elegir el centro."></canvas>
        <div class="location-map-zoom"><button type="button" data-location-zoom="1" aria-label="Acercar mapa">+</button><button type="button" data-location-zoom="-1" aria-label="Alejar mapa">−</button></div>
        <a class="location-attribution" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap</a>
    </div>
    <p data-location-status class="location-picker-status" role="status" aria-live="polite">Selecciona el lugar en el mapa. La vista inicial es solo una referencia; no guarda una ubicación por ti.</p>
    @auth
        <section class="location-rural" aria-label="Comunidades y sectores rurales">
            <strong>¿El lugar está en una comunidad o sector?</strong>
            <p>Consulta lugares cercanos o busca su nombre. Elige solo si reconoces el lugar. El punto exacto permanece donde lo marcaste.</p>
            <div class="location-rural-search"><input type="search" maxlength="100" data-rural-query aria-label="Nombre de comunidad o sector" placeholder="Ejemplo: nombre de comunidad o sector"><button type="button" data-rural-search>Buscar comunidad</button></div>
            <p data-rural-status role="status" aria-live="polite">Marca un punto para ver centros poblados cercanos.</p>
            <div data-rural-results></div>
            <small>Referencia: <a href="https://pifa.oefa.gob.pe/arcgis/rest/services/OSPA/SERV_BASE_PROB/MapServer/26" target="_blank" rel="noopener noreferrer">centros poblados INEI publicados por OEFA</a>. Cobertura local: Puno. Son puntos de referencia, no límites territoriales. Puedes escribir un nombre local que no aparezca.</small>
        </section>
    @endauth
    <p class="location-picker-help">Si estás en otro lugar, marca el destino en el mapa. Puedes arrastrarlo y usar + para acercarte. Con teclado: flechas y Enter. El mapa usa OpenStreetMap y requiere conexión.</p>
</div>
