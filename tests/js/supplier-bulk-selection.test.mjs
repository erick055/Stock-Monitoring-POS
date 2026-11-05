import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

test('supplier bulk selection survives paging, restores checks, and submits every selected page', () => {
    const events = {};
    const control = () => ({ addEventListener(type, callback) { this[type] = callback; } });
    const all = control(), button = control(), count = {}, modalCount = {};
    const ids = { replaceChildren(...inputs) { this.inputs = inputs; } };
    const modal = { querySelector: selector => selector === '[data-bulk-modal-count]' ? modalCount : ids };
    const controls = { '[data-bulk-select-all]': all, '[data-open-bulk-products]': button,
        '[data-bulk-count]': count, '[data-bulk-products-modal]': modal };
    let rows = [];
    const document = {
        querySelector: selector => controls[selector],
        querySelectorAll: () => rows,
        addEventListener(type, callback) { events[type] = callback; },
        createElement: () => ({}),
    };
    const source = fs.readFileSync(new URL('../../resources/js/suppliers.js', import.meta.url), 'utf8');
    vm.runInNewContext(source.slice(source.indexOf('const bulkCheckboxes'), source.indexOf('const initializedSupplierControls')), {
        document, openModal() {},
    });
    assert.equal(all.disabled, true);
    const row = value => ({ value, checked: false, matches: () => true });
    const first = row('10');
    rows = [first];
    events['motosync:table-updated']();
    first.checked = true;
    events.change({ target: first });
    rows = [row('20'), row('21')];
    events['motosync:table-updated']();
    assert.equal(count.textContent, '1');
    assert.equal(all.checked, false);
    all.checked = true;
    all.change();
    assert.equal(count.textContent, '3');
    rows = [row('10')];
    events['motosync:table-updated']();
    assert.equal(rows[0].checked, true);
    all.checked = false;
    all.change();
    assert.equal(count.textContent, '2');
    button.click();
    assert.deepEqual(ids.inputs.map(input => input.value), ['20', '21']);
    assert.equal(ids.inputs[0].name, 'supplier_price_ids[]');
    assert.equal(modalCount.textContent, '2');
});
