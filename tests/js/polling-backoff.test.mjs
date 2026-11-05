import { money, plural } from '../../resources/js/formatters.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

for (const file of ['inventory-live-reload', 'email-verification']) {
    test(`${file} honors Retry-After without navigating away or repeatedly polling`, async () => {
        const source = readFileSync(new URL(`../../resources/js/${file}.js`, import.meta.url), 'utf8')
            .replace(/import[^;]+;/g, '').replace('export function backgroundReload', 'function backgroundReload');
        let poll;
        let calls = 0;
        let now = 10000;
        const redirects = [];
        const page = { dataset: { inventoryVersion: 'v1', liveInventoryUrl: '/inventory/live', statusUrl: '/email/verification-status', loginUrl: '/' } };
        runInNewContext(source, {
            Date: class extends Date { static now() { return now; } }, URL, AbortController,
            money, plural, HTMLElement: class {},
            document: {
                hidden: false, addEventListener() {}, body: { classList: { contains() { return false; } } }, querySelectorAll() { return []; },
                querySelector(selector) {
                    if (selector.startsWith('meta')) return { content: selector.includes('csrf-token') ? 'csrf' : 'https://example.com/session/activity' };
                    return ['[data-live-inventory-page]', '[data-email-verification]'].includes(selector) ? page : null;
                },
            },
            window: {
                location: { href: 'https://example.com/admin/inventory', assign(url) { redirects.push(url); }, replace(url) { redirects.push(url); } },
                history: { replaceState() {} }, addEventListener() {},
                setTimeout() { return 1; }, clearTimeout() {}, setInterval(callback) { poll = callback; },
            },
            async fetch() {
                calls++;
                return calls === 1
                    ? { status: 429, ok: false, headers: { get() { return '30'; } } }
                    : { status: 200, ok: true, redirected: false, async json() { return { version: 'v1', verified: false }; } };
            },
        });
        await new Promise(resolve => setImmediate(resolve));
        if (!calls) await poll();
        assert.equal(calls, 1);
        await poll();
        await poll();
        assert.equal(calls, 1);
        now += 31000;
        await poll();
        assert.equal(calls, 2);
        assert.deepEqual(redirects, []);
    });
}
