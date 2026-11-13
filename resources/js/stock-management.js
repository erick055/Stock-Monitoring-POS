import { plural } from './formatters';
import './inventory-live-reload';
import './stock-archive';
import './archived-products';

const statusFilter = document.querySelector('[data-status-filter]');
const filterForm = document.querySelector('[data-filter-form]');
const modal = document.querySelector('[data-product-modal]');
const firstProductInput = modal?.querySelector('input[name="sku"]');

statusFilter?.addEventListener('change', () => filterForm?.requestSubmit());

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

const categorySelect = modal?.querySelector('[data-category-select]');
const newCategoryField = modal?.querySelector('[data-new-category-field]');
const newCategoryInput = newCategoryField?.querySelector('input');

function updateNewCategoryField() {
    const addingCategory = categorySelect?.value === '__new__';
    if (!newCategoryField || !newCategoryInput) return;
    newCategoryField.hidden = !addingCategory;
    newCategoryInput.disabled = !addingCategory;
    newCategoryInput.required = addingCategory;
    if (addingCategory) window.setTimeout(() => newCategoryInput.focus(), 0);
}

categorySelect?.addEventListener('change', updateNewCategoryField);
updateNewCategoryField();

function updateNewOptionField(select, focus = false) {
    const field = document.querySelector(`[data-new-option-field="${select.dataset.newField}"]`);
    const input = field?.querySelector('input');
    if (!field || !input) return;
    const adding = select.value === select.dataset.newValue;
    field.hidden = !adding;
    input.disabled = !adding;
    input.required = adding;
    if (adding && focus) window.setTimeout(() => input.focus(), 0);
}

document.querySelectorAll('[data-option-select]').forEach((select) => {
    select.addEventListener('change', () => updateNewOptionField(select, true));
    updateNewOptionField(select);
});

const editModal = document.querySelector('[data-edit-product-modal]');
const editForm = editModal?.querySelector('[data-edit-product-form]');
const editTitle = editModal?.querySelector('[data-edit-product-title]');
const editStock = editModal?.querySelector('[data-edit-product-stock]');
const editPromotionNote = editModal?.querySelector('[data-edit-promotion-note]');

function setEditValue(name, value) {
    const field = editForm?.elements.namedItem(name);
    if (!field) return;
    const requestedValue = value || '';
    if (field instanceof HTMLSelectElement && requestedValue && ![...field.options].some((option) => option.value === requestedValue)) {
        const option = new Option(requestedValue, requestedValue);
        field.add(option, field.options[field.options.length - 1]);
    }
    field.value = requestedValue;
    if (field.matches('[data-option-select]')) updateNewOptionField(field);
}

function showEditProductModal() {
    if (!editModal) return;
    editModal.hidden = false;
    document.body.classList.add('modal-open');
    window.setTimeout(() => editForm?.querySelector('input[name="sku"]')?.focus(), 0);
}

function openEditProductModal(button) {
    if (!editModal || !editForm) return;

    editForm.action = button.dataset.editAction;
    setEditValue('edit_product_id', button.dataset.productId);
    setEditValue('sku', button.dataset.productSku);
    setEditValue('name', button.dataset.productName);
    setEditValue('category', button.dataset.productCategory);
    setEditValue('shelf_location', button.dataset.productShelfLocation);
    setEditValue('manufacturer', button.dataset.productManufacturer);
    setEditValue('manufacturer_part_number', button.dataset.productManufacturerPartNumber);
    setEditValue('description', button.dataset.productDescription);
    setEditValue('unit_cost', button.dataset.productUnitCost);
    setEditValue('unit_price', button.dataset.productUnitPrice);
    setEditValue('reorder_level', button.dataset.productReorderLevel);
    editTitle.textContent = button.dataset.productName;
    editStock.textContent = plural(Number(button.dataset.productStock || 0), 'unit');
    editModal.querySelector('.modal-form-errors')?.setAttribute('hidden', '');

    const promotion = button.dataset.productPromotion;
    editPromotionNote.hidden = !promotion;
    editPromotionNote.textContent = promotion
        ? `${promotion} is active. Its promotional price will continue to control the POS price until the promotion ends.`
        : '';
    showEditProductModal();
}

