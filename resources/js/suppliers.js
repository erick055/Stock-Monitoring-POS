const supplierFile = document.querySelector('[data-supplier-file]');
const importMode = document.querySelector('[data-supplier-import-mode]');
function syncImportMode() {
    const ai = importMode?.value === 'ai';
    document.querySelector('[data-ai-import-settings]')?.toggleAttribute('hidden', !ai);
    const currency = document.querySelector('[data-ai-import-settings] input');
    if (currency) { currency.required = ai; currency.disabled = !ai; }
    document.querySelector('[data-ai-import-note]')?.toggleAttribute('hidden', !ai);
    if (supplierFile) supplierFile.accept = ai ? '.pdf,.docx,.txt,.csv,.xlsx,.png,.jpg,.jpeg,.webp' : '.csv,.xlsx';
    const label = document.querySelector('[data-file-name]');
    if (label && !supplierFile?.files?.length) label.textContent = ai ? 'Choose a document, image, or spreadsheet' : 'Choose CSV or XLSX file';
}
importMode?.addEventListener('change', syncImportMode);
syncImportMode();
document.querySelector('[data-supplier-upload-form]')?.addEventListener('submit', (event) => {
    const button = event.target.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = importMode.value === 'ai' ? 'AI is reading the document…' : 'Detecting columns and importing…';
});
document.querySelector('[data-supplier-page-size]')?.addEventListener('change', (event) => event.target.form.requestSubmit());
const supplierFileName = document.querySelector('[data-file-name]');

supplierFile?.addEventListener('change', () => {
    if (supplierFileName) {
        supplierFileName.textContent = supplierFile.files?.[0]?.name || 'Choose CSV or XLSX file';
    }
});

const supplierPurgeForm = document.querySelector('[data-supplier-purge]');
const supplierPurgeDialog = document.querySelector('#supplier-purge-dialog');
document.querySelector('[data-open-supplier-purge]')?.addEventListener('click', () => {
    supplierPurgeDialog.showModal();
    document.body.classList.add('supplier-modal-open');
    supplierPurgeDialog.querySelector('[data-purge-password]')?.focus();
});
supplierPurgeDialog?.querySelectorAll('[data-close-supplier-purge]').forEach(button => {
    button.addEventListener('click', () => supplierPurgeDialog.close());
});
supplierPurgeDialog?.addEventListener('close', () => {
    supplierPurgeForm?.reset();
    document.body.classList.remove('supplier-modal-open');
});

supplierPurgeForm?.addEventListener('submit', (event) => {
    if (!window.confirm('Clear all published supplier prices? Original import rows will remain in the archive.')) {
        event.preventDefault();
    }
});

const catalogOptions = [...document.querySelectorAll('#catalog-product-options option')];
const catalogProductIds = new Map(catalogOptions.map((option) => [option.value.trim().toLowerCase(), option.dataset.productId]));

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

const bulkCheckboxes = () => [...document.querySelectorAll('[data-bulk-product-checkbox]')];
const bulkSelectAll = document.querySelector('[data-bulk-select-all]');
const bulkButton = document.querySelector('[data-open-bulk-products]');
const bulkCount = document.querySelector('[data-bulk-count]');
const bulkModal = document.querySelector('[data-bulk-products-modal]');
const bulkModalCount = bulkModal?.querySelector('[data-bulk-modal-count]');
const bulkIds = bulkModal?.querySelector('[data-bulk-product-ids]');

const bulkSelection = new Set();
const bulkProductDetails = new Map();
const bulkOpeningStocks = new Map();
const selectedBulkIds = () => [...bulkSelection];
const syncBulkSelection = () => {
    const visible = bulkCheckboxes();
    visible.forEach(checkbox => {
        checkbox.checked = bulkSelection.has(checkbox.value);
        bulkProductDetails.set(checkbox.value, `${checkbox.dataset.productName} (${checkbox.dataset.productSku})`);
    });
    const count = selectedBulkIds().length;
    if (bulkCount) bulkCount.textContent = String(count);
    if (bulkButton) bulkButton.disabled = count === 0;
    if (bulkSelectAll) {
        const checked = visible.filter(checkbox => checkbox.checked).length;
        bulkSelectAll.checked = visible.length > 0 && checked === visible.length;
        bulkSelectAll.indeterminate = checked > 0 && checked < visible.length;
        bulkSelectAll.disabled = visible.length === 0;
    }
};

