if (document.documentElement.hasAttribute('data-local-performance')) {
    window.addEventListener('load', () => {
        setTimeout(() => {
            const timing = performance.getEntriesByType('navigation')[0];
            if (timing) {
                document.documentElement.dataset.pageTiming = JSON.stringify({
                    ttfb: Math.round(timing.responseStart),
                    dom: Math.round(timing.domContentLoadedEventEnd),
                    load: Math.round(timing.loadEventEnd),
                    server: timing.serverTiming.map(item => ({ name: item.name, ms: item.duration })),
                    connect: Math.round(timing.connectEnd - timing.connectStart),
                });
            }
        }, 0);
    }, { once: true });
}

function scaledDecimal(value, scale) {
    const match = String(value).match(new RegExp('^([0-9]{1,15})(?:\\.([0-9]{1,' + scale + '}))?$'));
    if (!match) return null;
    return BigInt(match[1]) * (10n ** BigInt(scale)) + BigInt((match[2] ?? '').padEnd(scale, '0'));
}

const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
const scrollBehavior = () => motionPreference.matches ? 'instant' : 'smooth';
const operationsToggle = document.querySelector('[data-operations-toggle]');
if (operationsToggle) {
    const sidebar = document.querySelector('[data-operations-sidebar]');
    const mobileOperations = window.matchMedia('(max-width: 940px)');
    operationsToggle.hidden = false;
    function closeOperationsMenu() {
        sidebar.dataset.collapsed = String(mobileOperations.matches);
        operationsToggle.setAttribute('aria-expanded', 'false');
    }
    closeOperationsMenu();
    mobileOperations.addEventListener('change', closeOperationsMenu);
    operationsToggle.addEventListener('click', () => {
        const open = operationsToggle.getAttribute('aria-expanded') !== 'true';
        operationsToggle.setAttribute('aria-expanded', String(open));
        sidebar.dataset.collapsed = String(!open);
    });
    document.addEventListener('click', event => {
        if (mobileOperations.matches && !sidebar.contains(event.target) && !operationsToggle.contains(event.target)) closeOperationsMenu();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && operationsToggle.getAttribute('aria-expanded') === 'true') {
            closeOperationsMenu();
            operationsToggle.focus();
        }
    });
}

const operationsSearch = document.getElementById('operations-module-search');
const operationsSearchTrigger = document.querySelector('[data-operations-search-open]');
if (operationsSearch && operationsSearchTrigger && typeof operationsSearch.showModal === 'function') {
    const query = operationsSearch.querySelector('[data-operations-module-query]');
    const links = [...operationsSearch.querySelectorAll('[data-operations-module]')];
    const empty = operationsSearch.querySelector('[data-operations-search-empty]');
    const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    function filterModules() {
        const term = normalize(query.value);
        links.forEach(link => { link.hidden = !normalize(link.textContent).includes(term); });
        empty.hidden = links.some(link => !link.hidden);
    }
    function openModuleSearch() {
        if (operationsSearch.open) return;
        query.value = '';
        filterModules();
        operationsSearch.showModal();
        query.focus();
    }
    operationsSearchTrigger.hidden = false;
    operationsSearchTrigger.addEventListener('click', openModuleSearch);
    query.addEventListener('input', filterModules);
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openModuleSearch();
        }
    });
    operationsSearch.addEventListener('click', event => {
        if (event.target === operationsSearch) {
            const bounds = operationsSearch.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) operationsSearch.close();
        }
    });
}

for (const createMenu of document.querySelectorAll('.op-create-menu')) {
    document.addEventListener('click', event => { if (!createMenu.contains(event.target)) createMenu.open = false; });
    createMenu.addEventListener('keydown', event => {
        if (event.key === 'Escape') { createMenu.open = false; createMenu.querySelector('summary').focus(); }
    });
}

