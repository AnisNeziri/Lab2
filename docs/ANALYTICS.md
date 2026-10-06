# Analytics foundation

- `/analytics` provides tenant-scoped operational analytics and Accounting Core reports.
- Linked issued invoices replace daily-sale amounts; orders are never extra revenue. Completed unbilled refunds and issued credit notes reduce sales once.
- Unknown costs, incomplete timing, missing warehouse incoming quantities and unavailable history remain null. Different inventory units are never summed together.
- Current inventory/credit/supplier observations are labelled current; period sales and dated outcomes support equivalent prior-period comparisons.
- `analytics:setup` adds permissions without resetting existing role assignments. Sensitive financial information additionally requires `analytics.finance`.
- `analytics:capture` captures one immutable observation per company/date/entity/warehouse. The normal Laravel scheduler runs it daily at 23:55. Desktop startup and hidden hourly maintenance catch the first available observation each day; the desktop must be running.
- Capturing missing past dates is deliberately prohibited. A snapshot means observed at its timestamp, not a fabricated end-of-day balance. Snapshots are compact and indexed, with no copies of source documents.
- `observed-v1` features document their definitions and missing-value semantics. Preserve this version's contract; use a new version when changing calculations.
- Demand dataset versions freeze features from stored observations and labels from the following 30 completed calendar days. Labels describe gross recorded sales demand, not latent unmet demand. Missing/changed product units invalidate the label. Review stockouts, sparse history, corrections and data quality before training.
- CSV/JSON exports require dataset, finance and export permissions. JSON includes the immutable version manifest; CSV includes feature version, snapshot date, unit and target horizon per row.
- Serious new data-quality issues emit `analytics.data_quality_problem` and appear in Action Center. Repeated checks do not flood it with duplicate alerts; source business data is never silently repaired.
- Future model providers implement `PredictionProvider`; prediction storage includes input version, actual outcomes and evaluation fields. No model or prediction runs in this phase.

## Limits

New installations need observed history and matured outcomes before ML training. Stockout intervals are daily observations, not exact intra-day events. Credit-order payment timing remains unavailable where no reliable dated allocation exists. Partial/sparse history never produces a 90-day turnover estimate. Financial statements remain Accounting Core's responsibility.
