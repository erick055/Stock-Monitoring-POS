# Sales targets and Analytics tabs

The owner Dashboard displays the current calendar month's sales target below
Sales breakdown. No target or sample amount is created automatically. The owner
sets targets under Analytics > Sales Targets, using a month and positive peso
amount. Saving the same month updates its existing target; history supports
20/50/100 rows and the existing asynchronous pagination.

Progress uses paid receipt totals by sale date, minus approved refunds by their
approval date. A refund approved this month reduces this month's progress even
when the original receipt was from a prior month. Pending returns, cancelled
receipts, and future-dated receipts/refunds do not count. Dates use the configured
application timezone (Asia/Manila). This is net sales, including receipt labor,
not gross profit. Gross profit remains visible in Analytics Overview and daily
transaction summaries.

Display progress can exceed 100% or fall below zero after refunds. The progress
bar is capped between 0 and 100; remaining sales cannot fall below zero.
Statuses are Not started, In progress, Achieved, and Below target for ended
months. Owner-only route middleware protects both read and write access; staff
dashboards do not display store targets. CSRF and server-side validation apply.

Analytics has five panels: Overview, Transactions, Product Movement, AI Insights,
and Sales Targets. Tab switches are local and support keyboard arrows/Home/End,
URL state, browser Back/Forward, pagination anchors, and validation errors. The
original native specific-date picker and receipt/refund tables are retained in
Transactions. Existing chart-period links and Excel exports are preserved.
Overview financial cards remain explicitly labelled all-time; the chart and
monthly target each have their own period labels. AI Insights contains the
existing local forecast, rather than fabricated sample recommendations.

Deployment requires the new migration
`2026_11_05_000001_create_sales_targets_table.php`, a production asset build, and
the normal application cache refresh. This adds a new table and does not reset
existing data. Include new untracked source files when preparing a release.
No changes have been deployed to the VPS. Browser/device visual verification
remains pending because browser access was blocked during the prior UI review.
