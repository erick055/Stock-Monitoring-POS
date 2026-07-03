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
