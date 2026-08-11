const supplierFile = document.querySelector('[data-supplier-file]');
const supplierFileName = document.querySelector('[data-file-name]');

supplierFile?.addEventListener('change', () => {
    if (supplierFileName) {
        supplierFileName.textContent = supplierFile.files?.[0]?.name || 'Choose CSV or XLSX file';
    }
});

const supplierPurgeForm = document.querySelector('[data-supplier-purge]');
const supplierPurgeConfirmation = supplierPurgeForm?.querySelector('[data-purge-confirmation]');
const supplierPurgeButton = supplierPurgeForm?.querySelector('[data-purge-button]');

supplierPurgeConfirmation?.addEventListener('input', () => {
    if (supplierPurgeButton) supplierPurgeButton.disabled = supplierPurgeConfirmation.value !== 'DELETE';
});

supplierPurgeForm?.addEventListener('submit', (event) => {
    if (!window.confirm('Permanently delete every supplier price, supplier, and import record? Products and inventory will remain.')) {
        event.preventDefault();
    }
});
