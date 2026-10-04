import test from 'node:test';
import assert from 'node:assert/strict';
import { checkoutStorage, canDiscardCheckout } from '../../resources/js/pending-checkout.js';

test('pending request survives reload with identical key and payload', () => {
    const data = new Map();
    const storage = { getItem: k => data.get(k), setItem: (k,v) => data.set(k,v), removeItem: k => data.delete(k) };
    const pending = { key: '12345678-1234-1234-1234-123456789012', body: JSON.stringify({items: [{product_id: 1, quantity: 2}]}), cart: [] };
    checkoutStorage(storage, 'cashier1').save(pending);
    const reloaded = checkoutStorage(storage, 'cashier1');
    assert.deepEqual(reloaded.load(), pending);
    assert.equal(checkoutStorage(storage, 'cashier2').load(), null);
    reloaded.clear(pending);
    assert.equal(reloaded.load(), null);
});

test('uncertain errors preserve request; corrupt storage fails closed', () => {
    for (const status of [401,403,409,419,429,500,502,503]) assert.equal(canDiscardCheckout(status), false);
    assert.equal(canDiscardCheckout(422), true);
    assert.throws(() => checkoutStorage({getItem: () => 'corrupted'}, 'key').load());
});
