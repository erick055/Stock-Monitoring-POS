import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/js/analytics-tabs.js', import.meta.url), 'utf8');
function load(url = 'https://example.test/admin/analytics', validation = false) {
    const keys = ['overview', 'transactions', 'movement', 'ai', 'targets'];
    const element = () => ({ attributes: {}, events: {}, setAttribute(key, value) { this.attributes[key] = value; },
        getAttribute(key) { return this.attributes[key]; }, addEventListener(key, callback) { this.events[key] = callback; }, focus() { this.focused = true; } });
    const buttons = keys.map(key => Object.assign(element(), { dataset: { analyticsTab: key }, attributes: { 'aria-selected': String(key === 'overview') } }));
    const forms = keys.map(() => ({ children: [], querySelector() { return this.children[0]; }, append(input) { this.children.push(input); } }));
    const panels = keys.map((key, index) => ({ dataset: { analyticsPanel: key }, hidden: false, querySelectorAll: () => [forms[index]], closest() { return this; } }));
    const bar = Object.assign(element(), { querySelectorAll: () => buttons });
    const documentEvents = {}, windowEvents = {};
    const window = { location: { href: url }, history: { pushState(_, __, next) { window.location.href = String(next); } }, addEventListener(key, callback) { windowEvents[key] = callback; } };
    const anchors = { 'transaction-history': panels[1], 'selling-speed': panels[2], 'future-demand': panels[3], 'sales-target-history': panels[4] };
    const document = { querySelector: selector => selector === '[data-analytics-tabs]' ? bar : validation ? {} : null,
        querySelectorAll: () => panels, createElement: () => ({}), dispatchEvent() {},
        getElementById: key => anchors[key], addEventListener(key, callback) { documentEvents[key] = callback; } };
    vm.runInNewContext(source, { document, window, URL, CustomEvent: class {} });
    return { buttons, panels, forms, bar, documentEvents, windowEvents, window };
}

test('Analytics tabs change only visible panel, preserve filters, and support Back and keyboard navigation', () => {
    const page = load('https://example.test/admin/analytics?period=month&range=2026-11');
    assert.deepEqual(page.panels.map(panel => panel.hidden), [false, true, true, true, true]);
    page.buttons[4].events.click();
    assert.deepEqual(page.panels.map(panel => panel.hidden), [true, true, true, true, false]);
    assert.match(page.window.location.href, /range=2026-11/);
    assert.match(page.window.location.href, /tab=targets/);
    page.bar.events.keydown({ key: 'Home', preventDefault() {} });
    assert.equal(page.buttons[0].focused, true);
    page.bar.events.keydown({ key: 'ArrowLeft', preventDefault() {} });
    assert.equal(page.panels[4].hidden, false);
    page.window.location.href = 'https://example.test/admin/analytics?tab=transactions';
    page.windowEvents.popstate();
    assert.equal(page.panels[1].hidden, false);
    assert.equal(page.forms[1].children.length, 1);
    assert.equal(page.forms[1].children[0].value, 'transactions');
});

test('table anchors, day links, pagination updates and validation open their owning Analytics tab', () => {
    for (const [query, index] of [['#selling-speed', 2], ['?tab=overview#future-demand', 3], ['?period=day', 1], ['?target_page=2', 4]]) {
        const page = load(`https://example.test/admin/analytics${query}`);
        assert.equal(page.panels[index].hidden, false);
        assert.equal(page.panels.filter(panel => !panel.hidden).length, 1);
    }
    const page = load('https://example.test/admin/analytics?tab=overview', true);
    assert.equal(page.panels[4].hidden, false);
    page.documentEvents['motosync:table-updated']({ detail: { root: { closest: () => page.panels[2] } } });
    assert.equal(page.panels[2].hidden, false);
});

test('Manage target opens the tab locally and modified clicks retain normal link behavior', () => {
    const page = load();
    let prevented = false;
    const target = { closest: () => ({ dataset: { analyticsOpen: 'targets' } }) };
    page.documentEvents.click({ target, ctrlKey: true, preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    page.documentEvents.click({ target, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(page.panels[4].hidden, false);
});
