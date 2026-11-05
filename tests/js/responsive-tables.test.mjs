import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/js/responsive-tables.js', import.meta.url), 'utf8');

function setup(preserveTabIndex = false) {
    const attributes = {}, classes = new Set(), observed = new Set(), events = {}, windowEvents = {};
    let hint, table = {};
    const wrapper = {
        clientWidth: 320, scrollWidth: 950, isConnected: true,
        classList: { toggle(name, value) { value ? classes.add(name) : classes.delete(name); } },
        hasAttribute: name => name in attributes,
        setAttribute(name, value) { attributes[name] = value; }, removeAttribute(name) { delete attributes[name]; },
        querySelector: () => table, closest: () => ({ querySelector: () => ({ textContent: 'Products' }) }),
        before(node) { hint = node; },
    };
    if (preserveTabIndex) attributes.tabindex = '0';
    class Observer { constructor(callback) { events.resize = callback; } observe(node) { observed.add(node); } unobserve(node) { observed.delete(node); } }
    const document = {
        querySelectorAll: () => wrapper.isConnected ? [wrapper] : [],
        createTextNode: text => ({ textContent: text }),
        createElement: () => ({ append() {}, setAttribute() {}, remove() { this.removed = true; } }),
        addEventListener(name, callback) { events[name] = callback; },
    };
    vm.runInNewContext(source, { document, window: { addEventListener(name, callback) { windowEvents[name] = callback; } }, ResizeObserver: Observer });
    return { wrapper, attributes, classes, observed, events, windowEvents, hint: () => hint, table: () => table, replaceTable() { table = {}; } };
}

test('wide tables expose a swipe hint and keyboard scrolling, then hide the hint when they fit', () => {
    const app = setup();
    assert.equal(app.hint().hidden, false);
    assert.equal(app.attributes.tabindex, '0');
    assert.equal(app.attributes.role, 'region');
    assert.equal(app.classes.has('table-can-scroll'), true);
    app.wrapper.clientWidth = 1200;
    app.windowEvents.resize();
    assert.equal(app.hint().hidden, true);
    assert.equal(app.attributes.tabindex, undefined);
    app.wrapper.clientWidth = 0;
    app.events.resize();
    assert.equal(app.hint().hidden, true);
});

test('table pagination rebinds size observations and removes detached hints without changing authored focus behavior', () => {
    const app = setup(true);
    const oldTable = app.table();
    app.replaceTable();
    app.events['motosync:table-updated']();
    assert.equal(app.observed.has(oldTable), false);
    assert.equal(app.observed.has(app.table()), true);
    app.wrapper.clientWidth = 1200;
    app.events.resize();
    assert.equal(app.attributes.tabindex, '0');
    app.wrapper.isConnected = false;
    app.events['motosync:table-updated']();
    assert.equal(app.observed.size, 0);
    assert.equal(app.hint().removed, true);
});
