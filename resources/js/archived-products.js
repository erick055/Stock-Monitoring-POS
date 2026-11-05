const panel = document.getElementById('archived-products');
const dialog = document.querySelector('[data-archived-action-dialog]');

if (panel && dialog) {
    const form = dialog.querySelector('form');
    const selectAll = panel.querySelector('[data-select-archived-page]');
    const boxes = () => [...panel.querySelectorAll('[data-archived-selection]')];
    const selected = () => boxes().filter(box => box.checked);
    const sync = () => {
        const count = selected().length;
        panel.querySelector('[data-archived-selected-count]').textContent = `${count} selected`;
        panel.querySelectorAll('[data-archived-action]').forEach(button => { button.disabled = count === 0; });
        selectAll.disabled = boxes().length === 0;
        selectAll.checked = count > 0 && count === boxes().length;
        selectAll.indeterminate = count > 0 && count < boxes().length;
    };
    const open = (action, preserve = false) => {
        const products = selected();
        if (!products.length || !['restore', 'delete'].includes(action)) return;
        if (!preserve) form.reset();
        const deleting = action === 'delete';
        dialog.querySelector('[data-archived-action-title]').textContent = deleting ? 'Permanently delete archived products' : 'Restore archived products';
        dialog.querySelector('[data-archived-action-description]').textContent = deleting
            ? 'This cannot be undone. Selected product records and their movement logs will be deleted. Products linked to sales, returns, damages, or held orders cannot be permanently deleted.'
            : 'Selected products will return to active products and POS with their existing quantities. Previous promotions will stay ended.';
        dialog.querySelector('[data-archived-action-value]').value = action;
        const confirmation = dialog.querySelector('[data-archived-delete-confirm]');
        confirmation.hidden = !deleting;
        confirmation.querySelector('input').required = deleting;
        confirmation.querySelector('input').disabled = !deleting;
        const submit = dialog.querySelector('[data-submit-archived-action]');
        submit.textContent = deleting ? 'Delete permanently' : 'Restore products';
        submit.className = deleting ? 'danger-button' : 'primary-button';
        const list = dialog.querySelector('[data-archived-action-list]');
        const inputs = dialog.querySelector('[data-archived-action-inputs]');
        list.replaceChildren();
        inputs.replaceChildren();
        products.forEach(product => {
            const item = document.createElement('li');
            item.textContent = product.dataset.productLabel;
            list.append(item);
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'archived_product_ids[]';
            input.value = product.value;
            inputs.append(input);
        });
        dialog.showModal();
        document.body.classList.add('modal-open');
        form.querySelector('textarea').focus();
    };
    panel.addEventListener('change', event => {
        if (event.target === selectAll) boxes().forEach(box => { box.checked = selectAll.checked; });
        sync();
    });
    panel.addEventListener('click', event => {
        const button = event.target.closest('[data-archived-action]');
        if (button) open(button.dataset.archivedAction);
    });
    document.addEventListener('motosync:table-updated', sync);
    dialog.querySelector('[data-cancel-archived-action]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { form.reset(); document.body.classList.remove('modal-open'); });
    sync();
    if (dialog.dataset.openAction) open(dialog.dataset.openAction, true);
}
