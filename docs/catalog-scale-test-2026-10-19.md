# Catalog scale test — October 19, 2026

Tested 150, 500, and 1,000 products in SQLite in-memory test databases. No test
products were added to the real inventory. Each dataset includes 20 categories,
30 shelves, multiple stock statuses, active discounts, and recent paid sales.

## Coverage

- Owner dashboard, Products, Stock Management, POS, Analytics (weekly, monthly,
  yearly), Dead Stock, Low Stock Alerts, Returns, Compatibility landing page,
  Suppliers, and the live inventory endpoint all returned successful responses.
- Searches found the last product in each catalog.
- Product and stock pagination reached the last page without losing products.
- Stock status filtering and Dead Stock/Low Stock pagination passed.
- All products remained available in the movement selector.
- Staff Products and POS remained accessible.
- Checkout of a product near the end of the catalog deducted the correct quantity.
- Retrying the same checkout created no duplicate sale or stock deduction.
- Receipt rendering succeeded after checkout.
- Each measured page stayed below 100 database queries.

## Fix

Stock Management previously rendered every product row, including category and
shelf editing controls. Added database pagination and stock-status filtering,
with 25 rows by default and options for 50 or 100. Filters and sorting are kept
in pagination links; summary totals and the movement selector cover the full catalog.

One local 1,000-product comparison:

| Stock Management | Before | After |
| --- | ---: | ---: |
| HTML response size | 7,469,242 bytes | 365,239 bytes |
| In-process server request time | 637.5 ms | 149.9 ms |
| SQL queries | 6 | 7 |

The HTML size decreased by approximately 95%. Timing measurements are local
observations, not guaranteed production response times.

## Final checks

- Full PHP suite: 228 passed, 1,698 assertions.
- JavaScript suite: 3 passed.
- Frontend build and whitespace checks: passed.

Re-run catalog tests with `php artisan test --filter=CatalogScaleTest`.
Per-page timings, query counts, and response sizes are written to
`storage/app/testing/catalog-scale-{150,500,1000}.json`.

These tests do not measure browser rendering on low-powered devices, production
MySQL performance, external AI response time, or simultaneous users. They verify
single-request catalog behavior up to 1,000 products and cannot guarantee that
every workload will succeed.
