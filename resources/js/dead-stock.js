import { currency } from './formatters';
const refreshSummaryButton = document.querySelector('[data-refresh-summary]');
const deadStockToast = document.querySelector('[data-dead-stock-toast]');

let deadStockDialogTrigger = null;
function closeDeadStockDialog(dialog) {
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
    document.body.classList.remove('deadstock-dialog-open');
    deadStockDialogTrigger?.focus();
}

function showDeadStockToast() {
    if (!deadStockToast) return;
    deadStockToast.hidden = false;
    window.setTimeout(() => {
        deadStockToast.hidden = true;
    }, 2200);
}

refreshSummaryButton?.addEventListener('click', () => window.location.reload());

const initializedDeadStockControls = new Map();
function newDeadStockControls(selector) {
    const seen = initializedDeadStockControls.get(selector) ?? new WeakSet();
    initializedDeadStockControls.set(selector, seen);
    return [...document.querySelectorAll(selector)].filter(node => {
        if (seen.has(node)) return false;
        seen.add(node);
        return true;
    });
}
function initializeDeadStockRows() {
    newDeadStockControls('[data-deadstock-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.deadstockOpen);
            if (!dialog) return;
            deadStockDialogTrigger = button;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else {
                dialog.setAttribute('open', '');
                dialog.setAttribute('role', 'dialog');
                dialog.setAttribute('aria-modal', 'true');
            }
            document.body.classList.add('deadstock-dialog-open');
            dialog.querySelector('button, input, textarea, select')?.focus();
        });
    });

    newDeadStockControls('.deadstock-dialog').forEach((dialog) => {
        dialog.querySelectorAll('[data-deadstock-close]').forEach((button) => {
            button.addEventListener('click', () => closeDeadStockDialog(dialog));
        });
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeDeadStockDialog(dialog);
        });
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
                closeDeadStockDialog(dialog);
            }
        });
        dialog.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDeadStockDialog(dialog);
            }
            if (event.key !== 'Tab') return;
            const controls = [...dialog.querySelectorAll('button, input:not([type="hidden"]), textarea, select, a[href]')]
                .filter((control) => !control.disabled && control.getClientRects().length);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
    });

    newDeadStockControls('[data-ai-action]').forEach((button) => {
        button.addEventListener('click', showDeadStockToast);
    });

    newDeadStockControls('[data-promotion-form]').forEach((form) => {
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
            if (preview) preview.textContent = `Regular ${currency(basePrice)} → POS price ${currency(promotionalPrice)}`;
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

    newDeadStockControls('[data-end-promotion]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('End this offer and restore the regular POS price?')) event.preventDefault();
        });
    });

    newDeadStockControls('[data-archive-dead-stock]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Archive this item from the active dead-stock queue? Inventory and POS availability will not change.')) event.preventDefault();
        });
    });

    newDeadStockControls('[data-restore-dead-stock]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Restore this item to the active dead-stock queue?')) event.preventDefault();
        });
    });

}
initializeDeadStockRows();
document.addEventListener('motosync:table-updated', initializeDeadStockRows);
