'use strict';

// Compatibility for the pinned Evolution 2.3.7 / Baileys 7.0.0-rc.9 image.
// WhatsApp sometimes omits sender_pn; use its authenticated LID mapping only.
const marker = 'const alt = msg.key.participantAlt || msg.key.remoteJidAlt;';
const enrichment = `
        if (msg.key.fromMe === false && /^\\d+@lid$/.test(msg.key.remoteJid || '') && !msg.key.remoteJidAlt) {
            try {
                const mappedPhone = await signalRepository.lidMapping.getPNForLID(msg.key.remoteJid);
                const phoneMatch = /^([1-9][0-9]{7,14})(?::[0-9]+)?@s\\.whatsapp\\.net$/.exec(mappedPhone || '');
                if (phoneMatch) msg.key.remoteJidAlt = phoneMatch[1] + '@s.whatsapp.net';
            } catch {
                // An unresolved sender stays unresolved; never guess a phone from a LID.
            }
        }
        ${marker}`;

function patchReceiveSource(source) {
    const text = Buffer.isBuffer(source) ? source.toString('utf8') : source;
    if (typeof text !== 'string' || text.split(marker).length !== 2) {
        throw new Error('Unsupported Baileys receiver: review LID compatibility before upgrading.');
    }
    return text.replace(marker, enrichment);
}

if (process.env.AGROLAZO_LID_COMPAT === '1') {
    require('node:module').registerHooks({
        load(url, context, nextLoad) {
            const loaded = nextLoad(url, context);
            if (url.endsWith('/node_modules/baileys/lib/Socket/messages-recv.js')) {
                return { ...loaded, source: patchReceiveSource(loaded.source) };
            }
            return loaded;
        },
    });
}

module.exports = { patchReceiveSource };
