import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/** Events carry identifiers only. Callers reload authorized data from their endpoint. */
export function connectRealtime({ userId, key, threadId, onConversation, onInbox, onState } = {}) {
    let closed = false;
    let echo;
    const notify = (state) => { if (!closed) onState?.(state); };
    if (!key || !Number(userId) || !window.WebSocket) {
        notify('unavailable');
        return { destroy() {}, connected: () => false };
    }

    try {
        echo = new Echo({
            broadcaster: 'reverb',
            Pusher,
            key,
            wsHost: window.location.hostname,
            wsPort: Number(window.location.port || 80),
            wssPort: Number(window.location.port || 443),
            forceTLS: window.location.protocol === 'https:',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authorizer: (channel) => ({
                authorize(socketId, callback) {
                    fetch('/broadcasting/auth', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                    }).then(async (response) => {
                        if (!response.ok) {
                            if ([401, 403, 419].includes(response.status)) notify('unauthorized');
                            throw new Error('No se autorizó la conversación.');
                        }
                        callback(null, await response.json());
                    }).catch((error) => callback(error, null));
                },
            }),
        });

        const connection = echo.connector.pusher.connection;
        connection.bind('state_change', ({ current }) => notify(current));
        connection.bind('error', () => notify('unavailable'));
        const inbox = echo.private(`coordination.user.${Number(userId)}`)
            .listen('.conversation.updated', (event) => { if (!closed) onInbox?.(event); })
            .listen('.inbox.updated', (event) => { if (!closed) onInbox?.(event); });
        inbox.error(() => notify('unavailable'));
        inbox.subscribed(() => {
            notify('connected');
            if (!closed) onInbox?.({ reconnect: true });
        });
        if (Number(threadId)) {
            const thread = echo.private(`coordination.thread.${Number(threadId)}`)
                .listen('.conversation.updated', (event) => { if (!closed) onConversation?.(event); });
            thread.error(() => notify('unavailable'));
            thread.subscribed(() => { if (!closed) onConversation?.({ reconnect: true }); });
        }

        const reconnect = () => {
            if (closed || !navigator.onLine || document.hidden) return;
            echo.connector.pusher.connect();
            onInbox?.({ reconnect: true });
            if (Number(threadId)) onConversation?.({ reconnect: true });
        };
        window.addEventListener('online', reconnect);
        window.addEventListener('pageshow', reconnect);
        document.addEventListener('visibilitychange', reconnect);
        const destroy = () => {
            if (closed) return;
            closed = true;
            window.removeEventListener('online', reconnect);
            window.removeEventListener('pageshow', reconnect);
            document.removeEventListener('visibilitychange', reconnect);
            window.removeEventListener('pagehide', onPageHide);
            echo.disconnect();
        };
        const onPageHide = (event) => {
            if (event.persisted) echo.disconnect();
            else destroy();
        };
        window.addEventListener('pagehide', onPageHide);
        return { destroy, connected: () => !closed && connection.state === 'connected' };
    } catch {
        echo?.disconnect();
        notify('unavailable');
        return { destroy() {}, connected: () => false };
    }
}
