// Automatic reloads are marked so the server does not count them as user activity.
export function backgroundReload() {
    const url = new URL(window.location.href);
    url.searchParams.set('_background', '1');
    window.location.replace(url.href);
}

const activityUrl = document.querySelector('meta[name="session-activity-url"]')?.content;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
if (activityUrl && csrfToken) {
    const statusUrl = new URL(activityUrl, window.location.href);
    statusUrl.pathname = statusUrl.pathname.replace(/\/session\/activity\/?$/, '/session/status');
    const loginUrl = new URL(activityUrl, window.location.href);
    loginUrl.pathname = loginUrl.pathname.replace(/\/session\/activity\/?$/, '/');
    let checkingSession = false;
    const checkSession = async () => {
        if (document.hidden || checkingSession) return;
        checkingSession = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(statusUrl.href, {
                headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal,
            });
            if (response.status === 401 || response.status === 419 || response.redirected) {
                window.location.replace(loginUrl.href);
            }
        } catch {
            // A connection failure alone does not sign the user out.
        } finally {
            window.clearTimeout(timeout);
            checkingSession = false;
        }
    };
    window.setInterval(checkSession, 10000);
    window.addEventListener('focus', checkSession);
    document.addEventListener('visibilitychange', checkSession);

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
