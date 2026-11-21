document.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('.inline-option-form')) return;
    event.preventDefault();
    if (form.getAttribute('aria-busy') === 'true') return;

    const select = form.querySelector('select');
    const field = select.name;
    const data = new FormData(form);
    const controls = [...form.querySelectorAll('button, input, select')].filter(control => !control.disabled);
    let message = form.querySelector('[data-inline-save-status]');
    if (!message) {
        message = document.createElement('span');
        message.dataset.inlineSaveStatus = '';
        message.setAttribute('role', 'status');
        form.append(message);
    }
    form.setAttribute('aria-busy', 'true');
    controls.forEach(control => { control.disabled = true; });
    message.textContent = 'Saving…';
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();
        if (!response.ok) {
            throw new Error(Object.values(result.errors ?? {}).flat()[0] ?? result.message ?? 'Save failed.');
        }
        const value = result.value ?? '';
        if (value) {
            document.querySelectorAll(`.inline-option-form select[name="${field}"]`).forEach(other => {
                if (![...other.options].some(option => option.value === value)) {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    other.add(option, other.querySelector('option[value="__new__"]'));
                }
            });
        }
        select.value = value;
        const newField = form.querySelector('[data-new-option-field]');
        if (newField) {
            newField.hidden = true;
            const input = newField.querySelector('input');
            input.value = '';
            input.disabled = true;
        }
        const edit = form.closest('tr')?.querySelector('[data-edit-product]');
        if (edit) edit.dataset[field === 'category' ? 'productCategory' : 'productShelfLocation'] = value;
        message.textContent = 'Saved';
    } catch (error) {
        message.textContent = error instanceof SyntaxError
            ? 'Could not save. Check your connection or sign in again.'
            : error.message;
    } finally {
        controls.forEach(control => {
            if (!control.closest('[data-new-option-field]')?.hidden) control.disabled = false;
        });
        form.removeAttribute('aria-busy');
    }
});
