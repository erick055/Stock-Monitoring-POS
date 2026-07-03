const refreshSuppliersButton = document.querySelector('[data-refresh-suppliers]');
const suppliersToast = document.querySelector('[data-suppliers-toast]');

refreshSuppliersButton?.addEventListener('click', () => {
    if (!suppliersToast) return;
    suppliersToast.hidden = false;
    window.setTimeout(() => {
        suppliersToast.hidden = true;
    }, 2200);
});
