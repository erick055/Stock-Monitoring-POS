import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/js/dashboard.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');

test('mobile navigation stays out of keyboard focus when closed, traps focus when open, and recovers on desktop resize', () => {
    const handlers = {}, windowHandlers = {};
    const document = { activeElement: null, addEventListener(type, callback) { handlers[type] = callback; } };
    const element = () => {
        const classes = new Set();
        return { attributes: {}, handlers: {}, classList: { toggle(name, value) { value ? classes.add(name) : classes.delete(name); }, contains: name => classes.has(name) },
            setAttribute(name, value) { this.attributes[name] = value; }, addEventListener(type, callback) { this.handlers[type] = callback; }, focus() { document.activeElement = this; } };
    };
    const first = element(), last = element(), menu = element(), sidebar = element(), backdrop = element();
    sidebar.querySelector = () => first;
    sidebar.querySelectorAll = () => [first, last];
    document.querySelector = selector => selector === '[data-menu]' ? menu : selector === '[data-sidebar]' ? sidebar : null;
    document.createElement = () => backdrop;
    document.body = { append() {}, classList: element().classList };
    const window = { innerWidth: 390, addEventListener(type, callback) { windowHandlers[type] = callback; } };
    vm.runInNewContext(source, { document, window });
    assert.equal(sidebar.inert, true);
    assert.equal(menu.attributes['aria-controls'], 'primary-sidebar');
    menu.handlers.click();
    assert.equal(sidebar.inert, false);
    assert.equal(document.activeElement, first);
    let prevented = false;
    handlers.keydown({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(document.activeElement, last);
    handlers.keydown({ key: 'Escape' });
    assert.equal(sidebar.inert, true);
    assert.equal(document.activeElement, menu);
    window.innerWidth = 1440;
    windowHandlers.resize();
    assert.equal(sidebar.inert, false);
    window.innerWidth = 768;
    windowHandlers.resize();
    assert.equal(sidebar.inert, true);
});