document.addEventListener('change', event => {
    if (!event.target.matches('[data-bulk-product-checkbox]')) return;
    if (event.target.checked) bulkSelection.add(event.target.value);
    else bulkSelection.delete(event.target.value);
    syncBulkSelection();
});
document.addEventListener('motosync:table-updated', syncBulkSelection);
bulkSelectAll?.addEventListener('change', () => {
    bulkCheckboxes().forEach((checkbox) => {
        if (bulkSelectAll.checked) bulkSelection.add(checkbox.value);
        else bulkSelection.delete(checkbox.value);
    });
    syncBulkSelection();
});
syncBulkSelection();
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
    const stocks = bulkModal.querySelector('[data-bulk-opening-stocks]');
    stocks?.replaceChildren(...ids.map(id => {
        const label = document.createElement('label');
        label.textContent = `${bulkProductDetails.get(id) ?? id} — Opening stock`;
        const input = document.createElement('input');
        input.type = 'number';
        input.name = `initial_stocks[${id}]`;
        input.min = '0';
        input.max = '2147483647';
        input.step = '1';
        input.required = true;
        input.value = bulkOpeningStocks.get(id) ?? '0';
        input.addEventListener('input', () => bulkOpeningStocks.set(id, input.value));
        label.append(input);
        return label;
    }));
    if (bulkModalCount) bulkModalCount.textContent = String(ids.length);
    openModal(bulkModal);
});

const initializedSupplierControls = new Map();
function newSupplierControls(selector) {
    const seen = initializedSupplierControls.get(selector) ?? new WeakSet();
    initializedSupplierControls.set(selector, seen);
    return [...document.querySelectorAll(selector)].filter(node => {
        if (seen.has(node)) return false;
        seen.add(node);
        return true;
    });
}
function initializeSupplierRows() {
    const rowDialogs = [...newSupplierControls('[data-import-row-dialog]')];
    function openRowDialog(dialog) {
        if (!dialog || dialog.open) return;
        dialog.showModal();
        document.body.classList.add('supplier-modal-open');
    }
    newSupplierControls('[data-open-row-review]').forEach(button => button.addEventListener('click', () => openRowDialog(document.getElementById(button.dataset.openRowReview))));
    rowDialogs.forEach(dialog => {
        dialog.querySelector('[data-close-row-review]')?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => document.body.classList.remove('supplier-modal-open'));
    });
    openRowDialog(rowDialogs.find(dialog => dialog.dataset.openOnError === 'true'));

    const priceDialogs = [...newSupplierControls('[data-supplier-price-dialog]')];
    function openPriceDialog(dialog) {
        if (!dialog || dialog.open) return;
        dialog.showModal();
        document.body.classList.add('supplier-modal-open');
    }
    newSupplierControls('[data-open-price-dialog]').forEach((button) => {
        button.addEventListener('click', () => openPriceDialog(document.getElementById(button.dataset.openPriceDialog)));
    });
    priceDialogs.forEach((dialog) => {
        dialog.querySelectorAll('[data-close-price-dialog]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.querySelectorAll('form').forEach((form) => {
            const marker = document.createElement('input');
            marker.type = 'hidden';
            marker.name = '_supplier_price_dialog';
            marker.value = dialog.dataset.priceId;
            form.append(marker);
        });
        dialog.addEventListener('close', () => {
            if (!document.querySelector('[data-supplier-price-dialog][open]') && !document.querySelector('.supplier-modal:not([hidden])')) {
                document.body.classList.remove('supplier-modal-open');
            }
        });
    });
    openPriceDialog(priceDialogs.find((dialog) => dialog.dataset.openOnError === 'true'));

    newSupplierControls('[data-catalog-match-form]').forEach((form) => {
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

    newSupplierControls('[data-apply-supplier-cost]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = `Apply ${form.dataset.cost} as the unit cost for ${form.dataset.productName}? Selling price and inventory quantity will not change.`;
            if (!window.confirm(message)) event.preventDefault();
        });
    });

    newSupplierControls('[data-unmatch-product]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = `Unmatch ${form.dataset.supplierItem} from ${form.dataset.productName}? Product prices and inventory will not be changed.`;
            if (!window.confirm(message)) event.preventDefault();
        });
    });


}
initializeSupplierRows();
document.addEventListener('motosync:table-updated', initializeSupplierRows);

// Delegated for supplier dialogs replaced by table pagination.
document.addEventListener('change', (event) => {
    if (!event.target.matches('[data-catalog-option-select]')) return;
    const field = event.target.closest('[data-catalog-option]').querySelector('[data-catalog-option-new]');
    const input = field.querySelector('input');
    const creating = event.target.value === '__new__';
    field.hidden = !creating;
    input.disabled = !creating;
    input.required = creating;
    if (creating) input.focus();
});
