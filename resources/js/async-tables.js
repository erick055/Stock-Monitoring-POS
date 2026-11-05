export function tableUrl(current, target, config, form = null) {
    const url = new URL(current);
    const destination = new URL(target, url);
    if (destination.origin !== url.origin || destination.pathname !== url.pathname) return null;
    if (form) {
        const values = new FormData(form);
        if (form.closest('[data-table-pagination]')) {
            url.searchParams.set(config.size, values.get(config.size));
        } else {
            // Filter controls belong to this table. Other tables keep their current page.
            const names = form.dataset?.tableFilter ? form.dataset.tableFilter.split(',')
                : [...form.elements].filter(field => field.name && !field.disabled).map(field => field.name);
            for (const name of new Set(names)) {
                url.searchParams.delete(name);
                for (const value of values.getAll(name)) url.searchParams.append(name, value);
            }
        }
        url.searchParams.delete(config.page);
    } else {
        url.searchParams.delete(config.page);
        if (destination.searchParams.has(config.page)) url.searchParams.set(config.page, destination.searchParams.get(config.page));
    }
    url.hash = config.anchor;
    return url;
}

export class TableRequest {
    constructor(fetcher) { this.fetcher = fetcher; this.sequence = 0; }
    async load(url) {
        this.controller?.abort();
        this.controller = new AbortController();
        const controller = this.controller;
        const sequence = ++this.sequence;
        const timer = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await this.fetcher(url.href, {
                credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            if (sequence !== this.sequence) return null;
            if (response.status === 429) throw new Error(`Please wait ${response.headers.get('Retry-After') || 'a few'} seconds before trying again.`);
            if (response.status === 401 || response.status === 419 || (response.redirected && new URL(response.url).pathname !== url.pathname)) {
                throw new Error('Your session may have expired. Sign in again in another tab, then retry.');
            }
            if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) throw new Error('The table could not be loaded. Please try again.');
            const html = await response.text();
            return sequence === this.sequence ? html : null;
        } finally { clearTimeout(timer); }
    }
}

