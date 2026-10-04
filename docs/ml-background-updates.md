# Background machine-learning updates

Analytics and Demand Outlook now read saved predictions. Page requests never
train models or scan/hash the demand model's raw sales history. Estimates are
snapshots from the last completed update, not real-time forecasts.

Initial/manual refresh from the project terminal:

    php artisan ml:refresh

The Laravel schedule runs this command hourly. On local XAMPP, keep this running
in a terminal while developing:

    php artisan schedule:work

On hosting, configure the provider's scheduler to run `php artisan schedule:run`
every minute from the project directory. The hourly command runs directly in
the scheduler, so it does not require a queue worker. Both scheduled and manual
runs use a shared cache lock to prevent concurrent training. Use a shared
database/Redis cache when running on multiple servers.

The web training endpoint queues a refresh instead of blocking the page.
It uses the dedicated database-backed `ml` queue connection (not the default
mail queue), with a retry interval longer than the job timeout. Run:

    php artisan queue:work ml --queue=ml --timeout=1800 --tries=1

Use this separate worker so training cannot delay transactional mail.
The existing jobs/cache database tables must be migrated before use.

Insufficient history is an expected result: the command reports it without
inventing predictions. Cache loss yields a waiting state until the next refresh.
Inspect scheduler/worker logs for failures and monitor prediction timestamps.
