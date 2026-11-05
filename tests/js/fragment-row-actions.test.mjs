import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

test('password visibility initializes new staff fields without adding duplicate toggle handlers', () => {
    const events = {};
    let field, input, toggle;
    const newField = () => {
        input = { type: 'password' };
        toggle = { handlers: [], setAttribute() {}, querySelector: () => ({ classList: { toggle() {} } }),
            addEventListener(_, callback) { this.handlers.push(callback); } };
        field = { classList: { add() {} }, querySelector: selector => selector === 'input' ? input : toggle };
    };
    newField();
    const source = fs.readFileSync(new URL('../../resources/js/password-visibility.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
    vm.runInNewContext(source, { document: { querySelectorAll: () => [field], addEventListener(type, callback) { events[type] = callback; } } });
    newField();
    events['motosync:table-updated']();
    events['motosync:table-updated']();
    assert.equal(toggle.handlers.length, 1);
    toggle.handlers[0]();
    assert.equal(input.type, 'text');
    toggle.handlers[0]();
    assert.equal(input.type, 'password');
});

for (const [module, buttonSelector, dialogSelector, idKey] of [
    ['account-dialogs', '[data-open-account-dialog]', '[data-account-dialog]', 'openAccountDialog'],
    ['suppliers', '[data-open-price-dialog]', '[data-supplier-price-dialog]', 'openPriceDialog'],
    ['dead-stock', '[data-deadstock-open]', '.deadstock-dialog', 'deadstockOpen'],
]) {
    test(`${module} popup controls work after paging and initialize once`, () => {
        class Element {
            constructor() { this.events = {}; this.dataset = {}; this.opened = 0; this.open = false; }
            addEventListener(type, callback) { (this.events[type] ??= []).push(callback); }
            querySelector() { return null; }
            querySelectorAll() { return []; }
            matches(selector) { return selector === '[data-account-dialog]'; }
            showModal() { this.open = true; this.opened += 1; }
        }
        const handlers = {};
        let button, dialog;
        const document = {
            querySelector: () => null,
            querySelectorAll(selector) { return selector === buttonSelector ? [button] : selector === dialogSelector ? [dialog] : []; },
            getElementById: () => dialog,
            addEventListener(type, callback) { (handlers[type] ??= []).push(callback); },
            body: { classList: { add() {}, remove() {} } },
        };
        const newPage = () => { button = new Element(); button.dataset[idKey] = 'record'; dialog = new Element(); };
        newPage();
        const source = fs.readFileSync(new URL(`../../resources/js/${module}.js`, import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
        vm.runInNewContext(source, { document, window: {}, currency: String });
        newPage();
        for (let i = 0; i < 2; i += 1) handlers['motosync:table-updated'].forEach(callback => callback({ detail: { root: {} } }));
        if (module === 'account-dialogs') {
            handlers.click.forEach(callback => callback({ target: { closest: selector => selector === buttonSelector ? button : null } }));
        } else {
            button.events.click.forEach(callback => callback({}));
        }
        assert.equal(dialog.opened, 1);
        assert.equal(dialog.events.close?.length ?? dialog.events.cancel?.length, 1);
    });
}
