import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/async-tables.js', import.meta.url), 'utf8').replace(/^export /gm, '');

function browser() {
    const handlers = {}, windowHandlers = {}, roots = new Map();
    let fail = false;
    class Element {
        constructor() { this.dataset = {}; this.attributes = {}; this.children = []; this.isConnected = true; }
        setAttribute(key, value) { this.attributes[key] = value; }
        hasAttribute(key) { return key in this.attributes; }
        removeAttribute(key) { delete this.attributes[key]; }
        append(...children) { this.children.push(...children); if (children[0]?.dataset?.tableMessage !== undefined) this.status = children[0]; }
        replaceChildren(...children) { this.children = children; }
        addEventListener(name, callback) { this[name] = callback; }
        focus() { this.focused = true; }
        closest(selector) { return selector === '[data-table-pagination] a[href]' ? this : null; }
        contains(node) { return node === this || node.parent === this; }
        querySelector(selector) {
            if (selector === 'table tbody, .account-list, .case-list, .import-list') return this.body;
            if (selector === ':scope > [data-table-message]') return this.status;
            if (selector.startsWith('[rel=')) return this.link;
            return null;
        }
        querySelectorAll() { return []; }
    }
    function makeRoot(anchor, pageName, sizeName, url) {
        const root = new Element();
        root.id = anchor;
        root.body = new Element();
        root.body.page = url.searchParams.get(pageName) || '1';
        const replaceBody = next => { root.body = next; next.replaceWith = replaceBody; };
        root.body.replaceWith = replaceBody;
        const pager = new Element();
        pager.dataset = { tableAnchor: anchor, tablePage: pageName, tableSize: sizeName };
        pager.replaceWith = next => { root.pager = next; next.replaceWith = pager.replaceWith; };
        pager.link = new Element();
        const target = new URL(url);
        target.searchParams.set(pageName, String(Number(root.body.page) + 1));
        pager.link.href = target.href;
        pager.link.rel = 'next';
        pager.link.parent = pager;
        root.pager = pager;
        return root;
    }
    const initial = new URL('https://example.test/admin/inventory');
    for (const args of [['inventory-table', 'page', 'per_page'], ['movement-logs', 'ledger_page', 'ledger_rows']]) roots.set(args[0], makeRoot(...args, initial));
    const window = {
        location: initial, scrollX: 0, scrollY: 350, scrollTo() {},
        history: { state: null, pushState(state, _, url) { this.state = state; window.location = new URL(url); } },
        addEventListener(name, callback) { windowHandlers[name] = callback; },
        fetch: async url => ({ ok: !fail, status: fail ? 500 : 200, redirected: false,
            headers: { get: () => 'text/html' }, text: async () => url }),
    };
    const document = {
        getElementById: id => roots.get(id),
        querySelectorAll(selector) {
            if (selector === '[data-table-pagination]') return [...roots.values()].map(root => root.pager);
            if (selector === '[data-table-summary-for]') return [];
            return [...roots.values()].filter(root => root.attributes['aria-busy'] === 'true');
        },
        createElement: () => new Element(), createTextNode: text => ({ textContent: text }),
        importNode: node => node,
        addEventListener(name, callback) { handlers[name] = callback; },
        dispatchEvent() {},
    };
    const parser = class {
        parseFromString(html) {
            const url = new URL(html);
            const fresh = new Map([
                ['inventory-table', makeRoot('inventory-table', 'page', 'per_page', url)],
                ['movement-logs', makeRoot('movement-logs', 'ledger_page', 'ledger_rows', url)],
            ]);
            return { getElementById: id => fresh.get(id), querySelectorAll: () => [...fresh.values()].map(root => root.pager) };
        }
    };
    runInNewContext(source, { document, window, URL, FormData, DOMParser: parser, AbortController, setTimeout, clearTimeout,
        CustomEvent: class { constructor(type, data) { this.type = type; this.detail = data.detail; } } });
    const tick = async () => { for (let i = 0; i < 20; i += 1) await Promise.resolve(); };
    return {
        roots, window, fail: value => { fail = value; },
        async next(anchor) {
            const event = { target: roots.get(anchor).pager.link, button: 0, preventDefault() { this.prevented = true; } };
            handlers.click(event); await tick(); return event;
        },
        async back(url) { window.location = new URL(url); windowHandlers.popstate(); await tick(); },
    };
}

test('Next updates only its table, keeps panel identity and scroll, and Back restores rows in place', async () => {
    const app = browser();
    const panel = app.roots.get('inventory-table');
    const otherBody = app.roots.get('movement-logs').body;
    panel.unsavedDraft = 'Work in progress';
    const event = await app.next('inventory-table');
    assert.equal(event.prevented, true);
    assert.equal(panel.body.page, '2');
    assert.equal(app.roots.get('inventory-table'), panel);
    assert.equal(app.roots.get('movement-logs').body, otherBody);
    assert.equal(panel.unsavedDraft, 'Work in progress');
    assert.equal(app.window.scrollY, 350);
    assert.equal(app.window.location.search, '?page=2');
    await app.back('https://example.test/admin/inventory');
    assert.equal(panel.body.page, '1');
    assert.equal(panel.unsavedDraft, 'Work in progress');
});

test('a failed request keeps current rows and URL with a retry control', async () => {
    const app = browser();
    const panel = app.roots.get('inventory-table');
    const body = panel.body;
    app.fail(true);
    await app.next('inventory-table');
    assert.equal(panel.body, body);
    assert.equal(app.window.location.search, '');
    assert.match(panel.status.children[0].textContent, /could not be loaded/);
    assert.equal(panel.status.children[1].textContent, 'Retry');
    assert.equal(panel.attributes['aria-busy'], undefined);
});
