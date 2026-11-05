const archiveDialog = document.querySelector('[data-bulk-archive-dialog]');
const selections = () => [...document.querySelectorAll('[data-archive-selection]')];
const selectPage = document.querySelector('[data-select-page]');
const openArchive = document.querySelector('[data-open-bulk-archive]');

if (archiveDialog && selectPage && openArchive) {
    const selected = () => selections().filter((input) => input.checked);
    const sync = () => {
        const count = selected().length;
        document.querySelector('[data-selected-count]').textContent = `${count} selected`;
        openArchive.disabled = count === 0;
        selectPage.disabled = selections().length === 0;
        selectPage.checked = count > 0 && count === selections().length;
        selectPage.indeterminate = count > 0 && count < selections().length;
    };
    document.addEventListener('change', (event) => { if (event.target.matches('[data-archive-selection]')) sync(); });
    document.addEventListener('motosync:table-updated', sync);
    selectPage.addEventListener('change', () => {
        selections().forEach((input) => { input.checked = selectPage.checked; });
        sync();
    });
    openArchive.addEventListener('click', () => {
        const products = selected();
        if (!products.length) return;
        const form = archiveDialog.querySelector('form');
        form.reset();
        const list = archiveDialog.querySelector('[data-archive-list]');
        const inputs = archiveDialog.querySelector('[data-archive-inputs]');
        list.replaceChildren();
        inputs.replaceChildren();
        products.forEach((product) => {
            const item = document.createElement('li');
            item.textContent = product.dataset.productLabel;
            list.append(item);
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = product.value;
            inputs.append(input);
        });
        archiveDialog.querySelector('[data-archive-summary]').textContent = `Archive ${products.length} selected product(s)?`;
        archiveDialog.showModal();
        document.body.classList.add('modal-open');
    });
    archiveDialog.querySelector('[data-cancel-bulk-archive]').addEventListener('click', () => archiveDialog.close());
    archiveDialog.addEventListener('close', () => {
        archiveDialog.querySelector('form').reset();
        document.body.classList.remove('modal-open');
    });
    sync();
}
