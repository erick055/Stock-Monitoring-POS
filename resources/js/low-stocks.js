const applySettingsButton = document.querySelector('[data-apply-settings]');
const alertsToast = document.querySelector('[data-alerts-toast]');

applySettingsButton?.addEventListener('click', () => {
    if (!alertsToast) return;
    alertsToast.hidden = false;
    window.setTimeout(() => {
        alertsToast.hidden = true;
    }, 2200);
});
