import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/email-verification.js', import.meta.url), 'utf8');

test('verification waiting page retries after a network failure and continues after verification', async () => {
    let interval;
    let calls = 0;
    const redirects = [];
    const label = { textContent: '' };
    runInNewContext(source, {
        document: {
            hidden: false, addEventListener() {},
            querySelector() { return { dataset: { statusUrl: '/email/verification-status', loginUrl: '/' }, querySelector() { return label; } }; },
        },
        window: {
            setTimeout() { return 1; }, clearTimeout() {}, addEventListener() {},
            setInterval(callback) { interval = callback; }, location: { assign(url) { redirects.push(url); } },
        },
        AbortController,
        async fetch() {
            calls++;
            if (calls === 1) throw new Error('Network unavailable');
            return { ok: true, status: 200, redirected: false, async json() { return { verified: true, redirect_url: '/staff/dashboard' }; } };
        },
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(redirects, []);
    await interval();
    assert.equal(calls, 2);
    assert.deepEqual(redirects, ['/staff/dashboard']);
    assert.equal(label.textContent, 'Email verified. Continuing…');
});
