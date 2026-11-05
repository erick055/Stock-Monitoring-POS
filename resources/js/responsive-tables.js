const tableWrappers = '.table-wrap, .products-table-wrap, .supplier-table-wrap, .future-outlook-table, .velocity-table-wrap, .published-price-table, .login-stock-alert-table';
const tracked = new Map();
const update = wrapper => {
    const state = tracked.get(wrapper);
    if (!state) return;
    const overflows = wrapper.clientWidth > 0 && wrapper.scrollWidth > wrapper.clientWidth + 1;
    state.hint.hidden = !overflows;
    wrapper.classList.toggle('table-can-scroll', overflows);
    if (!state.hadTabIndex) {
        if (overflows) wrapper.setAttribute('tabindex', '0');
        else wrapper.removeAttribute('tabindex');
    }
};
const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(() => tracked.forEach((_, wrapper) => update(wrapper))) : null;

function initializeResponsiveTables() {
    tracked.forEach((state, wrapper) => {
        if (!wrapper.isConnected) {
            observer?.unobserve(wrapper);
            observer?.unobserve(state.table);
            state.hint.remove();
            tracked.delete(wrapper);
        }
    });
    document.querySelectorAll(tableWrappers).forEach(wrapper => {
        const table = wrapper.querySelector('table');
        if (!table) return;
        const existing = tracked.get(wrapper);
        if (existing) {
            if (existing.table !== table) {
                observer?.unobserve(existing.table);
                existing.table = table;
                observer?.observe(table);
            }
            update(wrapper);
            return;
        }
        const hint = document.createElement('p');
        hint.className = 'table-scroll-hint';
        hint.hidden = true;
        const icon = document.createElement('i');
        icon.className = 'bi bi-arrow-left-right';
        icon.setAttribute('aria-hidden', 'true');
        hint.append(icon, document.createTextNode('Swipe or scroll sideways to view all columns.'));
        wrapper.before(hint);
        if (!wrapper.hasAttribute('role')) wrapper.setAttribute('role', 'region');
        if (!wrapper.hasAttribute('aria-label')) {
            const heading = wrapper.closest('section, article, dialog')?.querySelector('h2, h3');
            wrapper.setAttribute('aria-label', `${heading?.textContent.trim() || 'Data table'} — scrollable table`);
        }
        tracked.set(wrapper, { hint, table, hadTabIndex: wrapper.hasAttribute('tabindex') });
        observer?.observe(wrapper);
        observer?.observe(table);
        update(wrapper);
    });
}

initializeResponsiveTables();
window.addEventListener('resize', () => tracked.forEach((_, wrapper) => update(wrapper)));
document.addEventListener('motosync:table-updated', initializeResponsiveTables);
