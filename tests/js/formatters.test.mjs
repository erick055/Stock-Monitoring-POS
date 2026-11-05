import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { currency, plural } from '../../resources/js/formatters.js';

test('shared currency and plural helpers use peso separators and correct counts', () => {
    assert.equal(currency(2000), '₱2,000.00');
    assert.equal(currency(1234.56), '₱1,234.56');
    assert.equal(currency(-1234.56), '-₱1,234.56');
    assert.equal(plural(1, 'unit'), '1 unit');
    assert.equal(plural(0, 'unit'), '0 units');
    assert.equal(plural(1000, 'unit'), '1,000 units');
    assert.equal(plural(1, 'item'), '1 item');
});

test('new checkout keeps paginated history stable and offers an explicit refresh', () => {
    const source = readFileSync(new URL('../../resources/js/pos.js', import.meta.url), 'utf8');
    const start = source.indexOf('    function prependCheckoutLog(');
    const end = source.indexOf('    function updateActiveHold(', start);
    const notice = { hidden: true };
    const context = {
        document: { querySelector(selector) { assert.equal(selector, '[data-checkout-history-update]'); return notice; } },
        checkoutLogs: { prepend() { assert.fail('History rows should remain on their original page'); } },
    };
    runInNewContext(source.slice(start, end), context);
    context.prependCheckoutLog({ number: 'POS-000010' });
    assert.equal(notice.hidden, false);
});