const menuToggle = document.querySelector('[data-menu-toggle]');
if (menuToggle) {
    const navigation = document.getElementById(menuToggle.getAttribute('aria-controls'));
    const mobileNavigation = window.matchMedia('(max-width: 940px)');
    menuToggle.hidden = false;
    function closeMenu() {
        navigation.dataset.collapsed = String(mobileNavigation.matches);
        menuToggle.setAttribute('aria-expanded', 'false');
    }
    closeMenu();
    mobileNavigation.addEventListener('change', closeMenu);
    menuToggle.addEventListener('click', () => {
        const open = menuToggle.getAttribute('aria-expanded') !== 'true';
        menuToggle.setAttribute('aria-expanded', String(open));
        navigation.dataset.collapsed = String(!open);
    });
    navigation.addEventListener('click', event => {
        if (event.target.closest('a') && mobileNavigation.matches) closeMenu();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
            closeMenu();
            menuToggle.focus();
        }
    });
}

for (const carousel of document.querySelectorAll('[data-carousel]')) {
    const track = carousel.querySelector('[data-carousel-track]');
    const slides = [...carousel.querySelectorAll('[data-carousel-slide]')];
    const dots = [...carousel.querySelectorAll('[data-carousel-to]')];
    let active = 0;
    carousel.querySelector('[data-carousel-controls]').hidden = false;
    function updateSlide(index) {
        active = index;
        dots.forEach((dot, i) => dot.setAttribute('aria-current', String(i === active)));
        slides.forEach((slide, i) => {
            slide.inert = i !== active;
            slide.setAttribute('aria-hidden', String(i !== active));
        });
    }
    function goToSlide(index) {
        const next = (index + slides.length) % slides.length;
        track.scrollTo({ left: next * track.clientWidth, behavior: scrollBehavior() });
    }
    carousel.addEventListener('click', event => {
        const dot = event.target.closest('[data-carousel-to]');
        const arrow = event.target.closest('[data-carousel-direction]');
        if (dot) goToSlide(Number(dot.dataset.carouselTo));
        if (arrow) goToSlide(active + Number(arrow.dataset.carouselDirection));
    });
    track.addEventListener('scroll', () => {
        const index = Math.round(track.scrollLeft / track.clientWidth);
        if (index !== active) updateSlide(index);
    }, { passive: true });
    carousel.addEventListener('keydown', event => {
        if (event.target.closest('[data-carousel-controls]') && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
            event.preventDefault();
            goToSlide(active + (event.key === 'ArrowRight' ? 1 : -1));
        }
    });
    updateSlide(0);
}

const categoryRail = document.querySelector('[data-category-rail]');
if (categoryRail) {
    document.querySelector('[data-rail-controls]').hidden = false;
    for (const arrow of document.querySelectorAll('[data-rail-direction]')) {
        arrow.addEventListener('click', () => categoryRail.scrollBy({ left: Number(arrow.dataset.railDirection) * categoryRail.clientWidth * .75, behavior: scrollBehavior() }));
    }
}

