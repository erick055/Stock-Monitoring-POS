// Small popup tables already have their complete snapshot in the page.
document.querySelectorAll('[data-client-table]').forEach((table) => {
    const rows = [...table.tBodies[0].rows];
    const controls = document.createElement('div');
    controls.className = 'ml-table-pagination';
    const label = document.createElement('label');
    label.textContent = 'Rows per page ';
    const size = document.createElement('select');
    size.setAttribute('aria-label', 'Rows per page');
    for (const value of [20, 50, 100]) size.add(new Option(String(value), String(value)));
    label.append(size);
    const previous = document.createElement('button');
    const next = document.createElement('button');
    const status = document.createElement('span');
    status.setAttribute('aria-live', 'polite');
    previous.type = next.type = 'button';
    previous.textContent = 'Previous';
    next.textContent = 'Next';
    controls.append(label, previous, status, next);
    table.parentElement.after(controls);
    let page = 1;
    const render = () => {
        const limit = Number(size.value);
        const pages = Math.max(1, Math.ceil(rows.length / limit));
        page = Math.min(page, pages);
        rows.forEach((row, index) => { row.hidden = index < (page - 1) * limit || index >= page * limit; });
        previous.disabled = page === 1;
        next.disabled = page === pages;
        status.textContent = `${rows.length ? (page - 1) * limit + 1 : 0}–${Math.min(page * limit, rows.length)} of ${rows.length}`;
    };
    size.addEventListener('change', () => { page = 1; render(); });
    previous.addEventListener('click', () => { page -= 1; render(); });
    next.addEventListener('click', () => { page += 1; render(); });
    render();
});
