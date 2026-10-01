import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

test('POS cart renders imported product names as text and preserves totals and controls', () => {
    const source = readFileSync(new URL('../../resources/js/pos.js', import.meta.url), 'utf8');
    const start = source.indexOf('    function renderCart()');
    const end = source.indexOf('    function addToCart(', start);
    const maliciousName = '<img src=x onerror="alert(1)"><script>alert(2)</script> & Special Part';
    const fields = new Map();
    const row = {
        innerHTML: '',
        querySelector(selector) {
            if (!fields.has(selector)) fields.set(selector, { textContent: '', addEventListener(type, handler) { this.handler = handler; } });
            return fields.get(selector);
        },
    };
    let total;
    const changes = [];
    const rows = [];
    runInNewContext(`${source.slice(start, end)}\nrenderCart();`, {
        cartContainer: { innerHTML: '', appendChild(item) { rows.push(item); } },
        cart: [{ id: 7, name: maliciousName, price: 125.5, qty: 2 }],
        document: { createElement() { return row; } },
        peso: (value) => `PHP ${value.toFixed(2)}`,
        updateTotals: (value) => { total = value; },
        changeQty: (...args) => changes.push(args),
    });
    assert.equal(rows.length, 1);
    assert.equal(fields.get('h4').textContent, maliciousName);
    assert.ok(!row.innerHTML.includes('<img'));
    assert.ok(!row.innerHTML.includes('<script'));
    assert.equal(fields.get('.item-controls span').textContent, '2');
    assert.equal(fields.get('.item-total').textContent, 'PHP 251.00');
    assert.equal(total, 251);
    fields.get('[data-action="minus"]').handler();
    fields.get('[data-action="plus"]').handler();
    assert.deepEqual(changes, [[7, -1], [7, 1]]);
});
