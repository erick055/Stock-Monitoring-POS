// Automatic reloads are marked so the server does not count them as user activity.
export function backgroundReload() {
    const url = new URL(window.location.href);
    url.searchParams.set('_background', '1');
    window.location.replace(url.href);
}

const activityUrl = document.querySelector('meta[name="session-activity-url"]')?.content;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
if (activityUrl && csrfToken) {
    // Clear the marker after the request; manual navigation remains ordinary activity.
    const url = new URL(window.location.href);
    if (url.searchParams.has('_background')) {
        url.searchParams.delete('_background');
        window.history.replaceState(window.history.state, '', url.href);
    }
    let lastPing = 0;
    let pending = false;
    const recordActivity = async (event) => {
        if (!event.isTrusted || document.hidden || pending || Date.now() - lastPing < 60000) return;
        pending = true;
        lastPing = Date.now();
        try {
            const response = await fetch(activityUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            if (response.status === 401 || response.status === 419) window.location.reload();
        } catch {
            // Keep the user's work visible during temporary connection failures.
        } finally {
            pending = false;
        }
    };
    for (const event of ['pointerdown', 'keydown', 'input', 'wheel']) {
        document.addEventListener(event, recordActivity, { passive: true });
    }
}
