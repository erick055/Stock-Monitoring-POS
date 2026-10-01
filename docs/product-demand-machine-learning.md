# Local future product demand

Analytics automatically displays a next-30-day unit-sales estimate for every
active product, without a Generate button or hosted API request. This is a local
PHP ridge-regression model, separate from the existing dead-stock classifier.

Features: units sold in each of the previous three 30-day periods, recent paid
receipt count, and days since last sale. Targets are actual paid units sold in
the following 30 days. Training uses up to two years of sales, with snapshots
30 days apart. Only products already at least 90 days old with a sale at each
historical cutoff contribute samples. Unpaid/future-dated sales are excluded.

Training requires at least 20 training and 5 validation snapshots across four
or more cutoff dates. Validation uses later dates, with overlapping training
target windows excluded. Feature scaling is fitted on training data only.
The table reports mean absolute error (MAE), not an accuracy percentage, and
compares it with the previous-30-day sales baseline. A model can be worse than
the baseline; do not claim improved accuracy without supporting measurements.
After evaluation, the serving model is fitted to all completed snapshots.

Products need at least 90 days of age and three paid receipts for an estimate.
Otherwise their table row shows Waiting for history. Predictions are nonnegative
rounded units and are not capped by current stock. Results and fitted parameters
are cached for one hour; a hash of product data and sales invalidates the cache
after sales, corrections, or catalog changes. Opening Analytics or its export
refreshes results automatically. No migration or Python installation is needed.

The model estimates observed sales, not unrestricted market demand. It does not
yet account for historical stockouts, returns, holidays, prices, promotions, or
seasonality. A new product's created_at may not reflect pre-import history.
Synthetic tests verify behavior, not accuracy on the store's real data. Large
datasets should eventually move training into a scheduled background job.

The old Groq forecasting route is removed; any existing GROQ_API_KEY in the
private server environment is unused by this feature and can be removed.
