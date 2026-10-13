'use strict';

// Evolution 2.3.7 writes messageRaw directly to console.log, outside LOG_LEVEL.
// Preserve technical diagnostics while removing message/session objects and QR data.
const sensitiveKeys = /^(key|message|messages|messageType|conversation|extendedTextMessage|remoteJid|pushName|qrcode|base64|pairingCode|creds|auth|authorization|apikey|token|password|headers|config|request|response|packet|content)$/i;
function sanitize(value, seen = new WeakSet(), depth = 0) {
    if (typeof value === 'string') {
        if (/data:image\/|[▄▀█]{8}|"(?:conversation|base64|creds|apikey|authorization)"\s*:/i.test(value)) return '[sensitive provider payload omitted]';
        return value.replace(/[\d:+-]+@(?:s\.whatsapp\.net|lid|g\.us)/g, '[contact omitted]');
    }
    if (value instanceof Error) return { name: value.name, code: value.code ?? 'provider_error' };
    if (!value || typeof value !== 'object') return value;
    if (depth >= 5 || seen.has(value)) return '[object omitted]';
    seen.add(value);
    if (Array.isArray(value)) return value.slice(0, 10).map(item => sanitize(item, seen, depth + 1));
    if (Object.keys(value).some(key => sensitiveKeys.test(key))) return '[sensitive provider object omitted]';
    return Object.fromEntries(Object.entries(value).slice(0, 20).map(([key, item]) => [key, sanitize(item, seen, depth + 1)]));
}
for (const method of ['log', 'info', 'debug', 'warn', 'error']) {
    const original = console[method].bind(console);
    console[method] = (...args) => original(...args.map(value => sanitize(value)));
}