export function installAsyncTables() {
    const request = new TableRequest(window.fetch.bind(window));
    let documentUrl = new URL(window.location.href);
    let navigation = 0;
    const configs = () => [...document.querySelectorAll('[data-table-pagination]')].map(pager => ({
        pager, anchor: pager.dataset.tableAnchor, page: pager.dataset.tablePage, size: pager.dataset.tableSize,
        root: document.getElementById(pager.dataset.tableAnchor),
    })).filter(config => config.root);
    const content = root => root.querySelector('table tbody, .account-list, .case-list, .import-list');
    const message = (root, text, retry = null) => {
        let status = root.querySelector(':scope > [data-table-message]');
        if (!status) {
            status = document.createElement('div');
            status.dataset.tableMessage = '';
            status.className = 'table-update-message';
            status.setAttribute('role', 'status');
            root.append(status);
        }
        status.replaceChildren(document.createTextNode(text));
        if (retry) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'Retry';
            button.addEventListener('click', retry, { once: true });
            status.append(button);
        }
    };
    const parameters = config => [config.page, config.size, ...[...config.root.querySelectorAll('form[method="GET"], form[method="get"]')]
        .filter(form => !form.closest('[data-table-pagination]'))
        .flatMap(form => form.dataset?.tableFilter ? form.dataset.tableFilter.split(',') : [...form.elements].map(field => field.name).filter(Boolean))];
    async function update(url, targets, push = true, focusRel = null) {
        const operation = ++navigation;
        const scroll = { left: window.scrollX, top: window.scrollY };
        const originalValues = new WeakMap();
        targets.forEach(({ root }) => root.querySelectorAll('form[method="GET"], form[method="get"]').forEach(form => {
            for (const field of form.elements) originalValues.set(field, field.value);
        }));
        document.querySelectorAll('[data-table-busy][aria-busy="true"]').forEach(root => {
            root.removeAttribute('aria-busy');
            message(root, '');
        });
        targets.forEach(({ root }) => { root.dataset.tableBusy = ''; root.setAttribute('aria-busy', 'true'); message(root, 'Loading table…'); });
        try {
            const html = await request.load(url);
            if (!html || operation !== navigation) return;
            const page = new DOMParser().parseFromString(html, 'text/html');
            const replacements = targets.map(config => {
                const freshRoot = page.getElementById(config.anchor);
                const freshPager = [...page.querySelectorAll('[data-table-pagination]')].find(pager => pager.dataset.tablePage === config.page);
                if (!freshRoot || !content(freshRoot) || !freshPager || !content(config.root)) throw new Error('The table could not be loaded. Please try again.');
                if (config.root.querySelector('dialog[open], [data-fallback-open]')) throw new Error('Close the open window before changing this table.');
                return { config, freshRoot, freshPager, associated: page.getElementById(`${config.anchor}-associated`) };
            });
            for (const { config, freshRoot, freshPager, associated } of replacements) {
                // Keep the panel, filter controls, page forms, cart, and history tab listeners.
                content(config.root).replaceWith(document.importNode(content(freshRoot), true));
                config.pager.replaceWith(document.importNode(freshPager, true));
                if (associated) document.getElementById(`${config.anchor}-associated`)?.replaceWith(document.importNode(associated, true));
                for (const selector of ['.section-heading small', '.ledger-count']) {
                    const current = config.root.querySelector(selector);
                    const fresh = freshRoot.querySelector(selector);
                    if (current && fresh) current.textContent = fresh.textContent;
                }
                for (const form of config.root.querySelectorAll('form[method="GET"], form[method="get"]')) {
                    if (form.closest('[data-table-pagination]')) continue;
                    const freshForm = [...freshRoot.querySelectorAll('form[method="GET"], form[method="get"]')].find(candidate => candidate.className === form.className);
                    if (!freshForm) continue;
                    for (const field of form.elements) {
                        const fresh = freshForm.elements.namedItem(field.name);
                        if (!field.name || !fresh || !('value' in fresh) || ['submit', 'button'].includes(field.type)) continue;
                        if (field.value !== originalValues.get(field)) continue;
                        if (push && field.name !== config.size && field.value !== fresh.value) continue;
                        field.value = fresh.value;
                        if (field.tagName === 'SELECT') [...field.options].forEach(option => { option.defaultSelected = option.value === fresh.value; });
                        else if ('defaultValue' in field) field.defaultValue = fresh.value;
                    }
                }
                message(config.root, 'Table updated.');
                document.dispatchEvent(new CustomEvent('motosync:table-updated', { detail: { root: config.root, url: url.href } }));
            }
            document.querySelectorAll('[data-table-summary-for]').forEach(summary => {
                if (!targets.some(config => config.anchor === summary.dataset.tableSummaryFor)) return;
                const fresh = page.getElementById(summary.id);
                if (fresh) summary.replaceWith(document.importNode(fresh, true));
            });
            if (push) window.history.pushState({ ...window.history.state, tableNavigation: true }, '', url.href);
            documentUrl = new URL(url);
            if (focusRel) {
                const fresh = configs().find(config => config.anchor === targets[0].anchor)?.pager;
                const focus = fresh?.querySelector(`[rel="${focusRel}"]`) ?? fresh;
                if (focus === fresh) focus?.setAttribute('tabindex', '-1');
                focus?.focus({ preventScroll: true });
            }
            window.scrollTo(scroll);
        } catch (error) {
            if (operation !== navigation) return;
            const text = error.name === 'AbortError' ? 'Loading timed out. Please try again.' : error.message;
            targets.forEach(({ root }) => message(root, text, () => update(url, configs().filter(config => targets.some(target => target.anchor === config.anchor)), push, focusRel)));
        } finally {
            targets.forEach(({ root }) => { if (operation === navigation || !root.isConnected) root.removeAttribute('aria-busy'); });
            // A cancelled request can belong to a different table.
            if (operation === navigation) document.querySelectorAll('[aria-busy="true"][data-table-busy]').forEach(root => root.removeAttribute('aria-busy'));
        }
    }
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-table-pagination] a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
        const config = configs().find(config => config.pager.contains(link));
        if (!config) return;
        const url = tableUrl(window.location.href, link.href, config);
        if (!url) return;
        event.preventDefault();
        update(url, [config], true, link.rel);
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (form.method.toLowerCase() !== 'get' || event.defaultPrevented) return;
        const config = configs().find(config => config.pager.contains(form) || (config.root.contains(form) && (form.matches('[data-table-filter]') || form.querySelector('[name="per_page"]'))));
        if (!config) return;
        const url = tableUrl(window.location.href, form.action, config, form);
        if (!url) return;
        event.preventDefault();
        const sharedFilters = ['search', 'sort', 'status', 'category', 'classification'];
        const sharedChanged = sharedFilters.some(name => new URL(window.location.href).searchParams.get(name) !== url.searchParams.get(name));
        update(url, sharedChanged ? configs() : [config]);
    });
    window.addEventListener('popstate', () => {
        const url = new URL(window.location.href);
        const sharedChanged = ['search', 'sort', 'status', 'category', 'classification'].some(name => documentUrl.searchParams.get(name) !== url.searchParams.get(name));
        const changed = configs().filter(config => sharedChanged || parameters(config).some(name => documentUrl.searchParams.get(name) !== url.searchParams.get(name)));
        if (changed.length) update(url, changed, false);
        else {
            navigation += 1;
            request.controller?.abort();
            document.querySelectorAll('[data-table-busy][aria-busy="true"]').forEach(root => { root.removeAttribute('aria-busy'); message(root, ''); });
            documentUrl = url;
        }
    });
}

if (typeof document !== 'undefined') installAsyncTables();
