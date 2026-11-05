import { money, plural } from './formatters';
const liveInventoryPage = document.querySelector('[data-live-inventory-page]');

if (liveInventoryPage) {
    let currentVersion = liveInventoryPage.dataset.inventoryVersion;
    let checking = false;
    let retryAt = 0;
    const number = new Intl.NumberFormat('en-PH');
    const snapshotUrl = new URL(liveInventoryPage.dataset.liveInventoryUrl, window.location.href);
    snapshotUrl.searchParams.set('view', 'balances');

    function userIsEditing() {
        if (document.hidden || document.activeElement?.matches?.('input, textarea, select')) return true;
        if (document.body.classList.contains('modal-open') || document.body.classList.contains('supplier-modal-open')) return true;
        if (document.querySelector('dialog[open], [data-fallback-open], [data-archive-selection]:checked')) return true;
        if (typeof HTMLElement.prototype.showPopover === 'function' && document.querySelector(':popover-open')) return true;
        return [...document.querySelectorAll('form')].some((form) => {
            if (form.closest('[hidden], dialog:not([open])')) return false;
            return [...form.elements].some((field) => {
                if (field.disabled || ['hidden', 'submit', 'button', 'reset'].includes(field.type)) return false;
                if (field.tagName === 'SELECT') {
                    const initial = [...field.options].find((option) => option.defaultSelected) ?? field.options[0];
                    return field.value !== (initial?.value ?? '');
                }
                if (['checkbox', 'radio'].includes(field.type)) return field.checked !== field.defaultChecked;
                return ['INPUT', 'TEXTAREA'].includes(field.tagName) && field.value !== field.defaultValue;
            });
        });
    }

    const setText = (root, selector, text) => root.querySelectorAll(selector).forEach(node => { node.textContent = text; });
    function applyBalances(snapshot) {
        const balances = new Map(snapshot.balances.map((product) => [String(product.id), product]));
        document.querySelectorAll('[data-live-product-id]').forEach((row) => {
            const product = balances.get(row.dataset.liveProductId);
            if (!product) return; // Preserve the row and selection until the user chooses to refresh the list.
            setText(row, '[data-live-stock]', plural(product.stock, 'unit'));
            setText(row, '[data-live-reorder]', number.format(product.reorder));
            setText(row, '[data-live-cost]', money.format(product.unitCost));
            setText(row, '[data-live-price]', money.format(product.unitPrice));
            setText(row, '[data-live-profit]', money.format(product.unitPrice - product.unitCost));
            setText(row, '[data-live-value]', money.format(product.stock * product.unitCost));
            row.querySelectorAll('[data-live-status]').forEach((status) => {
                const catalog = status.classList.contains('product-status');
                status.classList.remove('healthy', 'warning', 'critical', 'active', 'low');
                status.classList.add(catalog ? (product.status === 'healthy' ? 'active' : 'low') : product.status);
                status.textContent = product.status === 'warning' ? 'Low stock' : product.status === 'healthy' ? (catalog ? 'In stock' : 'Healthy') : 'Critical';
            });
            row.querySelectorAll('.stock-level i').forEach(bar => { bar.style.width = `${Math.min(100, product.reorder ? product.stock / (product.reorder * 3) * 100 : 100)}%`; });
            const edit = row.querySelector('[data-edit-product]');
            if (edit) Object.assign(edit.dataset, { productStock: product.stock, productUnitCost: product.unitCost, productUnitPrice: product.unitPrice, productReorderLevel: product.reorder });
            const remove = row.querySelector('[data-delete-product]');
            if (remove) remove.dataset.productStock = product.stock;
        });
        document.querySelectorAll('[data-movement-product] option').forEach((option) => {
            const product = balances.get(option.value);
            if (!product) return;
            option.dataset.stock = product.stock;
            option.textContent = option.textContent.replace(/\([^()]* currently\)$/, `(${number.format(product.stock)} currently)`);
        });
        Object.entries(snapshot.summary ?? {}).forEach(([key, value]) => {
            const text = ['stock_value', 'average_profit'].includes(key) ? money.format(value) : number.format(value);
            setText(document, `[data-live-summary="${key}"]`, text);
        });
        const notice = document.querySelector('[data-inventory-update-notice]');
        if (notice) notice.hidden = false;
    }

    document.querySelector('[data-inventory-manual-refresh]')?.addEventListener('click', () => window.location.reload());
    async function checkInventory() {
        if (checking || Date.now() < retryAt || userIsEditing()) return;
        checking = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(snapshotUrl.href, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
            if (response.status === 429) {
                retryAt = Date.now() + Math.max(1, Number(response.headers?.get('Retry-After')) || 60) * 1000;
                return;
            }
            if (!response.ok) return;
            const snapshot = await response.json();
            if (snapshot.version === currentVersion || userIsEditing()) return;
            if (!Array.isArray(snapshot.balances)) return;
            applyBalances(snapshot);
            currentVersion = snapshot.version;
        } catch {
            // Keep the current page and retry later; a network problem never reloads it.
        } finally {
            checking = false;
            window.clearTimeout(timeout);
        }
    }
    window.setInterval(checkInventory, 20000);
    window.setTimeout(checkInventory, 1500);
}
