const paymentSummary = document.querySelector('[data-payment-live]');
if (paymentSummary) {
    let busy = false, stopped = false, timer, failures = 0;
    const connection = paymentSummary.querySelector('[data-payment-connection]');
    const refresh = async () => {
        clearTimeout(timer);
        if (stopped) return;
        if (busy || document.hidden || !navigator.onLine) { timer = setTimeout(refresh, 15000); return; }
        busy = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(paymentSummary.dataset.paymentLive, {headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if ([401, 403].includes(response.status)) { stopped = true; connection.textContent = 'Inicia sesión para actualizar el estado'; return; }
            if (!response.ok) throw new Error('Unavailable');
            const data = await response.json();
            failures = 0;
            for (const field of ['headline', 'hint', 'total', 'received', 'balance']) {
                const target = paymentSummary.querySelector(`[data-payment-${field}]`);
                if (target && target.textContent !== data[field]) target.textContent = data[field];
            }
            paymentSummary.querySelector('[data-payment-pending]').hidden = !data.pending_count;
            paymentSummary.querySelector('[data-payment-pending-amount]').textContent = data.pending;
            document.querySelectorAll('[data-payment-entry]').forEach(node => {
                node.hidden = data.closed || (data.settled && node.dataset.allowOverpayment !== 'true');
            });
            for (const payment of data.payments) {
                document.querySelectorAll(`[data-payment-state="${Number(payment.id)}"]`).forEach(node => {
                    if (node.textContent.trim() !== payment.label) node.textContent = payment.label;
                });
                if (!['pending', 'proof_uploaded', 'under_review'].includes(payment.status)) {
                    document.querySelectorAll(`[data-payment-review="${Number(payment.id)}"], [data-payment-proof="${Number(payment.id)}"]`).forEach(node => { node.hidden = true; });
                }
                document.querySelectorAll(`[data-payment-reopen="${Number(payment.id)}"]`).forEach(node => { node.hidden = payment.status !== 'rejected'; });
            }
            connection.textContent = 'Estado actualizado';
        } catch {
            failures = Math.min(4, failures + 1);
            connection.textContent = 'Reconectando · conservamos el último estado';
        } finally {
            clearTimeout(timeout); busy = false;
            if (!stopped) timer = setTimeout(refresh, 15000 * (failures + 1));
        }
    };
    window.addEventListener('agrolazo:inbox', refresh);
    window.addEventListener('online', refresh);
    document.addEventListener('visibilitychange', refresh);
    refresh();
}