function closeEditProductModal() {
    if (!editModal) return;
    editModal.hidden = true;
    document.body.classList.remove('modal-open');
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-edit-product]');
    if (button) openEditProductModal(button);
});
document.querySelectorAll('[data-close-edit-product]').forEach((button) => button.addEventListener('click', closeEditProductModal));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && editModal && !editModal.hidden) closeEditProductModal();
});

if (editModal?.dataset.openOnError === 'true') {
    const failedProductId = editForm?.elements.namedItem('edit_product_id')?.value;
    const failedButton = document.querySelector(`[data-edit-product][data-product-id="${failedProductId}"]`);
    if (failedButton) {
        editTitle.textContent = failedButton.dataset.productName;
        editStock.textContent = plural(Number(failedButton.dataset.productStock || 0), 'unit');
        const promotion = failedButton.dataset.productPromotion;
        editPromotionNote.hidden = !promotion;
        editPromotionNote.textContent = promotion
            ? `${promotion} is active. Its promotional price will continue to control the POS price until the promotion ends.`
            : '';
    }
    showEditProductModal();
}

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

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-delete-product]');
    if (button) openDeleteProductModal(button);
});
document.querySelectorAll('[data-close-delete-product]').forEach((button) => button.addEventListener('click', closeDeleteProductModal));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && deleteModal && !deleteModal.hidden) closeDeleteProductModal();
});

const movementForm = document.querySelector('[data-movement-form]');

