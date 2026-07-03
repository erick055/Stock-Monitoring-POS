const compatibilityButton = document.querySelector('[data-refresh-compatibility]');
const compatibilityToast = document.querySelector('[data-compatibility-toast]');

compatibilityButton?.addEventListener('click', () => {
    if (!compatibilityToast) return;
    compatibilityToast.hidden = false;
    window.setTimeout(() => {
        compatibilityToast.hidden = true;
    }, 2200);
});
