# Production security setup

Set the hosting document root to **stock-pos/public**, not the repository root.
Never expose .env, .git, vendor, storage, backups, or database files. The root
Apache .htaccess is defense in depth only and does not protect Nginx.

Set APP_ENV=production, APP_DEBUG=false, and APP_URL=https://your-domain in the
private server .env. Keep the existing APP_KEY; rotating it invalidates encrypted
data. Use a dedicated database account with a strong password.

Enable HTTPS and redirect HTTP at the web server/load balancer before PHP.
Production rejects requests PHP sees as HTTP, generates HTTPS URLs, disables
debug output, encrypts session storage, and enforces Secure/HttpOnly/SameSite=Lax
session cookies. Behind a TLS-terminating proxy, set TRUSTED_PROXIES to its actual
IP addresses/CIDRs and ensure it overwrites forwarded headers. Never use `*`.

Leave SESSION_DOMAIN=null for host-only cookies. For subdirectory installations,
set SESSION_PATH to the app path (local XAMPP example: /stock-pos/public).
The existing staff 30-day session/idle duration is retained; consider shortening
STAFF_IDLE_TIMEOUT for shared terminals.

Deploy with php artisan migrate --force, npm ci, npm run build, and
php artisan config:cache. Rebuild the config cache after .env changes.
Verify login/logout, cookies, receipts, exports, and forwarded HTTPS on the host.
Do not expose the Vite development server publicly.

Responses include no-store, MIME-sniffing protection, same-origin framing,
referrer, permissions, and baseline CSP headers. Production HTTPS includes HSTS
without subdomain/preload commitment. The baseline CSP does not restrict scripts;
a strict script policy requires migrating inline scripts to nonces or external
assets. Configure appropriate headers for static assets at the web server too.
