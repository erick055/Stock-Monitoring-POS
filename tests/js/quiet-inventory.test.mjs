import { money, plural } from '../../resources/js/formatters.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/inventory-live-reload.js', import.meta.url), 'utf8').replace(/import[^;]+;/g, '');

test('inventory updates values in place, pauses for forms and dialogs, and never reloads automatically', async () => {
    let poll;
    let calls = 0;
    let reloads = 0;
    let focused = false;
    let modal = false;
    let stock = 2;
    const input = { tagName: 'INPUT', type: 'text', value: '', defaultValue: '' };
    const form = { elements: [input], closest() { return null; } };
    const stockLabel = { textContent: '9 units' };
    const notice = { hidden: true };
    const row = { dataset: { liveProductId: '7' }, querySelector() { return null; }, querySelectorAll(selector) { return selector === '[data-live-stock]' ? [stockLabel] : []; } };
    const page = { dataset: { inventoryVersion: 'v1', liveInventoryUrl: '/inventory/live' } };
    runInNewContext(source, {
        money, plural, URL, AbortController, HTMLElement: class {},
        document: {
            hidden: false, activeElement: { matches() { return focused; } },
            body: { classList: { contains() { return modal; } } },
            querySelector(selector) { return selector === '[data-live-inventory-page]' ? page : selector === '[data-inventory-update-notice]' ? notice : null; },
            querySelectorAll(selector) { return selector === 'form' ? [form] : selector === '[data-live-product-id]' ? [row] : []; },
        },
        window: { location: { href: 'https://example.com/admin/inventory', reload() { reloads++; } }, setInterval(callback, delay) { assert.equal(delay, 20000); poll = callback; }, setTimeout() { return 1; }, clearTimeout() {} },
        async fetch(url) {
            calls++;
            assert.match(url, /view=balances/);
            return { ok: true, async json() { return { version: `v${stock}`, balances: [{ id: 7, stock, reorder: 5, unitCost: 10, unitPrice: 20, status: 'critical' }] }; } };
        },
    });
    await poll();
    assert.equal(stockLabel.textContent, '2 units');
    assert.equal(notice.hidden, false);
    stock = 3;
    focused = true;
    await poll();
    focused = false;
    modal = true;
    await poll();
    modal = false;
    input.value = 'unsaved change';
    await poll();
    assert.equal(calls, 1);
    assert.equal(stockLabel.textContent, '2 units');
    input.value = '';
    await poll();
    assert.equal(calls, 2);
    assert.equal(stockLabel.textContent, '3 units');
    assert.equal(reloads, 0);
});

test('POS pauses its live redraw while typing or viewing a receipt and uses one request at a time', async () => {
    const posSource = readFileSync(new URL('../../resources/js/pos.js', import.meta.url), 'utf8');
    const start = posSource.indexOf('    async function syncLiveInventory()');
    const end = posSource.indexOf('    searchInput?.addEventListener', start);
    let focused = true;
    let calls = 0;
    let rendered = 0;
    let release;
    const receipt = { hidden: true };
    const context = {
        pendingCheckout: null, recoveryBlocked: false, liveInventoryUrl: '/inventory/live', inventorySyncing: false,
        inventoryRetryAt: 0, lastInventoryInteraction: 0, inventoryVersion: 'old', products: [], cart: [], receiptModal: receipt,
        document: { hidden: false, activeElement: { matches() { return focused; }, closest() { return null; } }, body: { classList: { contains() { return false; } } } },
        window: { setTimeout() { return 1; }, clearTimeout() {} }, AbortController,
        renderCategoryButtons() { rendered++; }, renderProducts() { rendered++; }, renderCart() { rendered++; }, showToast() {},
        async fetch() { calls++; await new Promise(resolve => { release = resolve; }); return { ok: true, async json() { return { version: 'new', products: [] }; } }; },
    };
    runInNewContext(posSource.slice(start, end), context);
    await context.syncLiveInventory();
    assert.equal(calls, 0);
    focused = false;
    receipt.hidden = false;
    await context.syncLiveInventory();
    assert.equal(calls, 0);
    receipt.hidden = true;
    const pending = context.syncLiveInventory();
    await context.syncLiveInventory();
    assert.equal(calls, 1);
    focused = true;
    release();
    await pending;
    assert.equal(rendered, 0);
});
