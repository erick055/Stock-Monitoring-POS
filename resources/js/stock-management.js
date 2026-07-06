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
