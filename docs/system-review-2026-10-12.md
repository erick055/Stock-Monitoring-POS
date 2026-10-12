# System review — October 12, 2026

This review checked application code, routes, automated workflows, input validation,
frontend compilation, and dependency advisories. It preserves the earlier requested
analytics, product, supplier, account management, and stock form changes.

## Fixes from this review

| Area | Problem and fix |
| --- | --- |
| POS held orders | Repeated product lines could pass individual stock checks while their combined quantity exceeded stock. Duplicate lines are now rejected in holds and checkout. |
| POS bundles | Multiple free products could reuse the same paid companion quantity. The server now checks the combined required companion quantity. |
| POS bundles | Two free products could qualify each other for a zero-value bundle. Bundle companions must have a positive selling price. |
| Receipts | Historical receipts with a missing cashier could produce a type error. Owners can now view them; staff permission checks remain enforced. |
| Checkout amounts | Oversized receipt totals could exceed database decimal limits. They now return a validation error and roll back stock changes. |
| Stock management | Added quantity, reorder, price, and stock balance limits. Stock movements recheck active product status inside their transaction. |
| Supplier imports | Excessive prices, invalid negative quantities, and oversized strings could reach database columns. Invalid rows are now flagged and stored using safe preview values; they cannot be published. |
| Supplier product creation | Selling prices and bulk markups are checked against the supported monetary range. |
| Product totals | Recorded units and revenue included unpaid sales. They now include paid sales only. Returned units count approved returns; damage totals exclude pending and rejected reports. |
| Analytics demand | Different products sharing a name and category were combined. They are now grouped by product ID. |
| Dashboard activity | Unpaid transactions could appear as completed sales. Completed-sale activity now includes paid transactions only. |
| Returns summary | Pending and rejected returns contributed to the total, and multiple return records could push the rate above 100%. The total now counts approved returns, and the rate counts distinct monthly paid receipts with approved returns. |
| Low-stock email | A failed email advanced the notification state, preventing a normal retry. Failed delivery now preserves the previous severity so the next scheduled check can retry. |
| Inventory synchronization | Product name, SKU, category, shelf, and promotion changes could be missed within the same timestamp. They now contribute to the version hash, including the correct promotion ID. |
| Browser compatibility | Live refresh used a popover selector unsupported by older browsers. It now checks native popover support and respects the fallback popup and supplier modal. |
| Recommendation Refresh | The button only displayed a preview toast. It now reloads the page to obtain current recommendations. |
| Account management | Added rate limits to account mutations. Disabling an account and revoking its access now happen in a database transaction. Session deletion respects the configured database connection and table. |
| Session messages | The logout message always used default durations. It now reflects the configured timeout. |
| Routes and model | Removed repeated owner POS route definitions and repeated checkout model fields. |
| Tests | Updated the supplier sort label assertion and exercised account session revocation with the database session driver. Added 11 regression tests covering confirmed bugs. |
| Dependencies | Updated concurrently 9.2.4 → 9.2.5, shell-quote 1.9.0 → 1.12.0, and source-map-js 1.2.1 → 1.2.2 within compatible dependency ranges. |

## Verification

- Full PHP suite: 209 passed, 1,415 assertions.
- JavaScript suite: 3 passed.
- Frontend production build: passed.
- PHP syntax: 97 files passed.
- Route inspection: 79 routes; no duplicate method/URI endpoints or duplicate non-null names.
- npm audit, including development dependencies: zero reported advisories.
- Composer locked production dependency audit: no reported advisories or abandoned packages.
- Git whitespace check: passed.

## Review limits and remaining improvements

The automated database tests use SQLite. MySQL concurrency and production database
behavior were not load-tested. Live SMTP delivery, external compatibility AI,
scheduled workers, and production hosting were not exercised. The interface was
compiled and rendered through page tests, but not manually tested across browsers.
No production deployment was performed.

Owner password reconfirmation for sensitive actions and a permanent account-change
audit trail remain security improvements. Existing approval/disable metadata does
not provide a complete history of name, email, or password changes.

The AI Recommendation Summary still derives its recovery advice from inventory
rules; the title change does not turn it into generated AI advice. Its description
states the source, and Refresh retrieves current data without training a model.

Passing these checks does not guarantee the absence of every possible defect or
security issue. Production configuration guidance remains in
`docs/production-security.md`.
