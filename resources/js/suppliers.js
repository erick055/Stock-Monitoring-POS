const supplierFile = document.querySelector('[data-supplier-file]');
const supplierFileName = document.querySelector('[data-file-name]');

supplierFile?.addEventListener('change', () => {
    if (supplierFileName) {
        supplierFileName.textContent = supplierFile.files?.[0]?.name || 'Choose CSV or XLSX file';
    }
});

const supplierPurgeForm = document.querySelector('[data-supplier-purge]');

supplierPurgeForm?.addEventListener('submit', (event) => {
    if (!window.confirm('Permanently delete every supplier price, supplier, and import record? Products and inventory will remain.')) {
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
