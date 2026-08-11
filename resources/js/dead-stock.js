const refreshSummaryButton = document.querySelector('[data-refresh-summary]');
const deadStockToast = document.querySelector('[data-dead-stock-toast]');

function showDeadStockToast() {
    if (!deadStockToast) return;
    deadStockToast.hidden = false;
    window.setTimeout(() => {
        deadStockToast.hidden = true;
    }, 2200);
}

refreshSummaryButton?.addEventListener('click', showDeadStockToast);
document.querySelectorAll('[data-ai-action]').forEach((button) => {
    button.addEventListener('click', showDeadStockToast);
});

document.querySelectorAll('[data-promotion-form]').forEach((form) => {
    const actionInput = form.querySelector('[data-action-type]');
    const actionButtons = form.querySelectorAll('[data-promotion-action]');
    const discountInput = form.querySelector('[name="discount_percent"]');
    const bundleField = form.querySelector('[data-bundle-field]');
    const bundleInput = bundleField?.querySelector('input');
    const preview = form.querySelector('[data-price-preview]');

    function updatePreview() {
        const basePrice = Number(preview?.dataset.basePrice || 0);
        const discount = Math.min(90, Math.max(0, Number(discountInput?.value || 0)));
        const promotionalPrice = basePrice * (1 - (discount / 100));
        if (preview) preview.textContent = `Regular P${basePrice.toFixed(2)} -> POS price P${promotionalPrice.toFixed(2)}`;
    }

    actionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            actionInput.value = button.dataset.promotionAction;
            actionButtons.forEach((entry) => entry.classList.toggle('selected', entry === button));
            const isBundle = actionInput.value === 'promo_bundle';
            if (bundleField) bundleField.hidden = !isBundle;
            if (bundleInput) bundleInput.required = isBundle;
        });
    });

    discountInput?.addEventListener('input', updatePreview);
    form.addEventListener('submit', (event) => {
        const label = form.querySelector('[data-promotion-action].selected')?.textContent?.trim() || 'promotion';
        if (!window.confirm(`Apply this ${label} to the POS price?`)) event.preventDefault();
    });
    updatePreview();
});

document.querySelectorAll('[data-end-promotion]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('End this offer and restore the regular POS price?')) event.preventDefault();
    });
});
