const verification = document.querySelector('[data-email-verification]');

if (verification) {
    let checking = false;
    let retryAt = 0;
    async function checkVerification() {
        if (checking || document.hidden || Date.now() < retryAt) return;
        checking = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(verification.dataset.statusUrl, {
                headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal,
            });
            if (response.status === 401 || response.redirected) {
                window.location.assign(verification.dataset.loginUrl);
                return;
            }
            if (response.status === 429) {
                const seconds = Math.max(1, Number(response.headers?.get('Retry-After')) || 60);
                retryAt = Date.now() + seconds * 1000;
                return;
            }
            if (!response.ok) return;
            const status = await response.json();
            if (status.verified && status.redirect_url) {
                verification.querySelector('[data-verification-status]').textContent = 'Email verified. Continuing…';
                window.location.assign(status.redirect_url);
            }
        } catch {
            // A temporary network failure is retried; the manual continue link also works.
        } finally {
            window.clearTimeout(timeout);
            checking = false;
        }
    }
    window.setInterval(checkVerification, 5000);
    window.addEventListener('focus', checkVerification);
    document.addEventListener('visibilitychange', checkVerification);
    checkVerification();
}
