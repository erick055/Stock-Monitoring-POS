import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/session-activity.js', import.meta.url), 'utf8')
    .replace('export function backgroundReload', 'function backgroundReload');

test('old device returns to the correct login URL after session expiry but stays open during network failure', async () => {
    let check;
    let requestedUrl;
    let calls = 0;
    const redirects = [];
    runInNewContext(source, {
        URL, AbortController,
        document: {
            hidden: false, addEventListener() {},
            querySelector(selector) { return { content: selector.includes('csrf-token') ? 'csrf' : 'https://example.com/stock-pos/public/session/activity' }; },
        },
        window: {
            location: { href: 'https://example.com/stock-pos/public/admin/dashboard', replace(url) { redirects.push(url); } },
            history: { replaceState() {} }, addEventListener() {},
            setInterval(callback, delay) { assert.equal(delay, 10000); check = callback; },
            setTimeout() { return 1; }, clearTimeout() {},
        },
        async fetch(url) {
            requestedUrl = url;
            if (++calls === 1) throw new Error('Disconnected');
            return { status: 401, redirected: false };
        },
    });
    await check();
    assert.deepEqual(redirects, []);
    await check();
    assert.equal(requestedUrl, 'https://example.com/stock-pos/public/session/status');
    assert.deepEqual(redirects, ['https://example.com/stock-pos/public/']);
});