const favoriteKey = 'agroconecta.favorite-lots.v1';
let favorites = new Set();
try {
    const saved = JSON.parse(localStorage.getItem(favoriteKey) ?? '[]');
    if (Array.isArray(saved)) favorites = new Set(saved.filter(code => typeof code === 'string').slice(-250));
} catch { /* Favorites remain available for the current page if storage is unavailable. */ }
const savedToggle = document.querySelector('[data-saved-toggle]');
let toastTimer;
function showStorefrontMessage(message) {
    const toast = document.querySelector('[data-storefront-toast]');
    if (!toast) return;
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.hidden = false;
    toastTimer = setTimeout(() => { toast.hidden = true; }, 4500);
}
function updateFavorites() {
    for (const button of document.querySelectorAll('[data-favorite]')) {
        const saved = favorites.has(button.dataset.favorite);
        button.hidden = false;
        button.setAttribute('aria-pressed', String(saved));
        button.setAttribute('aria-label', `${saved ? 'Quitar' : 'Guardar'} ${button.dataset.productName} ${saved ? 'de' : 'en'} favoritos`);
    }
    const onlySaved = savedToggle?.getAttribute('aria-pressed') === 'true';
    const cards = [...document.querySelectorAll('[data-catalog-grid] [data-product-card]')];
    for (const card of cards) card.hidden = onlySaved && !favorites.has(card.dataset.productCode);
    const empty = document.querySelector('[data-empty-favorites]');
    if (empty) empty.hidden = !onlySaved || cards.some(card => !card.hidden);
    const story = document.querySelector('[data-catalog-grid] .ac-catalog-story');
    if (story) story.hidden = onlySaved;
}
document.addEventListener('click', event => {
    const button = event.target.closest('[data-favorite]');
    if (!button) return;
    const code = button.dataset.favorite;
    const isSaved = favorites.has(code);
    if (isSaved) favorites.delete(code);
    else favorites.add(code);
    favorites = new Set([...favorites].slice(-250));
    let persisted = true;
    try { localStorage.setItem(favoriteKey, JSON.stringify([...favorites])); } catch { persisted = false; }
    updateFavorites();
    if (savedToggle?.getAttribute('aria-pressed') === 'true' && isSaved) savedToggle.focus();
    showStorefrontMessage(isSaved ? 'Producto quitado de tus favoritos.' : persisted ? 'Producto guardado en este navegador.' : 'Favorito guardado durante esta visita; el navegador no permite conservarlo.');
});
if (savedToggle) {
    savedToggle.hidden = false;
    savedToggle.addEventListener('click', () => {
        savedToggle.setAttribute('aria-pressed', String(savedToggle.getAttribute('aria-pressed') !== 'true'));
        updateFavorites();
    });
}
updateFavorites();

for (const panel of document.querySelectorAll('[data-whatsapp-connection]')) {
    const state = panel.querySelector('[data-connection-state]');
    const qr = panel.querySelector('[data-connection-qr]');
    const hint = panel.querySelector('[data-connection-hint]');
    const labels = { not_configured: 'Sin conexión configurada', preparing: 'Preparando conexión…', awaiting_scan: 'Esperando escaneo', connected: 'WhatsApp conectado', configured: 'Meta configurado; verifica la recepción con tu cuenta', disconnected: 'WhatsApp desconectado', error: 'No se pudo preparar la conexión' };
    let remaining = 40;
    let timer;
    let qrTimer;
    let loading = false;
    async function refresh() {
        if (loading || document.hidden) return;
        loading = true;
        clearTimeout(timer);
        try {
            const response = await fetch(panel.dataset.statusUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) throw new Error('status');
            const data = await response.json();
            clearTimeout(qrTimer);
            state.textContent = labels[data.status] ?? 'Estado no disponible';
            qr.hidden = !data.qr;
            if (data.qr?.startsWith('data:image/png;base64,')) qr.src = data.qr;
            else qr.removeAttribute('src');
            hint.textContent = data.qr ? 'WhatsApp → Dispositivos vinculados → Vincular un dispositivo. Escanea este QR antes de que caduque.' : data.status === 'awaiting_scan' ? 'El QR se está generando o ha caducado. Si no aparece, pulsa «Revisar conexión / renovar QR».' : data.status === 'error' ? 'Comprueba que Evolution y el trabajador de colas estén activos y que las credenciales estén configuradas. Después vuelve a preparar la conexión.' : data.status === 'connected' ? (data.automation_enabled ? 'Respuestas automáticas activadas para los productores registrados que escriban a este número.' : 'El número está conectado, pero las respuestas están pausadas. Actívalas en el paso 2.') : panel.dataset.integration === 'WHATSAPP-BAILEYS' ? 'Espera la generación del QR. Si la conexión terminó sin vincular el teléfono, pulsa «Revisar conexión / renovar QR».' : 'La API oficial requiere las credenciales de Meta. Para vincular desde el teléfono, selecciona QR antes de crear la conexión.';
            if (data.qr) qrTimer = setTimeout(() => { qr.hidden = true; qr.removeAttribute('src'); hint.textContent = 'El QR ha caducado. Renueva la conexión para obtener otro.'; }, 30000);
            if (['preparing', 'awaiting_scan'].includes(data.status) && --remaining > 0) timer = setTimeout(refresh, 3000);
        } catch {
            state.textContent = 'No se pudo consultar el estado. Actualiza la página si tu sesión terminó.';
            qr.hidden = true;
            qr.removeAttribute('src');
        } finally { loading = false; }
    }
    panel.querySelector('[data-connection-refresh]').addEventListener('click', () => { remaining = 40; refresh(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) { qr.hidden = true; qr.removeAttribute('src'); } else refresh(); });
    refresh();
}

