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
const reviewDialog = document.querySelector('[data-bulk-review-dialog]');
if (reviewDialog && bulkModal) {
    const frame = reviewDialog.querySelector('iframe');
    const launcher = bulkModal.querySelector('[data-bulk-review-launch]');
    launcher.target = frame.name;
    launcher.addEventListener('submit', () => {
        launcher.querySelectorAll('[name="popup"]').forEach(input => { input.value = '1'; });
        closeModal(bulkModal);
        reviewDialog.showModal();
    });
    reviewDialog.querySelector('[data-close-bulk-review]').addEventListener('click', () => reviewDialog.close());
    frame.addEventListener('load', () => {
        if (frame.contentDocument?.querySelector('[data-bulk-review-finished]')) {
            bulkSelection.clear();
            syncBulkSelection();
            window.location.reload();
        }
    });
}

const bulkSelection = new Set();
const selectionOwner = document.body?.dataset?.supplierSelectionOwner;
const selectionKey = selectionOwner && `motosync:supplier-selection:${selectionOwner}:${window.location.pathname}`;
const currentPriceList = () => document.querySelector('#supplier-prices')?.dataset.publishedPriceList;
let publishedPriceList = currentPriceList();
if (selectionKey) {
    try {
        const saved = JSON.parse(window.sessionStorage.getItem(selectionKey) ?? 'null');
        if (saved?.publishedPriceList === publishedPriceList && Array.isArray(saved?.ids)) {
            saved.ids.filter(id => typeof id === 'string' && /^[1-9]\d*$/.test(id))
                .forEach(id => bulkSelection.add(id));
        }
    } catch {
        // Keep selection in memory when browser storage is unavailable.
    }
}
const selectedBulkIds = () => [...bulkSelection];
const syncBulkSelection = () => {
    if (selectionKey) {
        try {
            window.sessionStorage.setItem(selectionKey, JSON.stringify({ publishedPriceList, ids: selectedBulkIds() }));
        } catch {
            // In-place pagination still works without browser storage.
        }
    }
    const visible = bulkCheckboxes();
    visible.forEach(checkbox => {
        checkbox.checked = bulkSelection.has(checkbox.value);
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
const refreshBulkSelection = async () => {
    const ids = selectedBulkIds();
    if (!ids.length || !bulkButton) return;

    try {
        const url = bulkButton.dataset.selectionUrl;
        const token = bulkModal.querySelector('input[name="_token"]').value;
        const eligible = new Set();
        for (let offset = 0; offset < ids.length; offset += 500) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ supplier_price_ids: ids.slice(offset, offset + 500) }),
            });
            if (!response.ok) return;
            const result = await response.json();
            result.ids.forEach(id => eligible.add(String(id)));
        }
        ids.forEach(id => {
            if (!eligible.has(id)) bulkSelection.delete(id);
        });
        syncBulkSelection();
    } catch {
        // Preserve selections if the server could not verify them.
    }
};
document.addEventListener('motosync:table-updated', () => {
    const priceList = currentPriceList();
    if (priceList !== publishedPriceList) {
        publishedPriceList = priceList;
        bulkSelection.clear();
    }
    syncBulkSelection();
    void refreshBulkSelection();
});
window.addEventListener('pageshow', event => {
    if (event.persisted) void refreshBulkSelection();
});
bulkSelectAll?.addEventListener('change', () => {
    bulkCheckboxes().forEach((checkbox) => {
        if (bulkSelectAll.checked) bulkSelection.add(checkbox.value);
        else bulkSelection.delete(checkbox.value);
    });
    syncBulkSelection();
});
syncBulkSelection();
void refreshBulkSelection();
bulkButton?.addEventListener('click', async () => {
    await refreshBulkSelection();
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
const review = document.querySelector('.supplier-review');
if (review) {
    const form = review.querySelector('#supplier-review-form');
    const list = form.querySelector('.supplier-review-list');
    const details = form.querySelector('.supplier-review-details');
    const progress = review.querySelector('[data-review-progress]');
    const create = review.querySelector('[data-review-create]');
    const status = review.querySelector('[data-review-status]');
    const unsaved = review.querySelector('[data-review-unsaved]');
    const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    let saving = false;

    function updatePrice() {
        const markup = details.querySelector('[data-review-markup]');
        const price = details.querySelector('[data-review-price]');
        if (!markup || !price) return;
        price.textContent = markup.validity.valid && Number.isFinite(markup.valueAsNumber)
            ? peso.format(Number(price.dataset.cost) * (1 + markup.valueAsNumber / 100))
            : '—';
    }

    form.addEventListener('input', () => {
        updatePrice();
        create.disabled = true;
        unsaved.hidden = false;
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving) return;
        const data = new FormData(form, event.submitter);
        const controls = [...form.querySelectorAll('button, input')].filter(control => !control.disabled);
        const createWasDisabled = create.disabled;
        saving = true;
        create.disabled = true;
        form.setAttribute('aria-busy', 'true');
        controls.forEach(control => { control.disabled = true; });
        status.textContent = 'Saving…';
        try {
            const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' });
            if (!response.ok) throw new Error('Save failed');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextForm = page.querySelector('#supplier-review-form');
            const nextCreate = page.querySelector('[data-review-create]');
            const nextProgress = page.querySelector('[data-review-progress]');
            const nextList = nextForm?.querySelector('.supplier-review-list');
            const nextDetails = nextForm?.querySelector('.supplier-review-details');
            if (!nextList || !nextDetails || !nextCreate || !nextProgress) throw new Error('Review unavailable');
            const scroll = list.scrollTop;
            form.action = nextForm.action;
            list.replaceChildren(...nextList.childNodes);
            list.scrollTop = scroll;
            details.replaceChildren(...nextDetails.childNodes);
            progress.textContent = nextProgress.textContent;
            create.disabled = nextCreate.disabled;
            unsaved.hidden = true;
            status.textContent = details.querySelector('[role="alert"]') ? 'Check the highlighted details.' : 'Draft saved.';
            window.history.replaceState(null, '', response.url);
            updatePrice();
            details.focus({ preventScroll: true });
        } catch {
            create.disabled = createWasDisabled;
            status.textContent = 'Could not update the review. Your entries are still here. Try again.';
        } finally {
            controls.forEach(control => { control.disabled = false; });
            form.removeAttribute('aria-busy');
            saving = false;
        }
    });

    updatePrice();
}
