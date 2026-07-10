const filterForm = document.querySelector('[data-products-filter]');
const detailsModal = document.querySelector('[data-product-details]');

document.querySelectorAll('[data-auto-submit]').forEach((select) => select.addEventListener('change', () => filterForm?.submit()));

function closeDetails() {
    if (!detailsModal) return;
    detailsModal.hidden = true;
    document.body.classList.remove('details-open');
}

document.querySelectorAll('[data-view-product]').forEach((button) => {
    button.addEventListener('click', () => {
        if (!detailsModal) return;
        const product = JSON.parse(button.dataset.product);
        const title = detailsModal.querySelector('[data-detail-name]');
        if (title) title.textContent = product.name;
        Object.entries(product).forEach(([key, value]) => {
            const field = detailsModal.querySelector(`[data-detail="${key}"]`);
            if (!field) return;
            field.textContent = ['unitCost', 'unitPrice'].includes(key) ? `₱${value}` : key === 'stock' ? `${value} units` : key === 'margin' ? `${value}%` : key === 'id' ? `#${value}` : value;
        });
        detailsModal.hidden = false;
        document.body.classList.add('details-open');
        detailsModal.querySelector('[data-close-details]')?.focus();
    });
});

document.querySelectorAll('[data-close-details]').forEach((button) => button.addEventListener('click', closeDetails));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && detailsModal && !detailsModal.hidden) closeDetails();
});
