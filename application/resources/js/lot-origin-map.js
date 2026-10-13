const project = (lat, lng, zoom) => {
    const size = 256 * 2 ** zoom;
    const sine = Math.sin(Math.max(-85, Math.min(85, lat)) * Math.PI / 180);
    return [(lng + 180) / 360 * size, (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * size];
};
for (const panel of document.querySelectorAll('[data-lot-origin-map]')) {
    let points;
    try { points = JSON.parse(panel.dataset.points).filter(p => Number.isFinite(p.latitude) && Number.isFinite(p.longitude)); } catch { continue; }
    if (!points.length) continue;
    const canvas = panel.querySelector('[data-origin-canvas]'), context = canvas.getContext('2d');
    const status = panel.querySelector('[data-origin-status]');
    const buttons = [...panel.querySelectorAll('[data-origin-point]')];
    const tiles = new Map();
    let width = 0, height = 380, zoom = 12, center = [0, 0], selected = -1, drag = null, frame;
    const repaint = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(draw); };
    function fit() {
        for (zoom = 16; zoom >= 5; zoom--) {
            const p = points.map(p => project(p.latitude, p.longitude, zoom));
            const xs = p.map(p => p[0]), ys = p.map(p => p[1]);
            const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
            center = [(minX + maxX) / 2, (minY + maxY) / 2];
            if ((maxX - minX < width - 110 && maxY - minY < height - 110) || zoom === 5) break;
        }
        repaint();
    }
    function draw() {
        context.fillStyle = '#e9eddf'; context.fillRect(0, 0, width, height);
        const left = center[0] - width / 2, top = center[1] - height / 2, used = new Set();
        for (let x = Math.floor(left / 256); x <= Math.floor((left + width) / 256); x++) {
            for (let y = Math.floor(top / 256); y <= Math.floor((top + height) / 256); y++) {
                if (x < 0 || y < 0 || x >= 2 ** zoom || y >= 2 ** zoom) continue;
                const key = `${zoom}/${x}/${y}`; used.add(key);
                let tile = tiles.get(key);
                if (!tile) {
                    tile = new Image(); tile.referrerPolicy = 'strict-origin-when-cross-origin';
                    tile.onload = repaint; tile.onerror = () => { status.textContent = 'El fondo del mapa no está disponible. Los puntos y las direcciones del lote siguen visibles.'; };
                    tile.src = `https://tile.openstreetmap.org/${key}.png`; tiles.set(key, tile);
                }
                if (tile.complete && tile.naturalWidth) context.drawImage(tile, x * 256 - left, y * 256 - top, 256, 256);
            }
        }
        if (tiles.size > 100) for (const key of tiles.keys()) if (!used.has(key)) tiles.delete(key);
        points.forEach((point, index) => {
            const [px, py] = project(point.latitude, point.longitude, zoom), x = px - left, y = py - top;
            context.beginPath(); context.arc(x, y, selected === index ? 20 : 16, 0, Math.PI * 2);
            context.fillStyle = selected === index ? '#bb6d20' : '#174d38'; context.fill();
            context.lineWidth = 3; context.strokeStyle = '#fff'; context.stroke();
            context.fillStyle = '#fff'; context.font = 'bold 13px system-ui'; context.textAlign = 'center'; context.textBaseline = 'middle'; context.fillText(String(index + 1), x, y);
        });
    }
    function changeZoom(delta) {
        const next = Math.max(5, Math.min(18, zoom + delta)), ratio = 2 ** (next - zoom);
        center = center.map(value => value * ratio); zoom = next; repaint();
    }
    for (const button of panel.querySelectorAll('[data-origin-zoom]')) button.addEventListener('click', () => changeZoom(Number(button.dataset.originZoom)));
    panel.querySelector('[data-origin-fit]').addEventListener('click', () => { selected = -1; buttons.forEach(b => b.setAttribute('aria-pressed', 'false')); fit(); status.textContent = 'Todos los puntos del lote.'; });
    buttons.forEach((button, index) => button.addEventListener('click', () => {
        selected = index; zoom = 15; center = project(points[index].latitude, points[index].longitude, zoom);
        buttons.forEach((b, i) => b.setAttribute('aria-pressed', String(i === index)));
        status.textContent = `${points[index].label} · Oferta #${points[index].offer_id}. Revisa la dirección en la composición del lote.`; repaint();
    }));
    canvas.addEventListener('pointerdown', e => { drag = [e.clientX, e.clientY, ...center]; canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointermove', e => { if (!drag) return; center = [drag[2] - e.clientX + drag[0], drag[3] - e.clientY + drag[1]]; repaint(); });
    canvas.addEventListener('pointerup', () => { drag = null; });
    canvas.addEventListener('pointercancel', () => { drag = null; });
    canvas.addEventListener('keydown', e => {
        const movement = {ArrowLeft:[-70,0], ArrowRight:[70,0], ArrowUp:[0,-70], ArrowDown:[0,70]}[e.key];
        if (movement) { e.preventDefault(); center = center.map((v,i) => v + movement[i]); repaint(); }
        if (e.key === '+' || e.key === '-') { e.preventDefault(); changeZoom(e.key === '+' ? 1 : -1); }
    });
    new ResizeObserver(() => {
        const first = !width; width = canvas.clientWidth;
        const ratio = Math.min(window.devicePixelRatio || 1, 2); canvas.width = width * ratio; canvas.height = height * ratio; context.setTransform(ratio, 0, 0, ratio, 0, 0);
        if (first) fit(); else repaint();
    }).observe(canvas);
}
