const alertsToast = document.querySelector('[data-alerts-toast]');

if (alertsToast) {
    window.setTimeout(() => {
        alertsToast.hidden = true;
    }, 3500);
}
