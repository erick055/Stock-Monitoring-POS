const supplierFile = document.querySelector('[data-supplier-file]');
const supplierFileName = document.querySelector('[data-file-name]');

supplierFile?.addEventListener('change', () => {
    if (supplierFileName) {
        supplierFileName.textContent = supplierFile.files?.[0]?.name || 'Choose CSV or XLSX file';
    }
});

const supplierPurgeForm = document.querySelector('[data-supplier-purge]');

supplierPurgeForm?.addEventListener('submit', (event) => {
    if (!window.confirm('Clear all published supplier prices? Original import rows will remain in the archive.')) {
        event.preventDefault();
    }
});

const catalogOptions = [...document.querySelectorAll('#catalog-product-options option')];
const catalogProductIds = new Map(catalogOptions.map((option) => [option.value.trim().toLowerCase(), option.dataset.productId]));

document.querySelectorAll('[data-catalog-match-form]').forEach((form) => {
    const search = form.querySelector('[data-catalog-match-search]');
    const productId = form.querySelector('[data-catalog-product-id]');
    if (!search || !productId) return;

    const syncProduct = () => {
        productId.value = catalogProductIds.get(search.value.trim().toLowerCase()) || '';
        search.setCustomValidity('');
    };

    search.addEventListener('input', syncProduct);
    search.addEventListener('change', syncProduct);
    form.addEventListener('submit', (event) => {
        syncProduct();
        if (!productId.value) {
            event.preventDefault();
            search.setCustomValidity('Choose a product from the search suggestions.');
            search.reportValidity();
        }
    });
});

document.querySelectorAll('[data-apply-supplier-cost]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = `Apply ${form.dataset.cost} as the unit cost for ${form.dataset.productName}? Selling price and inventory quantity will not change.`;
        if (!window.confirm(message)) event.preventDefault();
    });
});

document.querySelectorAll('[data-unmatch-product]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = `Unmatch ${form.dataset.supplierItem} from ${form.dataset.productName}? Product prices and inventory will not be changed.`;
        if (!window.confirm(message)) event.preventDefault();
    });
});

const openModal = (modal) => {
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('supplier-modal-open');
    modal.querySelector('button, input')?.focus();
};

const closeModal = (modal) => {
    if (!modal) return;
    modal.hidden = true;
    if (!document.querySelector('.supplier-modal:not([hidden])')) document.body.classList.remove('supplier-modal-open');
};

document.querySelector('[data-open-import-decision]')?.addEventListener('click', () => {
    openModal(document.querySelector('[data-import-decision-modal]'));
});

document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', () => closeModal(button.closest('.supplier-modal')));
});

document.querySelectorAll('.supplier-modal').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal(document.querySelector('.supplier-modal:not([hidden])'));
});

const bulkCheckboxes = [...document.querySelectorAll('[data-bulk-product-checkbox]')];
const bulkSelectAll = document.querySelector('[data-bulk-select-all]');
const bulkButton = document.querySelector('[data-open-bulk-products]');
const bulkCount = document.querySelector('[data-bulk-count]');
const bulkModal = document.querySelector('[data-bulk-products-modal]');
const bulkModalCount = bulkModal?.querySelector('[data-bulk-modal-count]');
const bulkIds = bulkModal?.querySelector('[data-bulk-product-ids]');

const selectedBulkIds = () => bulkCheckboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value);
const syncBulkSelection = () => {
    const count = selectedBulkIds().length;
    if (bulkCount) bulkCount.textContent = String(count);
    if (bulkButton) bulkButton.disabled = count === 0;
    if (bulkSelectAll) {
        bulkSelectAll.checked = bulkCheckboxes.length > 0 && count === bulkCheckboxes.length;
        bulkSelectAll.indeterminate = count > 0 && count < bulkCheckboxes.length;
    }
};

bulkCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', syncBulkSelection));
bulkSelectAll?.addEventListener('change', () => {
    bulkCheckboxes.forEach((checkbox) => {
        checkbox.checked = bulkSelectAll.checked;
    });
    syncBulkSelection();
});
bulkButton?.addEventListener('click', () => {
    const ids = selectedBulkIds();
    if (!ids.length || !bulkIds) return;
    bulkIds.replaceChildren(...ids.map((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'supplier_price_ids[]';
        input.value = id;
        return input;
    }));
    if (bulkModalCount) bulkModalCount.textContent = String(ids.length);
    openModal(bulkModal);
});
