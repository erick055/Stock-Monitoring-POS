import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { paymentState, createPaymentDialog } from '../../resources/js/payment-dialog.js';

test('Charge opens payment review without posting checkout', () => {
    const source = readFileSync(new URL('../../resources/js/pos.js', import.meta.url), 'utf8');
    const start = source.indexOf("    posApp.querySelector('[data-process-payment]')?.addEventListener('click'");
    const end = source.indexOf('    async function processPayment(', start);
    let click;
    let opened = 0;
    let posted = 0;
    runInNewContext(source.slice(start, end), {
        posApp: { querySelector() { return { addEventListener(type, callback) { click = callback; } }; } },
        recoveryBlocked: false, pendingCheckout: null, cart: [{ id: 1, qty: 1 }], laborAmount: () => 0,
        paymentFlow: { open() { opened++; } }, fetch() { posted++; }, showToast() {},
    });
    click();
    assert.equal(opened, 1);
    assert.equal(posted, 0);
});

test('payment eligibility checks cash in cents and requires digital payment references', () => {
    assert.equal(paymentState('cash', '', '', 950).valid, false);
    assert.equal(paymentState('cash', '949.99', '', 950).valid, false);
    assert.equal(paymentState('cash', '950', '', 950).valid, true);
    assert.equal(paymentState('cash', '1000', '', 950).change, 50);
    assert.equal(paymentState('cash', '1.001', '', 1).valid, false);
    assert.equal(paymentState('cash', '0.30', '', 0.1 + 0.2).valid, true);
    for (const method of ['gcash', 'maya', 'card']) {
        assert.equal(paymentState(method, '', '   ', 950).valid, false);
        assert.equal(paymentState(method, '', 'PAY-123', 950).valid, true);
    }
    assert.equal(paymentState('unsupported', '', 'PAY-123', 950).valid, false);
});

function dialogFixture() {
    const nodes = new Map();
    const node = (selector) => {
        if (!nodes.has(selector)) nodes.set(selector, { value: '', disabled: false, hidden: false, textContent: '', events: {}, addEventListener(type, handler) { this.events[type] = handler; }, focus() {} });
        return nodes.get(selector);
    };
    const dialog = { open: false, querySelector: node, addEventListener() {}, showModal() { this.open = true; }, close() { this.open = false; } };
    globalThis.document = { body: { classList: { add() {}, remove() {} } }, querySelector() { return null; } };
    return { dialog, node };
}

test('payment dialog keeps Confirm disabled until valid and blocks double submission', async () => {
    const { dialog, node } = dialogFixture();
    const calls = [];
    let release;
    const payment = createPaymentDialog(dialog, { getTotal: () => 950, onConfirm(details) { calls.push(details); return new Promise(resolve => { release = resolve; }); } });
    payment.open();
    assert.equal(dialog.open, true);
    assert.equal(calls.length, 0);
    assert.equal(node('[data-confirm-payment]').disabled, true);
    node('[data-payment-tendered]').value = '1000';
    node('form').events.input();
    assert.equal(node('[data-confirm-payment]').disabled, false);
    assert.match(node('[data-payment-change]').textContent, /50\.00/);
    const first = node('form').events.submit({ preventDefault() {} });
    await node('form').events.submit({ preventDefault() {} });
    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0], { payment_method: 'cash', amount_tendered: 1000, payment_reference: null });
    release(true);
    await first;
    assert.equal(dialog.open, false);
});

test('cancel does not charge and unresolved payments reopen with frozen original details', () => {
    const { dialog, node } = dialogFixture();
    let calls = 0;
    const payment = createPaymentDialog(dialog, { getTotal: () => 950, onConfirm() { calls++; } });
    payment.open();
    node('[data-cancel-payment]').events.click();
    assert.equal(calls, 0);
    assert.equal(dialog.open, false);
    payment.open({ payment_method: 'gcash', payment_reference: 'ORIGINAL', amount_tendered: null, payment_total: 950 });
    assert.equal(node('[data-payment-method]').disabled, true);
    assert.equal(node('[data-payment-reference]').value, 'ORIGINAL');
    assert.equal(node('[data-confirm-payment]').textContent, 'Retry payment');
    assert.equal(node('[data-confirm-payment]').disabled, false);
});

test('uncertain checkout retries reuse the exact payment body and idempotency key', async () => {
    const source = readFileSync(new URL('../../resources/js/pos.js', import.meta.url), 'utf8');
    const start = source.indexOf('    async function processPayment(');
    const end = source.indexOf('    bindCategoryButtons();', start);
    const requests = [];
    let durable = null;
    let generated = 0;
    const button = { disabled: false, classList: { add() {}, remove() {} } };
    const notice = { hidden: true };
    const context = {
        recoveryBlocked: false, paymentSubmitting: false, pendingCheckout: null, checkoutLockedControls: [], activeHeldOrderId: null,
        cart: [{ id: 7, price: 800, qty: 1 }], laborInput: { value: '150' }, inventoryVersion: 'v1',
        laborAmount: () => 150, currentSubtotal: () => 800, csrfToken: 'token', checkoutUrl: '/staff/pos/checkout',
        posApp: { querySelector(selector) { return selector === '[data-process-payment]' ? button : notice; }, querySelectorAll() { return []; } },
        savedCheckout: { load: () => durable, save(value) { durable = value; }, clear() { durable = null; } },
        crypto: { randomUUID() { generated++; return '12345678-1234-1234-1234-123456789012'; } },
        paymentFlow: { setPending() {}, setError() {} }, canDiscardCheckout: status => status === 422,
        lockPendingCheckout() {}, renderCart() {}, showToast() {}, showReceipt() {}, prependCheckoutLog() {}, syncLiveInventory() {},
        async fetch(url, options) {
            requests.push(options);
            if (requests.length === 1) throw new Error('Response lost');
            return { ok: true, async json() { return { sale_id: 1, receipt: { number: 'POS-000001', items: [] } }; } };
        },
    };
    runInNewContext(source.slice(start, end), context);
    await context.processPayment({ payment_method: 'cash', amount_tendered: 1000, payment_reference: null });
    assert.ok(durable);
    await context.processPayment({ payment_method: 'card', payment_reference: 'CHANGED' });
    assert.equal(requests.length, 2);
    assert.equal(generated, 1);
    assert.equal(requests[0].headers['Idempotency-Key'], requests[1].headers['Idempotency-Key']);
    assert.equal(requests[0].body, requests[1].body);
    assert.equal(JSON.parse(requests[1].body).amount_tendered, 1000);
    assert.equal(durable, null);
});
