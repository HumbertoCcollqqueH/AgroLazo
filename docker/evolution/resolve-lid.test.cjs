'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { patchReceiveSource } = require('./resolve-lid.cjs');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const receive = new AsyncFunction('msg', 'signalRepository', patchReceiveSource('const alt = msg.key.participantAlt || msg.key.remoteJidAlt; return msg.key;'));

test('maps an authenticated LID to its phone, including device suffixes', async () => {
    const key = await receive({ key: { fromMe: false, remoteJid: '123456789@lid' } }, { lidMapping: { getPNForLID: async () => '51900000801:0@s.whatsapp.net' } });
    assert.equal(key.remoteJidAlt, '51900000801@s.whatsapp.net');
    assert.equal(key.remoteJid, '123456789@lid');
});
test('never treats unresolved LIDs, groups or invalid mapping values as phone numbers', async () => {
    for (const value of [null, '123456789@lid', '12345@g.us', '123@s.whatsapp.net', '51900000801@s.whatsapp.net.evil']) {
        const key = await receive({ key: { fromMe: false, remoteJid: '123456789@lid' } }, { lidMapping: { getPNForLID: async () => value } });
        assert.equal(key.remoteJidAlt, undefined);
    }
});
test('preserves existing aliases and ignores own messages and groups', async () => {
    for (const key of [{ fromMe: true, remoteJid: '123456789@lid' }, { fromMe: false, remoteJid: '123456789@g.us' }, { fromMe: false, remoteJid: '123456789@lid', remoteJidAlt: '51900000802@s.whatsapp.net' }]) {
        const original = { ...key };
        let lookups = 0;
        await receive({ key }, { lidMapping: { getPNForLID: async () => { lookups++; return null; } } });
        assert.equal(lookups, 0);
        assert.deepEqual(key, original);
    }
});
test('lookup failures preserve receipt without inventing an identity', async () => {
    const key = await receive({ key: { fromMe: false, remoteJid: '123456789@lid' } }, { lidMapping: { getPNForLID: async () => { throw new Error('unavailable'); } } });
    assert.equal(key.remoteJidAlt, undefined);
});
test('rejects incompatible provider source instead of applying an ambiguous patch', () => {
    assert.throws(() => patchReceiveSource('changed source'), /Unsupported/);
    assert.throws(() => patchReceiveSource('const alt = msg.key.participantAlt || msg.key.remoteJidAlt;'.repeat(2)), /Unsupported/);
});