if (movementForm) {
    const typeInput = movementForm.querySelector('[data-movement-type]');
    const productInput = movementForm.querySelector('[data-movement-product]');
    const productPicker = movementForm.querySelector('[data-movement-product-picker]');
    const productTrigger = productPicker.querySelector('summary');
    const productResults = productPicker.querySelector('[data-movement-product-results]');
    const productSearch = movementForm.querySelector('[data-movement-product-search]');
    const productSearchStatus = movementForm.querySelector('[data-movement-product-search-status]');
    const productOptions = Array.from(productInput.querySelectorAll('option[data-search]'), (option) => ({
        option,
        searchText: option.dataset.search.toLowerCase(),
    }));
    const quantityInput = movementForm.querySelector('[data-movement-quantity]');
    const reasonInput = movementForm.querySelector('[data-movement-reason]');
    const reasonOptions = [...reasonInput.querySelectorAll('option[data-types]')];
    const oldReason = reasonInput.dataset.oldReason;
    const configurations = {
        in: {
            title: 'Stock In adds units to the selected product.',
            text: 'Use this for supplier deliveries, customer returns accepted back into stock, or recovered inventory.',
            quantityLabel: 'Quantity received (+)', quantityHelp: 'Use - or + to add or deduct',
            counterparty: 'Supplier / source', placeholder: 'Supplier, customer, or stock source', button: 'Record stock in', sign: 1,
        },
        out: {
            title: 'Stock Out removes units from the selected product.',
            text: 'Use this for manual releases, damaged goods, supplier returns, or internal shop use. Stock cannot go below zero.',
            quantityLabel: 'Quantity released (−)', quantityHelp: 'Use - or + to add or deduct',
            counterparty: 'Recipient / destination', placeholder: 'Customer, supplier, department, or destination', button: 'Record stock out', sign: -1,
        },
        adjustment: {
            title: 'Adjustment corrects the recorded balance by a difference.',
            text: 'Enter a positive number to add missing units or a negative number to remove excess units after a physical count.',
            quantityLabel: 'Quantity difference (+/−)', quantityHelp: 'Use - or + to add or deduct',
            counterparty: 'Counted / authorized by', placeholder: 'Person who verified or authorized the correction', button: 'Save stock adjustment', sign: null,
        },
    };

    function selectedType() {
        return typeInput.value || 'in';
    }

    function filterProducts() {
        const query = productSearch.value.trim().toLowerCase();
        const terms = query ? query.split(/\s+/) : [];
        const results = document.createDocumentFragment();
        productOptions.forEach(({ option, searchText }) => {
            if (!terms.every((term) => searchText.includes(term))) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.value = option.value;
            button.textContent = option.textContent;
            button.setAttribute('aria-pressed', String(option.selected));
            results.append(button);
        });
        productSearchStatus.textContent = results.childElementCount ? '' : 'No products found.';
        productResults.replaceChildren(results);
        productResults.scrollTop = 0;
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

        movementForm.querySelector('[data-current-stock]').textContent = hasProduct ? plural(stock, 'unit') : '—';
        movementForm.querySelector('[data-stock-change]').textContent = hasQuantity ? `${change > 0 ? '+' : ''}${plural(change, 'unit')}` : '—';
        const resultElement = movementForm.querySelector('[data-resulting-stock]');
        resultElement.textContent = hasProduct && hasQuantity ? plural(result, 'unit') : '—';
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
        quantityInput.placeholder = type === 'adjustment' ? 'Example: +5 or -2' : 'Enter a positive quantity';

        reasonOptions.forEach((option) => {
            const available = option.dataset.types.split(' ').includes(type);
            option.hidden = !available;
            option.disabled = !available;
        });
        if (reasonInput.selectedOptions[0]?.disabled) reasonInput.value = '';
        updateResult();
    }

    typeInput.addEventListener('change', updateMovementType);
    productInput.addEventListener('change', () => {
        productTrigger.textContent = productInput.selectedOptions[0].textContent;
        productTrigger.removeAttribute('aria-invalid');
        updateResult();
    });
    productInput.addEventListener('invalid', (event) => {
        event.preventDefault();
        productTrigger.setAttribute('aria-invalid', 'true');
        productPicker.open = true;
        productSearch.focus();
    });
    productPicker.addEventListener('toggle', () => {
        if (!productPicker.open) return;
        productSearch.value = '';
        filterProducts();
        productSearch.focus();
    });
    productPicker.addEventListener('focusout', (event) => {
        if (!productPicker.contains(event.relatedTarget)) productPicker.open = false;
    });
    productPicker.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !productPicker.open) return;
        event.preventDefault();
        productPicker.open = false;
        productTrigger.focus();
    });
    productResults.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        productInput.value = button.value;
        productInput.dispatchEvent(new Event('change', { bubbles: true }));
        productPicker.open = false;
        productTrigger.focus();
    });
    productSearch.addEventListener('input', filterProducts);
    productSearch.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        productResults.querySelector('button')?.focus();
    });
    productTrigger.textContent = productInput.selectedOptions[0].textContent;
    productInput.hidden = true;
    productPicker.hidden = false;
    quantityInput.addEventListener('input', updateResult);
    updateMovementType();
    if (oldReason && [...reasonInput.options].some((option) => option.value === oldReason && !option.disabled)) reasonInput.value = oldReason;

    const movementDialog = document.querySelector('[data-movement-dialog]');
    const titles = { in: 'Stock In', out: 'Stock Out', adjustment: 'Stock Adjustment' };
    function openMovement(type) {
        if (!movementDialog || !configurations[type]) return;
        typeInput.value = type;
        updateMovementType();
        movementDialog.querySelector('[data-movement-dialog-title]').textContent = titles[type];
        movementDialog.showModal();
        document.body.classList.add('modal-open');
        productTrigger.textContent = productInput.selectedOptions[0].textContent;
        productTrigger.focus();
    }
    document.querySelectorAll('[data-open-movement]').forEach((button) => {
        button.addEventListener('click', () => {
            if (typeInput.value !== button.dataset.openMovement) {
                quantityInput.value = '';
                reasonInput.value = '';
            }
            openMovement(button.dataset.openMovement);
        });
    });
    movementDialog?.querySelectorAll('[data-close-movement]').forEach((button) => {
        button.addEventListener('click', () => movementDialog.close());
    });
    movementDialog?.addEventListener('close', () => document.body.classList.remove('modal-open'));
    if (movementDialog?.dataset.openOnError === 'true') openMovement(selectedType());
}
