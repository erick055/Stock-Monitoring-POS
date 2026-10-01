const liveInventoryPage = document.querySelector('[data-live-inventory-page]');

if (liveInventoryPage) {
    let currentVersion = liveInventoryPage.dataset.inventoryVersion;
    let refreshPending = false;

    function userIsEditing() {
        const active = document.activeElement;
        const formFieldActive = active?.matches?.('input, textarea, select');
        const dialogOpen = document.body.classList.contains('modal-open')
            || document.querySelector(':popover-open, [data-fallback-open]');

        return Boolean(formFieldActive || dialogOpen || document.hidden);
    }

    async function checkInventory() {
        try {
            const response = await fetch(liveInventoryPage.dataset.liveInventoryUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) return;
            const snapshot = await response.json();
            if (!currentVersion) {
                currentVersion = snapshot.version;
                return;
            }
            if (snapshot.version !== currentVersion) refreshPending = true;
            if (refreshPending && !userIsEditing()) backgroundReload();
        } catch (error) {
            // Keep the current data visible and try again on the next interval.
        }
    }

    window.setInterval(checkInventory, 5000);
    window.setTimeout(checkInventory, 800);
}
import { backgroundReload } from './session-activity';

