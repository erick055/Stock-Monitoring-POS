# Low-stock email digests

An alert check queues at most one email containing the eligible critical and warning
products. Immediate inventory-change alerts require a new crossing to stock at or
below the reorder level. Repeated checks do not resend stable critical stock.
Manual checks also include warning items, but do not bypass cooldowns.

`stock_alert_states.last_notified_at` records successful delivery for each product.
A pending delivery reserves its products until it succeeds or exhausts its retries.
Products cannot be included in another digest until 24 hours after successful
notification. Daily summaries share this cooldown.

Notification History contains one row per digest. The worker updates that row from
Queued to Sent, Retrying, or Failed. A failed attempt retries the same digest up to
three times with 60-second and 300-second backoffs; final failure releases the
reservations. Mail is never sent by the HTTP request.

## Worker

Run a persistent queue worker under your normal process supervisor:

```sh
php artisan queue:work stock-alerts --queue=default --timeout=60
```

The `stock-alerts` connection always uses the application's database and `jobs`
table, even if `QUEUE_CONNECTION=sync`. It shares the default jobs queue with a
standard database worker, so an existing worker on that same table and queue is
also sufficient. Run only the worker configuration appropriate for the deployment.
Restart long-running workers after deploying the changes.

Apply migrations before using the updated alert checks. The cooldown migration
backfills the last successful per-product email timestamp from existing delivery
history. It also allows larger digest bodies without truncating the item list.