function decimalText(value, scale) {
    const unit = 10n ** BigInt(scale);
    return (value / unit).toString() + '.' + (value % unit).toString().padStart(scale, '0');
}

for (const panel of document.querySelectorAll('[data-lot-estimator]')) {
    const input = panel.querySelector('[data-estimate-quantity]');
    const output = panel.querySelector('[data-estimate-output]');
    const error = panel.querySelector('[data-estimate-error]');
    const available = scaledDecimal(panel.dataset.available, 3);
    const minimumPrice = scaledDecimal(panel.dataset.priceMin, 6);
    const maximumPrice = scaledDecimal(panel.dataset.priceMax, 6);
    const counted = panel.dataset.unitDimension === 'count';
    const minimumQuantity = scaledDecimal(input.min, 3) ?? (counted ? 1000n : 1n);
    const quoteLink = panel.querySelector('[data-quote-link]');

    function updateEstimate() {
        const quantity = scaledDecimal(input.value, 3);
        error.textContent = '';
        if (quoteLink) quoteLink.href = quoteLink.dataset.quoteUrl;
        if (input.value === '') {
            output.textContent = 'Indica una cantidad';
            return;
        }
        if (quantity === null || quantity < minimumQuantity || quantity > available || (counted && quantity % 1000n !== 0n)) {
            output.textContent = 'Revisa la cantidad';
            error.textContent = quantity !== null && quantity < minimumQuantity
                ? `La compra mínima es ${input.min}. Revisa la cantidad indicada.`
                : counted
                ? 'Indica unidades enteras positivas dentro de la disponibilidad.'
                : 'Indica una cantidad positiva, con hasta tres decimales, dentro de la disponibilidad.';
            return;
        }
        if (quoteLink) {
            const url = new URL(quoteLink.dataset.quoteUrl, window.location.href);
            url.searchParams.set('quantity', input.value);
            quoteLink.href = url.href;
        }
        const minimumCents = (quantity * minimumPrice + 5000000n) / 10000000n;
        const maximumCents = (quantity * maximumPrice + 5000000n) / 10000000n;
        output.textContent = 'S/ ' + decimalText(minimumCents, 2) + ' – ' + decimalText(maximumCents, 2);
    }

    input.addEventListener('input', updateEstimate);
    for (const button of panel.querySelectorAll('[data-quantity-step]')) {
        button.addEventListener('click', () => {
            const current = scaledDecimal(input.value, 3) ?? 0n;
            const next = current + BigInt(button.dataset.quantityStep) * 1000n;
            const floor = minimumQuantity;
            input.value = decimalText(next < floor ? floor : next > available ? available : next, 3);
            updateEstimate();
        });
    }
    updateEstimate();
}

for (const button of document.querySelectorAll('[data-quote-quantity]')) {
    button.addEventListener('click', () => {
        const input = button.closest('form')?.querySelector('[name="quantity"]');
        if (!input) return;
        input.value = button.dataset.quoteQuantity;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    });
}


import './location-picker.js';
import './lot-origin-map.js';
