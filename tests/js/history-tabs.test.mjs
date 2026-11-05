import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/js/history-tabs.js', import.meta.url), 'utf8');

function load(search = '', hash = '', grouped = false) {
    class Element {
        constructor() { this.attributes = {}; this.events = {}; this.children = []; this.hidden = false; }
        setAttribute(name, value) { this.attributes[name] = value; }
        append(child) { this.children.push(child); }
        before(...elements) { this.beforeElements = elements; }
        addEventListener(name, listener) { this.events[name] = listener; }
        focus() { this.focused = true; }
        scrollIntoView() { this.scrolled = true; }
    }
    const panel = new Element();
    panel.id = 'movement-logs';
    panel.dataset = { historyPanel: 'Movement logs', historyPage: 'ledger_page', historySize: 'ledger_rows' };
    panel.hidden = true;
    const archive = new Element();
    archive.id = 'archived-products';
    archive.dataset = { historyPanel: 'Archived products', historyPage: 'archive_page', historySize: 'archive_rows', historyGroup: 'stock-records', historyOrder: '2' };
    const panels = grouped ? [archive, panel] : [panel];
    if (grouped) Object.assign(panel.dataset, { historyGroup: 'stock-records', historyOrder: '1' });
    const window = { location: { search, hash }, addEventListener() {} };
    const documentEvents = {};
    vm.runInNewContext(source, { document: { querySelectorAll: () => panels, createElement: () => new Element(), addEventListener(type, callback) { documentEvents[type] = callback; } }, window, URLSearchParams, URL });
    const [tabs, placeholder] = panels[0].beforeElements;
    return { panel, tabs, placeholder, overview: tabs.children[0], history: tabs.children[1], archive, documentEvents };
}

test('history is closed by default and its tab opens and closes without navigation', () => {
    const { panel, history, overview, placeholder } = load();
    assert.equal(panel.hidden, true);
    assert.equal(overview.attributes['aria-selected'], 'true');
    history.events.click();
    assert.equal(panel.hidden, false);
    assert.equal(placeholder.hidden, true);
    assert.equal(history.attributes['aria-selected'], 'true');
    overview.events.click();
    assert.equal(panel.hidden, true);
});

test('history pagination, row-size changes and direct anchors reopen the right tab', () => {
    for (const [search, hash] of [['?ledger_page=2', ''], ['?ledger_rows=50', ''], ['', '#movement-logs']]) {
        assert.equal(load(search, hash).panel.hidden, false);
    }
    assert.equal(load('?page=2', '#inventory-table').panel.hidden, true);
});

test('history tabs support keyboard selection and focus', () => {
    const { tabs, panel, history, overview } = load();
    let prevented = 0;
    tabs.events.keydown({ key: 'ArrowRight', preventDefault() { prevented += 1; } });
    assert.equal(panel.hidden, false);
    assert.equal(history.focused, true);
    tabs.events.keydown({ key: 'Home', preventDefault() { prevented += 1; } });
    assert.equal(panel.hidden, true);
    assert.equal(overview.focused, true);
    assert.equal(prevented, 2);
});

test('movement logs and archived products share a bar and only the selected panel opens', () => {
    const { panel, archive, tabs } = load('', '', true);
    assert.deepEqual(tabs.children.map(button => button.textContent), ['Overview', 'Movement logs', 'Archived products']);
    tabs.children[2].events.click();
    assert.equal(archive.hidden, false);
    assert.equal(panel.hidden, true);
    tabs.children[1].events.click();
    assert.equal(archive.hidden, true);
    assert.equal(panel.hidden, false);
    const restored = load('?ledger_page=2&archive_page=1', '#archived-products', true);
    assert.equal(restored.archive.hidden, false);
    assert.equal(restored.panel.hidden, true);
});
