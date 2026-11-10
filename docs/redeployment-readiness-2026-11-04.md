# Redeployment readiness — November 4, 2026

Local application checks passed: 303 PHP tests (3,113 assertions), 39 JavaScript
tests, production asset build, Composer validation and local production platform
requirements, plus isolated production configuration, route and Blade compilation.
All 35 build-manifest entries resolve; no Vite development hot file is present.
Removed a duplicate, identical `ml` connection entry from `config/queue.php`.

Subsequent responsive layout changes passed the same 303 PHP tests, 42 JavaScript
tests, and a new production build. See [responsive layout review](responsiveness-review-2026-11-04.md)
for changes and the visual device checks that remain pending.

The VPS was not accessed or changed. Its PHP extensions, pending migrations,
workers, scheduler, SMTP delivery, API credentials/billing, and browser behavior
still require checks on the host. AI tests mock provider responses; they do not
prove live API access. The local configured model is documented by
[OpenAI](https://developers.openai.com/api/docs/models/gpt-5.6-luna), but account
access and request success have not been tested in this readiness check.

## Assemble the complete release

There are modified tracked files and many new untracked files in the workspace.
A Git deployment must include the new application classes, views, JS/CSS,
scripts, migrations, and favicon before pushing a reviewed release. Pulling the
existing remote alone will miss these local files.

`public/build` is Git-ignored. Either build on the VPS with `npm ci` followed by
`npm run build`, or transfer the complete freshly generated `public/build`
directory and its manifest as one release. Do not mix an older manifest with new
hashed assets. The light-theme build requires `scripts/build-light-theme.mjs`.

Keep the VPS `.env`, its existing `APP_KEY`, database, uploads, saved ML results,
and storage directories. Do not replace them with the local development copies.
Do not deploy old update archives or the temporary/testing files in storage.

## Schema updates

These four migrations are applied locally. Check the VPS's own `migrate:status`;
Laravel will run only migrations it has not applied:

- `2026_11_03_000001_add_supplier_extraction_metadata`
- `2026_11_03_000002_add_approved_at_to_customer_returns`
- `2026_11_03_000003_add_stock_alert_cooldown`
- `2026_11_03_000004_add_pos_payment_details`

The refund migration backfills historical approval timestamps from the existing
update/return timestamps. The cooldown migration backfills successful alert times
and expands notification message storage. Back up the existing database before
applying schema updates. This release uses normal migrations, not a database reset.

## VPS update sequence

Prepare a database backup and a rollback copy of the previous application release.
Use the deployment account and PHP binary configured for this host. From
`/var/www/stock-pos`, enter maintenance mode before replacing application files.
Install the complete new release at the indicated point, then run the remaining
commands:

```sh
php artisan down
# Install the complete new application release here, preserving .env and storage.
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
npm ci
npm run build
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

Skip the npm steps only when transferring a complete verified production build.
Do not run `composer update` or change the application's encryption key as part of
this update. Check each command succeeds before continuing; if a step fails, keep
maintenance mode enabled while diagnosing it rather than running later steps.
Restart the host's PHP-FPM service through the existing deployment procedure if
its OPcache configuration requires it.

## Host settings and background processes

- Document root: `/var/www/stock-pos/public`; production HTTPS URL:
  `https://mtsync.tech`. Use `APP_ENV=production`, `APP_DEBUG=false`, and retain
  the VPS's database, mail, and API settings. Rebuild config cache after changes.
- Run the minute scheduler through the existing cron configuration:
  `php artisan schedule:run`. It runs low-stock checks and scheduled ML refreshes.
- Ensure the stock-alert database queue has a persistent worker. The explicit
  connection command is `php artisan queue:work stock-alerts --queue=default --timeout=60`.
  An existing database/default worker on the same connection/table is sufficient;
  do not introduce a redundant worker configuration blindly.
- The owner-triggered ML refresh uses the separate worker:
  `php artisan queue:work ml --queue=ml --timeout=1800 --tries=1`.
  Supervisors must restart workers after code updates.
- Confirm storage and `bootstrap/cache` are writable by the application account.
  If building on the VPS, the locked Vite version requires Node.js
  `^20.19.0` or `>=22.12.0`.
  Check PHP and web-server upload limits allow the existing 10 MB spreadsheet
  imports and new 5 MB AI documents. AI extraction may take up to 60 seconds;
  the web-server/PHP request limits must allow completion.

## After deployment

November 5 addition: include the new sales-target table migration, target model,
controller/service, shared target card/CSS, and Analytics tab script/partials.
See `sales-targets-and-analytics-tabs-2026-11-05.md`. Verify owner target saving,
refund-adjusted progress, history pagination, and all five Analytics tabs after
the normal migration/build/cache steps.

Check owner and staff login, email verification/delivery, forced password change,
and session behavior. Check dashboards in both themes, table Next/Previous and
row-size controls without full reload, and grouped Movement Logs / Archived
Products tabs. Test restore with a disposable record. Permanent deletion is an
actual database deletion and is blocked for products referenced by sales,
returns, damages, or held orders.

Verify a controlled POS transaction/payment/receipt, daily analytics and Excel
export, supplier import/review, queued alert delivery, and ML timestamps. Use
approved test data for live smoke tests. Check application, worker, scheduler,
and web-server error logs before considering the live update complete.
