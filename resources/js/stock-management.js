const statusFilter = document.querySelector('[data-status-filter]');
const filterForm = document.querySelector('[data-filter-form]');
const modal = document.querySelector('[data-product-modal]');
const firstProductInput = modal?.querySelector('input[name="sku"]');

statusFilter?.addEventListener('change', () => filterForm?.submit());

function openProductModal() {
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    window.setTimeout(() => firstProductInput?.focus(), 0);
}

function closeProductModal() {
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('modal-open');
}

document.querySelector('[data-open-product]')?.addEventListener('click', openProductModal);
document.querySelectorAll('[data-close-product]').forEach((button) => button.addEventListener('click', closeProductModal));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal && !modal.hidden) closeProductModal();
});

if (modal?.dataset.openOnError === 'true') openProductModal();

const deleteModal = document.querySelector('[data-delete-product-modal]');
const deleteForm = deleteModal?.querySelector('[data-delete-product-form]');
const deleteName = deleteModal?.querySelector('[data-delete-product-name]');
const deleteStock = deleteModal?.querySelector('[data-delete-product-stock]');
const deleteWarning = deleteModal?.querySelector('[data-delete-product-warning]');
const deleteSubmit = deleteModal?.querySelector('[data-delete-product-submit]');

function openDeleteProductModal(button) {
    if (!deleteModal || !deleteForm) return;

    const stock = Number(button.dataset.productStock || 0);
    const hasStock = stock !== 0;
    deleteForm.reset();
    deleteForm.action = button.dataset.deleteAction;
    deleteName.textContent = `${button.dataset.productName} (${button.dataset.productSku})`;
    deleteStock.textContent = `${stock.toLocaleString()} unit${stock === 1 ? '' : 's'} currently in stock`;
    deleteWarning.hidden = !hasStock;
    deleteSubmit.disabled = hasStock;
    deleteModal.hidden = false;
    document.body.classList.add('modal-open');
    window.setTimeout(() => deleteForm.querySelector(hasStock ? '[data-close-delete-product]' : 'textarea')?.focus(), 0);
}

function closeDeleteProductModal() {
    if (!deleteModal) return;
    deleteModal.hidden = true;
    document.body.classList.remove('modal-open');
}

document.querySelectorAll('[data-delete-product]').forEach((button) => {
    button.addEventListener('click', () => openDeleteProductModal(button));
});
document.querySelectorAll('[data-close-delete-product]').forEach((button) => button.addEventListener('click', closeDeleteProductModal));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && deleteModal && !deleteModal.hidden) closeDeleteProductModal();
});

const movementForm = document.querySelector('[data-movement-form]');

if (movementForm) {
    const typeInput = movementForm.querySelector('[data-movement-type]');
    const productInput = movementForm.querySelector('[data-movement-product]');
    const quantityInput = movementForm.querySelector('[data-movement-quantity]');
    const reasonInput = movementForm.querySelector('[data-movement-reason]');
    const reasonOptions = [...reasonInput.querySelectorAll('option[data-types]')];
    const oldReason = reasonInput.dataset.oldReason;
    const configurations = {
        in: {
            title: 'Stock In adds units to the selected product.',
            text: 'Use this for supplier deliveries, customer returns accepted back into stock, or recovered inventory.',
            quantityLabel: '3. Quantity received', quantityHelp: 'Entered units will be added to current stock.',
            counterparty: '6. Supplier / source', placeholder: 'Supplier, customer, or stock source', button: 'Record stock in', sign: 1,
        },
        out: {
            title: 'Stock Out removes units from the selected product.',
            text: 'Use this for manual releases, damaged goods, supplier returns, or internal shop use. Stock cannot go below zero.',
            quantityLabel: '3. Quantity released', quantityHelp: 'Entered units will be deducted from current stock.',
            counterparty: '6. Recipient / destination', placeholder: 'Customer, supplier, department, or destination', button: 'Record stock out', sign: -1,
        },
        adjustment: {
            title: 'Adjustment corrects the recorded balance by a difference.',
            text: 'Enter a positive number to add missing units or a negative number to remove excess units after a physical count.',
            quantityLabel: '3. Quantity difference (+/−)', quantityHelp: 'Examples: +5 adds five units; -2 removes two units.',
            counterparty: '6. Counted / authorized by', placeholder: 'Person who verified or authorized the correction', button: 'Save stock adjustment', sign: null,
        },
    };

    function selectedType() {
        return typeInput.value || 'in';
    }

    function updateResult() {
        const type = selectedType();
        const config = configurations[type];
        const stock = Number(productInput.selectedOptions[0]?.dataset.stock);
        const entered = Number(quantityInput.value);
        const hasProduct = productInput.value !== '' && Number.isFinite(stock);
        const hasQuantity = quantityInput.value !== '' && Number.isFinite(entered);
        const change = type === 'adjustment' ? entered : Math.abs(entered) * config.sign;
        const result = stock + change;

        movementForm.querySelector('[data-current-stock]').textContent = hasProduct ? `${stock} units` : '—';
        movementForm.querySelector('[data-stock-change]').textContent = hasQuantity ? `${change > 0 ? '+' : ''}${change} units` : '—';
        const resultElement = movementForm.querySelector('[data-resulting-stock]');
        resultElement.textContent = hasProduct && hasQuantity ? `${result} units` : '—';
        resultElement.classList.toggle('invalid-stock', hasProduct && hasQuantity && result < 0);
    }

    function updateMovementType() {
        const type = selectedType();
        const config = configurations[type];
        movementForm.querySelector('[data-guidance-title]').textContent = config.title;
        movementForm.querySelector('[data-guidance-text]').textContent = config.text;
        movementForm.querySelector('[data-quantity-label]').textContent = config.quantityLabel;
        movementForm.querySelector('[data-quantity-help]').textContent = config.quantityHelp;
        movementForm.querySelector('[data-counterparty-label]').textContent = config.counterparty;
        movementForm.querySelector('[data-counterparty-input]').placeholder = config.placeholder;
        movementForm.querySelector('[data-movement-submit]').textContent = config.button;
        quantityInput.min = type === 'adjustment' ? '' : '1';
        quantityInput.placeholder = type === 'adjustment' ? 'Example: -2 or 5' : 'Enter a positive quantity';

        reasonOptions.forEach((option) => {
            const available = option.dataset.types.split(' ').includes(type);
            option.hidden = !available;
            option.disabled = !available;
        });
        if (reasonInput.selectedOptions[0]?.disabled) reasonInput.value = '';
        updateResult();
    }

    typeInput.addEventListener('change', updateMovementType);
    productInput.addEventListener('change', updateResult);
    quantityInput.addEventListener('input', updateResult);
    updateMovementType();
    if (oldReason && [...reasonInput.options].some((option) => option.value === oldReason && !option.disabled)) reasonInput.value = oldReason;
}
