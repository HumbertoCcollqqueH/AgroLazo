import { connectRealtime } from './realtime';
document.querySelectorAll('[data-refresh-page]').forEach(button => button.addEventListener('click', () => window.location.reload()));
const updates = document.querySelector('[data-platform-updates]');
if (updates) {
    let busy = false, message = null, timer, failures = 0;
    const account = updates.dataset.platformAccount;
    const native = window.AgroLazoAlerts;
    const enable = document.querySelector('[data-enable-notifications]');
    const status = document.querySelector('[data-notification-status]');
    const storageKey = 'agrolazo.notice.' + account;
    const supported = 'Notification' in window && window.isSecureContext;
    const permissionText = () => {
        if (!status) return;
        if (native) status.textContent = 'Activa los avisos de Android. En segundo plano pueden demorarse según la batería y la conexión. Al tocar un aviso vuelves a AgroLazo.';
        else if (supported) status.textContent = Notification.permission === 'granted' ? 'Avisos del navegador activados mientras AgroLazo esté abierto. También quedan guardados aquí.' : Notification.permission === 'denied' ? 'El navegador tiene bloqueados los avisos. Puedes habilitarlos en los permisos del sitio; tus novedades siguen disponibles aquí.' : 'Puedes activar avisos del navegador mientras mantienes AgroLazo abierto.';
        else status.textContent = 'Tus novedades aparecen aquí. Este navegador no permite avisos del dispositivo.';
    };
    if (enable && (native || supported)) {
        enable.hidden = false;
        enable.addEventListener('click', async () => {
            if (native) { native.enable(); return; }
            await Notification.requestPermission(); permissionText();
        });
    }
    permissionText();
    updates.querySelector('[data-dismiss-notices]')?.addEventListener('click', () => { updates.hidden = true; });
    const check = async () => {
        clearTimeout(timer);
        if (document.hidden || busy || !navigator.onLine) { timer = setTimeout(check, 20000); return; }
        busy = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(updates.dataset.platformUpdates, {headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
            if (response.status === 401 || response.status === 403) { native?.clear(); return; }
            if (!response.ok) throw new Error('Temporarily unavailable');
            const data = await response.json();
            if (String(data.account) !== account) return;
            failures = 0;
            document.querySelectorAll('[data-unread-count]').forEach(badge => { badge.textContent = data.unread ? String(data.unread) : ''; badge.setAttribute('aria-label', data.unread + ' avisos sin leer'); });
            const revision = String(data.revision) + ':' + (data.notice || '');
            const changed = (updates.dataset.platformRevision !== undefined && String(data.revision) !== updates.dataset.platformRevision) || (data.notice && data.notice !== updates.dataset.platformNotice) || (message !== null && data.message !== message);
            const body = data.notices?.[0]?.body || 'Tienes una nueva respuesta en Conversaciones.';
            if (changed) { updates.querySelector('[data-platform-summary]').textContent = body; updates.hidden = false; }
            if (native) native.sync(JSON.stringify(data));
            else if (changed && data.unread && supported && Notification.permission === 'granted') {
                let seen = null;
                try { seen = localStorage.getItem(storageKey); } catch {}
                if (seen !== revision) {
                    try { localStorage.setItem(storageKey, revision); } catch {}
                    const notice = new Notification('AgroLazo · ' + data.unread + ' avisos sin leer', {body, tag: 'agrolazo-' + account, icon: '/favicon.svg'});
                    notice.onclick = () => { window.focus(); window.location.assign('/notifications'); notice.close(); };
                }
            }
            updates.dataset.platformNotice = data.notice || '';
            updates.dataset.platformRevision = String(data.revision);
            message = data.message;
        } catch { failures = Math.min(failures + 1, 4); }
        finally { clearTimeout(timeout); busy = false; timer = setTimeout(check, Math.min(120000, 20000 * (failures + 1)) + Math.random() * 2000); }
    };
    document.addEventListener('visibilitychange', check);
    window.addEventListener('online', check);
    window.addEventListener('agrolazo:inbox', check);
    if (!document.querySelector('[data-chat], [data-conversation-list]')) {
        connectRealtime({userId: Number(account), key: updates.dataset.realtimeKey, onInbox: () => window.dispatchEvent(new Event('agrolazo:inbox'))});
    }
    check();
}
const producerOffer = document.querySelector('[data-producer-offer]');
if (producerOffer) {
    const from = producerOffer.elements.available_from;
    const until = producerOffer.elements.available_until;
    from.addEventListener('change', () => { until.min = from.value; });
    producerOffer.querySelectorAll('[data-offer-days]').forEach(button => button.addEventListener('click', () => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(from.value)) return;
        const date = new Date(from.value + 'T12:00:00Z');
        date.setUTCDate(date.getUTCDate() + Number(button.dataset.offerDays));
        until.value = date.toISOString().slice(0, 10);
    }));
}


