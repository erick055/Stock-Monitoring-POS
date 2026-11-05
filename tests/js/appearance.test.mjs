import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/appearance.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');

function appearance({ theme = 'dark', storageFails = false } = {}) {
    const root = { dataset: { theme } };
    const events = {};
    const attributes = {};
    let click;
    const button = { dataset: {}, append() {}, setAttribute(key, value) { attributes[key] = value; }, addEventListener(type, fn) { if (type === 'click') click = fn; } };
    const writes = [];
    let appended;
    runInNewContext(source, {
        document: { documentElement: root, createElement(tag) { return tag === 'button' ? button : { setAttribute() {} }; }, body: { append(node) { appended = node; } } },
        window: { dispatchEvent() {}, addEventListener(type, callback) { events[type] = callback; } },
        localStorage: { setItem(key, value) { if (storageFails) throw new Error('Storage unavailable'); writes.push([key, value]); } },
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    });
    return { root, attributes, button, writes, click: () => click(), storage: events.storage, appended };
}

test('appearance respects the restored preference, persists changes, and follows other tabs', () => {
    const view = appearance({ theme: 'light' });
    assert.equal(view.appended, view.button);
    assert.equal(view.attributes['aria-label'], 'Switch to dark mode');
    view.click();
    assert.equal(view.root.dataset.theme, 'dark');
    assert.deepEqual(view.writes, [['motosync-theme', 'dark']]);
    view.storage({ key: 'motosync-theme', newValue: 'light' });
    assert.equal(view.root.dataset.theme, 'light');
    assert.equal(view.attributes['aria-pressed'], 'true');
});

test('appearance remains usable when browser storage is blocked', () => {
    const view = appearance({ storageFails: true });
    assert.doesNotThrow(() => view.click());
    assert.equal(view.root.dataset.theme, 'light');
});
