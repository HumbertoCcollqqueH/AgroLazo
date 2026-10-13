const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
const project = (lat, lng, zoom) => {
    const size = 256 * 2 ** zoom;
    const sine = Math.sin(clamp(lat, -85, 85) * Math.PI / 180);
    return [(lng + 180) / 360 * size, (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * size];
};
const unproject = (x, y, zoom) => {
    const size = 256 * 2 ** zoom;
    return [Math.atan(Math.sinh(Math.PI * (1 - 2 * y / size))) * 180 / Math.PI, ((x / size * 360) % 360 + 360) % 360 - 180];
};
for (const panel of document.querySelectorAll('[data-location-picker]')) {
    const form = panel.closest('form');
    if (!form) continue;
    const latitude = form.elements.namedItem(panel.dataset.latitudeName);
    const longitude = form.elements.namedItem(panel.dataset.longitudeName);
    if (!latitude || !longitude) continue;
    const canvas = panel.querySelector('[data-location-canvas]');
    const context = canvas.getContext('2d');
    const status = panel.querySelector('[data-location-status]');
    const detect = panel.querySelector('[data-location-detect]');
    const tiles = new Map();
    let center = [-15.4997, -70.1333], zoom = 13, selected = null, width = 0, height = 320, pointer = null;
    const addressStatus = panel.querySelector('[data-location-address-status]');
    const delivery = panel.dataset.latitudeName.startsWith('delivery_address[');
    const addressFields = delivery
        ? {department:'delivery_address[department]', province:'delivery_address[province]', district:'delivery_address[district]', street:'delivery_address[street]'}
        : {location_id:'location_id', community:'community', street:'address'};
    const generated = new Map();
    for (const name of Object.values(addressFields)) {
        const field = form.elements.namedItem(name);
        if (field) generated.set(name, field.value);
    }
    let lookupTimer, lookupController, lookupVersion = 0;
    let markingPoint = false;
    const ruralResults = panel.querySelector('[data-rural-results]');
    const ruralStatus = panel.querySelector('[data-rural-status]');
    const ruralQuery = panel.querySelector('[data-rural-query]');
    const renderRural = places => {
        if (!ruralResults) return;
        ruralResults.replaceChildren();
        ruralStatus.textContent = places.length ? 'Lugares cercanos al punto marcado. Confirma cuál corresponde; la distancia es en línea recta.' : 'No hay coincidencias cercanas en esta fuente. Puedes escribir el nombre que conoce el productor en Comunidad o sector y añadir una referencia de acceso.';
        for (const place of places) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'location-rural-option';
            button.textContent = `${place.community} · ${place.district}, ${place.province} · a ${place.distance_km.toLocaleString('es-PE')} km — Usar este lugar`;
            button.addEventListener('click', () => {
                let preserved = 0;
                for (const [key, name] of Object.entries(addressFields)) {
                    const field = form.elements.namedItem(name);
                    if (!field) continue;
                    if (field.value !== '' && field.value !== generated.get(name)) { preserved++; continue; }
                    if (key === 'street' && field.value !== '') continue;
                    const value = key === 'street' ? `Centro poblado ${place.community}` : String(place[key] ?? '');
                    if (field.tagName === 'SELECT' && !Array.from(field.options).some(option => option.value === value)) continue;
                    field.value = value; generated.set(name, value);
                }
                ruralStatus.textContent = `Elegiste ${place.community}. Conservamos el punto exacto marcado. Completa cómo llegar a la parcela o entrada.${preserved ? ' Conservamos tus correcciones manuales; revisa que correspondan a este lugar.' : ''}`;
                addressStatus.textContent = 'Referencia rural seleccionada. Comprueba localidad y dirección antes de guardar.';
            });
            ruralResults.append(button);
        }
    };
    const lookupAddress = async (query = null) => {
        if (!selected || !panel.dataset.reverseUrl) return;
        const version = ++lookupVersion;
        lookupController?.abort();
        lookupController = new AbortController();
        addressStatus.textContent = query === null ? 'Buscando calle, localidad y centros poblados cercanos…' : 'Buscando comunidad en el catálogo rural de Puno…';
        try {
            const response = await fetch(panel.dataset.reverseUrl, {
                method:'POST', credentials:'same-origin', signal:lookupController.signal,
                headers:{'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':form.elements.namedItem('_token')?.value || ''},
                body:JSON.stringify({latitude:selected[0], longitude:selected[1], ...(query === null ? {} : {rural_query:query})})
            });
            const result = await response.json();
            if (version !== lookupVersion) return;
            if (!response.ok) throw new Error(response.status === 429 ? 'Hay otra consulta en curso. Pulsa «Consultar dirección del punto» en unos segundos.' : 'No se pudo consultar la dirección. Completa los campos manualmente o vuelve a consultar.');
            renderRural(result.nearby_places || []);
            if (query !== null) { addressStatus.textContent = 'Búsqueda local completada. Elige una referencia solo si corresponde al lugar marcado.'; return; }
            let filled = 0, preserved = 0;
            for (const [key, name] of Object.entries(addressFields)) {
                const field = form.elements.namedItem(name);
                if (!field) continue;
                if (field.value !== '' && field.value !== generated.get(name)) { preserved++; continue; }
                let value = result[key] == null ? '' : String(result[key]);
                if (field.tagName === 'SELECT' && !Array.from(field.options).some(option => option.value === value)) value = '';
                field.value = value; generated.set(name, value);
                if (value) filled++;
            }
            addressStatus.textContent = filled
                ? `Dirección sugerida completada. Revisa calle, número y localidad; escribe las referencias de acceso.${preserved ? ' Conservamos los campos que modificaste manualmente.' : ''}${!result.district ? ' No se identificó un distrito del catálogo: selecciónalo o escríbelo.' : ''}`
                : (result.nearby_places?.length ? 'No hay una calle identificada para este punto. Puedes elegir una comunidad cercana de la lista y añadir cómo llegar a la parcela.' : 'El punto exacto está marcado, aunque el mapa no conoce su nombre. Escribe la comunidad y una referencia de acceso; no necesitas una calle urbana.') + (result.lookup_status === 'unavailable' ? ' La consulta de calles no está disponible; el catálogo rural sigue funcionando.' : '');
        } catch (error) {
            if (error.name !== 'AbortError' && version === lookupVersion) addressStatus.textContent = error.message || 'No se pudo consultar la dirección. Completa los campos manualmente.';
        }
    };
    const queueLookup = () => {
        clearTimeout(lookupTimer); lookupVersion++; lookupController?.abort();
        ruralResults?.replaceChildren();
        if (!panel.dataset.reverseUrl) return;
        for (const name of Object.values(addressFields)) {
            const field = form.elements.namedItem(name);
            if (field && field.value === generated.get(name)) { field.value = ''; generated.set(name, ''); }
        }
        addressStatus.textContent = 'Punto cambiado. Actualizando dirección; revisa también cualquier referencia escrita.';
        lookupTimer = setTimeout(lookupAddress, 700);
    };
    const searchRural = () => {
        if (!selected) { ruralStatus.textContent = 'Primero marca el punto exacto en el mapa.'; return; }
        clearTimeout(lookupTimer); lookupAddress(ruralQuery.value.trim());
    };
    panel.querySelector('[data-rural-search]')?.addEventListener('click', searchRural);
    ruralQuery?.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchRural(); } });
    panel.querySelector('[data-location-address-retry]')?.addEventListener('click', () => {
        if (!selected) { addressStatus.textContent = 'Primero marca el lugar en el mapa.'; return; }
        clearTimeout(lookupTimer); lookupAddress();
    });
    const paint = () => {
        if (!width) return;
        context.fillStyle = '#e7ede6'; context.fillRect(0, 0, width, height);
        const [cx, cy] = project(...center, zoom);
        const left = cx - width / 2, top = cy - height / 2;
        for (let y = Math.floor(top / 256); y < Math.ceil((top + height) / 256); y++) {
            for (let x = Math.floor(left / 256); x < Math.ceil((left + width) / 256); x++) {
                const count = 2 ** zoom;
                if (y < 0 || y >= count) continue;
                const key = `${zoom}/${((x % count) + count) % count}/${y}`;
                let image = tiles.get(key);
                if (!image) {
                    image = new Image(); tiles.set(key, image);
                    image.referrerPolicy = 'strict-origin-when-cross-origin';
                    image.onload = () => requestAnimationFrame(paint);
                    image.onerror = () => { status.textContent = 'El mapa no pudo cargar. Revisa tu conexión; puedes reintentar o usar tu ubicación actual.'; };
                    image.src = `https://tile.openstreetmap.org/${key}.png`;
                    if (tiles.size > 100) tiles.delete(tiles.keys().next().value);
                }
                if (image.complete && image.naturalWidth) context.drawImage(image, x * 256 - left, y * 256 - top, 256, 256);
            }
        }
        context.strokeStyle = '#173f38'; context.lineWidth = 1;
        context.beginPath(); context.moveTo(width / 2 - 8, height / 2); context.lineTo(width / 2 + 8, height / 2); context.moveTo(width / 2, height / 2 - 8); context.lineTo(width / 2, height / 2 + 8); context.stroke();
        if (selected) {
            const [px, py] = project(...selected, zoom);
            const x = px - left, y = py - top;
            context.fillStyle = '#205b42'; context.strokeStyle = '#fff'; context.lineWidth = 3;
            context.beginPath(); context.arc(x, y - 13, 11, 0, Math.PI * 2); context.fill(); context.stroke();
            context.beginPath(); context.moveTo(x - 6, y - 6); context.lineTo(x, y + 3); context.lineTo(x + 6, y - 6); context.fill();
        }
    };
    const select = (point, message = 'Punto marcado. Comprueba que sea la entrada del lugar antes de guardar.') => {
        selected = point;
        latitude.value = point[0].toFixed(6); longitude.value = point[1].toFixed(6);
        markingPoint = true;
        for (const field of [latitude, longitude]) field.dispatchEvent(new Event('input', {bubbles:true}));
        markingPoint = false;
        status.textContent = message; paint();
        queueLookup();
    };
    const read = () => {
        if (latitude.value.trim() === '' || longitude.value.trim() === '') { selected = null; paint(); return; }
        const a = Number(latitude.value), b = Number(longitude.value);
        selected = Number.isFinite(a) && Number.isFinite(b) && Math.abs(a) <= 90 && Math.abs(b) <= 180 ? [a, b] : null;
        paint();
    };
    read(); if (selected) {
        center = [...selected]; zoom = 16;
        status.textContent = 'Mostramos el punto registrado. Puedes ajustar la entrada exacta en el mapa si cambia el lugar.';
        if (ruralStatus) ruralStatus.textContent = 'Puedes buscar una comunidad cercana al punto registrado sin cambiar sus coordenadas.';
    }
    for (const field of [latitude, longitude]) field.addEventListener('input', () => {
        read();
        if (!markingPoint) {
            if (selected) { center = [...selected]; zoom = 15; paint(); status.textContent = 'Coordenadas introducidas. Revisa en el mapa que correspondan al lugar exacto.'; }
            queueLookup();
        }
    });
    new ResizeObserver(() => {
        const rect = canvas.getBoundingClientRect();
        width = rect.width; height = rect.height;
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
        context.setTransform(ratio, 0, 0, ratio, 0, 0); paint();
    }).observe(canvas);
    canvas.addEventListener('pointerdown', e => { if (e.button !== 0) return; pointer = {x:e.clientX, y:e.clientY, center:project(...center, zoom)}; canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointerup', e => {
        if (!pointer) return;
        const dx = e.clientX - pointer.x, dy = e.clientY - pointer.y;
        if (Math.hypot(dx, dy) > 7) { center = unproject(pointer.center[0] - dx, pointer.center[1] - dy, zoom); paint(); }
        else { const rect = canvas.getBoundingClientRect(); select(unproject(pointer.center[0] + e.clientX - rect.left - width / 2, pointer.center[1] + e.clientY - rect.top - height / 2, zoom)); }
        pointer = null;
    });
    canvas.addEventListener('pointermove', e => {
        if (!pointer) return;
        const dx = e.clientX - pointer.x, dy = e.clientY - pointer.y;
        if (Math.hypot(dx, dy) > 7) { center = unproject(pointer.center[0] - dx, pointer.center[1] - dy, zoom); paint(); }
    });
    canvas.addEventListener('pointercancel', () => { pointer = null; });
    canvas.addEventListener('keydown', e => {
        const deltas = {ArrowLeft:[-90,0], ArrowRight:[90,0], ArrowUp:[0,-90], ArrowDown:[0,90]};
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select([...center]); }
        if (deltas[e.key]) { e.preventDefault(); const p = project(...center, zoom), d = deltas[e.key]; center = unproject(p[0] + d[0], p[1] + d[1], zoom); paint(); }
    });
    for (const button of panel.querySelectorAll('[data-location-zoom]')) button.addEventListener('click', () => { zoom = clamp(zoom + Number(button.dataset.locationZoom), 4, 18); paint(); });
    panel.querySelector('[data-location-town]').addEventListener('change', e => {
        if (!e.target.value) return;
        center = e.target.value.split(',').map(Number); zoom = 14; paint();
        status.textContent = 'Vista aproximada de la localidad. Acerca el mapa y toca el lugar exacto para seleccionarlo.';
    });
    detect.addEventListener('click', () => {
        if (!window.isSecureContext) { status.textContent = 'La ubicación del dispositivo requiere HTTPS o localhost. Puedes marcar el lugar directamente en el mapa.'; return; }
        if (!navigator.geolocation) { status.textContent = 'Este navegador no ofrece ubicación. Marca el lugar en el mapa.'; return; }
        detect.disabled = true; status.textContent = 'Buscando ubicación. Permite el acceso cuando el navegador lo solicite.';
        const success = position => {
            detect.disabled = false; center = [position.coords.latitude, position.coords.longitude]; zoom = 17;
            select(center, `Ubicación obtenida, precisión aproximada ${Math.round(position.coords.accuracy)} m. ${position.coords.accuracy > 150 ? 'La señal es amplia: corrige el punto en el mapa.' : 'Revisa el punto y ajústalo si hace falta.'}`);
        };
        const failure = error => {
            detect.disabled = false;
            status.textContent = error.code === 1 ? 'El navegador o el dispositivo no concedió el permiso. Revisa Ubicación en los permisos del sitio y del sistema. También puedes marcar el lugar en el mapa sin activar GPS.' : 'El dispositivo no pudo localizarte con precisión. Selecciona tu localidad y toca el lugar en el mapa; no necesitas escribir coordenadas.';
        };
        navigator.geolocation.getCurrentPosition(success, error => {
            if (error.code === 1) { failure(error); return; }
            status.textContent = 'Buscando una señal alternativa de ubicación…';
            navigator.geolocation.getCurrentPosition(success, failure, {enableHighAccuracy:false,timeout:10000,maximumAge:60000});
        }, {enableHighAccuracy:true,timeout:12000,maximumAge:30000});
    });
}
