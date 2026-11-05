import test from 'node:test';
import assert from 'node:assert/strict';
import { tableUrl, TableRequest } from '../../resources/js/async-tables.js';

const config = { page: 'ledger_page', size: 'ledger_rows', anchor: 'movement-logs' };

test('paging one table retains the latest filters and other tables instead of stale link parameters', () => {
    const url = tableUrl('https://example.test/admin/inventory?search=chain&page=3&ledger_rows=50',
        'https://example.test/admin/inventory?search=old&page=1&ledger_page=2', config);
    assert.equal(url.searchParams.get('search'), 'chain');
    assert.equal(url.searchParams.get('page'), '3');
    assert.equal(url.searchParams.get('ledger_rows'), '50');
    assert.equal(url.searchParams.get('ledger_page'), '2');
    assert.equal(url.hash, '#movement-logs');
    assert.equal(tableUrl(url, 'https://another.test/admin/inventory?ledger_page=2', config), null);
    assert.equal(tableUrl(url, '/login', config), null);
});

test('row-size changes reset only their own page and keep other controls', () => {
    const original = globalThis.FormData;
    globalThis.FormData = class {
        constructor(form) { this.values = new Map(form.values); }
        get(name) { return this.values.get(name); }
    };
    try {
        const url = tableUrl('https://example.test/admin/inventory?page=4&ledger_page=3&search=chain',
            'https://example.test/admin/inventory', config,
            { values: [['ledger_rows', '100']], closest: () => ({}) });
        assert.equal(url.searchParams.get('ledger_rows'), '100');
        assert.equal(url.searchParams.has('ledger_page'), false);
        assert.equal(url.searchParams.get('page'), '4');
        assert.equal(url.searchParams.get('search'), 'chain');
    } finally { globalThis.FormData = original; }
});

const response = (body, extra = {}) => ({
    ok: true, status: 200, redirected: false, url: 'https://example.test/admin/inventory',
    headers: new Headers({ 'Content-Type': 'text/html' }), text: async () => body, ...extra,
});

test('rapid navigation cancels older requests and ignores responses arriving out of order', async () => {
    const pending = [];
    const request = new TableRequest((url, options) => new Promise(resolve => pending.push({ resolve, options })));
    const first = request.load(new URL('https://example.test/admin/inventory?ledger_page=2'));
    const second = request.load(new URL('https://example.test/admin/inventory?ledger_page=3'));
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(pending[1].options.credentials, 'same-origin');
    pending[1].resolve(response('page three'));
    assert.equal(await second, 'page three');
    pending[0].resolve(response('page two'));
    assert.equal(await first, null);
});

test('rate limits, expired sessions, wrong content and network failures stay recoverable', async () => {
    const url = new URL('https://example.test/admin/inventory');
    for (const [result, expected] of [
        [response('', { status: 429, headers: new Headers({ 'Retry-After': '30' }) }), /30 seconds/],
        [response('', { status: 401 }), /session may have expired/],
        [response('', { redirected: true, url: 'https://example.test/login' }), /session may have expired/],
        [response('', { headers: new Headers({ 'Content-Type': 'application/json' }) }), /could not be loaded/],
        [response('', { ok: false, status: 500 }), /could not be loaded/],
    ]) {
        await assert.rejects(new TableRequest(async () => result).load(url), expected);
    }
    const request = new TableRequest(async () => { throw new TypeError('Network offline'); });
    await assert.rejects(request.load(url), /Network offline/);
    request.fetcher = async () => response('recovered');
    assert.equal(await request.load(url), 'recovered');
});
