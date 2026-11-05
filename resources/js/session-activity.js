const activityUrl = document.querySelector('meta[name="session-activity-url"]')?.content;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
if (activityUrl && csrfToken && !window.__motosyncSessionTracker) {
    window.__motosyncSessionTracker = true;
    const base = new URL(activityUrl, window.location.href);
    const statusUrl = document.querySelector('meta[name="session-status-url"]')?.content
        || base.href.replace(/\/session\/activity\/?$/, '/session/status');
    const loginUrl = document.querySelector('meta[name="session-login-url"]')?.content
        || base.href.replace(/\/session\/activity\/?$/, '/');
    let initial = {};
    try { initial = JSON.parse(document.querySelector('meta[name="session-context"]')?.content || '{}'); } catch {}
    const storageKey = `motosync:session:${base.pathname}:${initial.scope || 'current'}`;
    let idleSeconds = Number(initial.idle_timeout_seconds) || 3600;
    let activityStamp = Number(initial.last_activity_at) || 0;
    let deadline = Date.now() + ((Number(initial.expires_at) - Number(initial.server_time)) * 1000 || idleSeconds * 1000);
    let nextStatusAt = Date.now() + 60000;
    let retrySessionAt = 0;
    let nextActivityAt = 0;
    let checkingSession = false;
    let recordingActivity = false;
    let failureDelay = 30000;
    let warning;

    function readShared() {
        try { return JSON.parse(window.localStorage.getItem(storageKey) || '{}'); } catch { return {}; }
    }
    function share(values) {
        try { window.localStorage.setItem(storageKey, JSON.stringify({ ...readShared(), ...values })); } catch {}
    }
    function adoptShared() {
        const shared = readShared();
        if (Number(shared.activityStamp) > activityStamp && Number.isFinite(shared.deadline)) {
            activityStamp = shared.activityStamp;
            deadline = shared.deadline;
            idleSeconds = Number(shared.idleSeconds) || idleSeconds;
        }
        retrySessionAt = Math.max(retrySessionAt, Number(shared.retrySessionAt) || 0);
        nextActivityAt = Math.max(nextActivityAt, Number(shared.nextActivityAt) || 0);
    }
    function acceptSnapshot(snapshot) {
        const expires = Number(snapshot.expires_at);
        const serverTime = Number(snapshot.server_time);
        const seconds = Number(snapshot.idle_timeout_seconds);
        const stamp = expires - seconds;
        if (!Number.isFinite(expires) || !Number.isFinite(serverTime) || !(seconds > 0) || stamp < activityStamp) return;
        activityStamp = stamp;
        idleSeconds = seconds;
        deadline = Date.now() + (expires - serverTime) * 1000;
        share({ activityStamp, deadline, idleSeconds });
        renderWarning();
    }
    function renderWarning() {
        if (document.hidden) return;
        if (deadline - Date.now() > 120000) {
            if (warning) warning.hidden = true;
            return;
        }
        if (!warning) {
            warning = document.createElement('section');
            warning.className = 'session-idle-warning';
            warning.setAttribute('role', 'alertdialog');
            warning.setAttribute('aria-labelledby', 'session-idle-title');
            warning.innerHTML = '<strong id="session-idle-title">Your session is about to expire</strong><p data-idle-message></p><button type="button">Stay signed in</button>';
            warning.querySelector('button').addEventListener('click', () => recordActivity(true));
            document.body.appendChild(warning);
        }
        // Native dialogs sit above the document; keep the warning actionable there too.
        const host = document.querySelector('dialog[open]') || document.body;
        if (warning.parentElement !== host) host.appendChild(warning);
        warning.hidden = false;
        warning.querySelector('[data-idle-message]').textContent = deadline > Date.now()
            ? `You will be signed out after ${Math.ceil((deadline - Date.now()) / 1000)} seconds without activity.`
            : 'Checking your session. Your work stays visible while the connection is unavailable.';
        warning.querySelector('button').disabled = recordingActivity;
    }
    const originalFetch = window.fetch?.bind(window) || fetch;
    // Normal navigation uses server-rendered context; successful fetch requests
    // use the same deadline headers, including forms submitted by other bundles.
    window.fetch = async (...args) => {
        const response = await originalFetch(...args);
        const target = new URL(typeof args[0] === 'string' || args[0] instanceof URL ? args[0] : args[0].url, window.location.href);
        if (target.origin === base.origin && response.ok) {
            acceptSnapshot({
                expires_at: response.headers?.get('X-Session-Expires-At'),
                server_time: response.headers?.get('X-Session-Time'),
                idle_timeout_seconds: response.headers?.get('X-Session-Timeout'),
            });
        }
        return response;
    };
    async function signOut(response) {
        let target = loginUrl;
        try {
            const payload = await response.json();
            if (payload.login_url && new URL(payload.login_url, base).origin === base.origin) target = payload.login_url;
        } catch {}
        window.location.replace(target);
    }
    function backOff(response) {
        const retry = response?.headers?.get('Retry-After');
        const retrySeconds = Number(retry);
        const retryDate = retry && !Number.isFinite(retrySeconds) ? Date.parse(retry) - Date.now() : 0;
        const delay = response?.status === 429
            ? Math.max(1000, Number.isFinite(retrySeconds) && retrySeconds > 0 ? retrySeconds * 1000 : retryDate || 60000)
            : failureDelay;
        retrySessionAt = Date.now() + delay;
        failureDelay = Math.min(failureDelay * 2, 300000);
        share({ retrySessionAt });
    }
    async function checkSession() {
        adoptShared();
        if (document.hidden || checkingSession || Date.now() < retrySessionAt || Date.now() < nextStatusAt) return;
        const shared = readShared();
        if (Date.now() < (Number(shared.pollUntil) || 0)) return;
        // A shared lease reduces polling to once a minute across ordinary tabs.
        share({ pollUntil: Date.now() + 60000 });
        nextStatusAt = Date.now() + 60000;
        checkingSession = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await window.fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
            if (response.status === 401) { await signOut(response); return; }
            if (!response.ok || response.redirected) { backOff(response); return; }
            acceptSnapshot(await response.json());
            failureDelay = 30000;
            retrySessionAt = 0;
            share({ retrySessionAt: 0 });
        } catch { backOff(); }
        finally { window.clearTimeout(timeout); checkingSession = false; }
    }
    async function recordActivity(force = false) {
        adoptShared();
        if (document.hidden || recordingActivity || Date.now() < retrySessionAt || (!force && Date.now() < nextActivityAt)) return;
        recordingActivity = true;
        nextActivityAt = Date.now() + 60000;
        share({ nextActivityAt });
        renderWarning();
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await window.fetch(activityUrl, {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, signal: controller.signal,
            });
            if (response.status === 401) { await signOut(response); return; }
            if (!response.ok || response.redirected) { backOff(response); return; }
            failureDelay = 30000;
        } catch { backOff(); }
        finally { window.clearTimeout(timeout); recordingActivity = false; renderWarning(); }
    }
    for (const event of ['pointerdown', 'keydown', 'input', 'wheel']) {
        document.addEventListener(event, (event) => { if (event.isTrusted) recordActivity(); }, { passive: true });
    }
    const tick = () => { adoptShared(); renderWarning(); return checkSession(); };
    window.setInterval(tick, 1000);
    window.addEventListener('focus', tick);
    window.addEventListener('storage', tick);
    document.addEventListener('visibilitychange', tick);
    adoptShared();
    share({ activityStamp, deadline, idleSeconds });
    renderWarning();
    const url = new URL(window.location.href);
    if (url.searchParams.has('_background')) {
        url.searchParams.delete('_background');
        window.history.replaceState(window.history.state, '', url.href);
    }
}
