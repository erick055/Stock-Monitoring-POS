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
    const discountField = form.querySelector('[data-discount-field]');
    const bundleField = form.querySelector('[data-bundle-field]');
    const bundleSearch = bundleField?.querySelector('[data-bundle-search]');
    const bundleProductId = bundleField?.querySelector('[data-bundle-product-id]');
    const preview = form.querySelector('[data-price-preview]');

    function updatePreview() {
        const basePrice = Number(preview?.dataset.basePrice || 0);
        const discount = Math.min(90, Math.max(0, Number(discountInput?.value || 0)));
        const promotionalPrice = actionInput.value === 'promo_bundle' ? 0 : basePrice * (1 - (discount / 100));
        if (preview) preview.textContent = `Regular P${basePrice.toFixed(2)} -> POS price P${promotionalPrice.toFixed(2)}`;
    }

    actionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            actionInput.value = button.dataset.promotionAction;
            actionButtons.forEach((entry) => entry.classList.toggle('selected', entry === button));
            const isBundle = actionInput.value === 'promo_bundle';
            if (bundleField) bundleField.hidden = !isBundle;
            if (discountField) discountField.hidden = isBundle;
            if (discountInput) discountInput.required = !isBundle;
            if (bundleSearch) bundleSearch.required = isBundle;
            updatePreview();
        });
    });

    bundleSearch?.addEventListener('input', () => {
        const option = [...document.querySelectorAll('#bundle-product-options option')]
            .find((entry) => entry.value === bundleSearch.value);
        if (bundleProductId) bundleProductId.value = option?.dataset.productId || '';
        bundleSearch.setCustomValidity('');
    });

    discountInput?.addEventListener('input', updatePreview);
    form.addEventListener('submit', (event) => {
        if (actionInput.value === 'promo_bundle' && !bundleProductId?.value) {
            event.preventDefault();
            bundleSearch?.setCustomValidity('Select a product from the suggestions.');
            bundleSearch?.reportValidity();
            return;
        }
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

document.querySelectorAll('[data-archive-dead-stock]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Archive this item from the active dead-stock queue? Inventory and POS availability will not change.')) event.preventDefault();
    });
});

document.querySelectorAll('[data-restore-dead-stock]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Restore this item to the active dead-stock queue?')) event.preventDefault();
    });
});
