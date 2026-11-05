import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/session-activity.js', import.meta.url), 'utf8');
const epoch = 1700000000000;

function tracker({ storage = new Map(), clock = { now: epoch }, response, dialogHost } = {}) {
    let tick;
    let lastActivity = epoch / 1000;
    let calls = [];
    const redirects = [];
    const events = {};
    const warnings = [];
    const snapshot = () => ({ authenticated: true, idle_timeout_seconds: 3600, last_activity_at: lastActivity, expires_at: lastActivity + 3600, server_time: clock.now / 1000, scope: 'owner-session' });
    const makeResponse = (status = 200, extra = {}) => ({
        status, ok: status >= 200 && status < 300, redirected: false,
        headers: { get(name) { return ({ 'X-Session-Expires-At': snapshot().expires_at, 'X-Session-Time': snapshot().server_time, 'X-Session-Timeout': 3600 })[name] ?? null; } },
        async json() { return snapshot(); }, ...extra,
    });
    const rawFetch = async (url, options = {}) => {
        calls.push({ url: String(url), options });
        if (response) return response({ url: String(url), options, count: calls.length, makeResponse, snapshot });
        if (options.method === 'POST' || String(url).includes('/admin/save')) lastActivity = clock.now / 1000;
        return makeResponse(options.method === 'POST' ? 204 : 200);
    };
    function element() {
        const button = { disabled: false, addEventListener(name, fn) { this[name] = fn; } };
        const message = { textContent: '' };
        return { hidden: false, setAttribute() {}, innerHTML: '', querySelector(selector) { return selector === 'button' ? button : message; } };
    }
    const metas = {
        'session-activity-url': 'https://example.com/stock-pos/public/session/activity',
        'csrf-token': 'csrf', 'session-context': JSON.stringify(snapshot()),
    };
    const context = {
        URL, AbortController, Date: class extends Date { static now() { return clock.now; } },
        document: {
            hidden: false, addEventListener(name, fn) { events[name] = fn; },
            querySelector(selector) { if (selector === 'dialog[open]') return dialogHost || null; const key = /meta\[name="([^"]+)"\]/.exec(selector)?.[1]; return metas[key] ? { content: metas[key] } : null; },
            createElement: element, body: { appendChild(node) { node.parentElement = this; warnings.push(node); } },
        },
        window: {
            fetch: rawFetch, location: { href: 'https://example.com/stock-pos/public/admin/dashboard', replace(url) { redirects.push(url); } },
            localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
            history: { replaceState() {} }, addEventListener() {},
            setInterval(fn, delay) { assert.equal(delay, 1000); tick = fn; }, setTimeout() { return 1; }, clearTimeout() {},
        },
    };
    runInNewContext(source, context);
    return { context, calls, redirects, warnings, events, clock, tick: () => tick(), advance(seconds) { clock.now += seconds * 1000; }, makeResponse };
}

test('status checks back off on a network error and only explicit 401 returns to the correct login URL', async () => {
    const client = tracker({ response({ count, makeResponse }) {
        if (count === 1) throw new Error('Disconnected');
        return makeResponse(401, { async json() { return { reason: 'session_replaced', message: 'You signed in on another device.' }; } });
    } });
    client.advance(60);
    await client.tick();
    assert.deepEqual(client.redirects, []);
    await client.tick();
    assert.equal(client.calls.length, 1);
    client.advance(60);
    await client.tick();
    assert.equal(client.calls[0].url, 'https://example.com/stock-pos/public/session/status');
    assert.deepEqual(client.redirects, ['https://example.com/stock-pos/public/']);
});

test('429 respects Retry-After across tabs and never signs out', async () => {
    const storage = new Map();
    const clock = { now: epoch };
    const first = tracker({ storage, clock, response: ({ makeResponse }) => makeResponse(429, { headers: { get: () => '180' } }) });
    const second = tracker({ storage, clock });
    first.advance(60);
    await first.tick();
    await second.tick();
    first.advance(120);
    await first.tick();
    await second.tick();
    assert.equal(first.calls.length, 1);
    assert.equal(second.calls.length, 0);
    first.advance(61);
    await second.tick();
    assert.equal(second.calls.length, 1);
    assert.deepEqual(first.redirects, []);
});

test('visible tabs share one status polling lease and hidden tabs stay quiet', async () => {
    const storage = new Map(); const clock = { now: epoch };
    const first = tracker({ storage, clock }); const second = tracker({ storage, clock });
    first.advance(60);
    await first.tick(); await second.tick();
    assert.equal(first.calls.length + second.calls.length, 1);
    first.context.document.hidden = true;
    first.advance(60);
    await first.tick(); await second.tick();
    assert.equal(first.calls.length, 1);
    assert.equal(second.calls.length, 1);
});

test('status polling alone shows a warning at 58 minutes and Stay signed in resets the deadline', async () => {
    const client = tracker();
    client.advance(58 * 60);
    await client.tick();
    assert.equal(client.warnings.length, 1);
    assert.equal(client.warnings[0].hidden, false);
    await client.warnings[0].querySelector('button').click();
    assert.equal(client.calls.at(-1).options.method, 'POST');
    assert.equal(client.warnings[0].hidden, true);
    client.advance(57 * 60);
    await client.tick();
    assert.equal(client.warnings[0].hidden, true);
});

test('successful requests from other bundles refresh the warning deadline in every tab', async () => {
    const storage = new Map(); const clock = { now: epoch };
    const first = tracker({ storage, clock }); const second = tracker({ storage, clock });
    first.advance(58 * 60);
    await first.tick(); await second.tick();
    assert.equal(first.warnings[0].hidden, false);
    await first.context.window.fetch('/stock-pos/public/admin/save', { method: 'POST' });
    await second.tick();
    assert.equal(first.warnings[0].hidden, true);
    assert.equal(second.warnings[0].hidden, true);
    assert.deepEqual(second.redirects, []);
});

test('429, 419, redirects and network failures never infer a sign-out even after the local deadline', async () => {
    for (const failure of [429, 419, 500, 'redirect', 'network']) {
        const client = tracker({ response({ makeResponse }) {
            if (failure === 'network') throw new Error('Network');
            return failure === 'redirect' ? makeResponse(200, { redirected: true }) : makeResponse(failure);
        } });
        client.advance(61 * 60);
        await client.tick();
        assert.deepEqual(client.redirects, []);
        assert.equal(client.warnings[0].hidden, false);
    }
});

test('blocked storage still permits activity and bounded polling without navigating away', async () => {
    const client = tracker();
    client.context.window.localStorage.getItem = () => { throw new Error('Blocked'); };
    client.context.window.localStorage.setItem = () => { throw new Error('Blocked'); };
    client.events.pointerdown({ isTrusted: true });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(client.calls.length, 1);
    client.advance(60);
    await client.tick(); await client.tick();
    assert.equal(client.calls.length, 2);
    assert.deepEqual(client.redirects, []);
});


test('idle warning remains actionable above an open payment dialog', async () => {
    const dialogHost = { appendChild(node) { node.parentElement = this; } };
    const client = tracker({ dialogHost });
    client.advance(58 * 60);
    await client.tick();
    assert.equal(client.warnings[0].parentElement, dialogHost);
    await client.warnings[0].querySelector('button').click();
    assert.equal(client.warnings[0].hidden, true);
});
