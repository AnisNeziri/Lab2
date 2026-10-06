# AIMS V8 — Customer & Sales Intelligence

Open **Intelligence → Customer & Sales** (`/customer-sales-intelligence`). The seven views cover overview, opportunities, unusual inactivity, customer/product/category trends, genuine basket associations, concentration, and data/model health. English and Albanian use the existing language setting; light/dark theme tokens are shared with AIMS.

## Business sources and interpretation

`CustomerSalesEvidence` reuses `AnalyticsSalesLedger`: a completed daily sale and its linked invoice count once; an order or payment is not another sale. Returns are separate reversals in net-sales/gross-contribution summaries, not new purchases. Positive purchase baskets drive cadence, associations and purchase-activity trends. Gross contribution is revenue minus recorded COGS, not full profitability; it is unknown if any relevant cost is missing and requires financial analytics permission.

Metrics cover the configured rolling history (default 1,095 days), not a claim of lifetime history. First purchase and relationship duration mean first recorded purchase **in that analysed history**. Foreign-currency invoices are excluded rather than converted using an invented exchange rate. Existing customers are reused, including archived identities where needed for historical context. Missing identity is never guessed from customer names.

Personal timing requires six distinct purchase dates. The baseline uses the customer's own interquartile purchase intervals. Long irregular gaps suppress personalized predictions. Seasonal suppression requires repeated patterns across at least two completed calendar years; sparse seasonality remains unknown. Trends compare 90 days with the prior 90 days, or the same period last year when seasonal history supports it. Frequency and value changes must exceed the customer's own observed variability before a growing/declining classification is used.

RFM-style segments show the actual recency, median interval and recorded value and company-relative thresholds. They use business transactions only, not sensitive characteristics.

Product/category associations require at least 30 baskets, five co-purchases across three identified customers, 10% support, 35% conditional association and lift 1.2. A personal related-product signal also requires at least three baskets containing its source product and no recorded purchase of the target. A related-product association does **not** provide a personal quantity/value forecast. Reorder quantities and values are historical ranges, never guaranteed revenue or silently populated order quantities.

Concentration describes customer/product/category shares of recorded 90-day net sales. Unassigned sales remain in the denominator but are not called a “top customer.” Returns remain in the denominator. Concentration is not automatically labelled dangerous.

## Human-controlled workflow

1. Open **Review opportunity** and examine personal history, inventory, incoming dates and V7 payment context.
2. Enter your own quantity. **Review current price & stock** uses the existing manual-channel price rules and current available-to-promise.
3. Explicitly confirm **Create Order Draft**. A shortage requires acknowledgment. Current customer/product status, unit precision, price, inventory and permissions are revalidated; changed facts require another review.
4. Open the existing Fulfillment order detail. Existing confirmation, credit, allocation and fulfillment rules still apply.

One idempotency key per prediction prevents duplicate drafts. Creating a draft does not confirm an order, reserve/issue stock, create receivables, adjust credit or contact the customer. Advisory review does not change a price, discount or ledger entry.

Saved V6 shipment evidence and incoming quantities qualify fulfillment opportunities. Saved customer financial context uses the existing credit exposure service and V7 completed-cash-payment timing; advances stay separate. Opportunity ranking is explicit: personal reorder timing, then historical value; related-product suggestions follow. Warnings stay visible rather than being silently hidden in a sales score. Customer signals explain company inventory/procurement demand, never add a second forecast stream.

Customer sheets embed the profile; the dashboard and V5 Decision Center show a small guarded commercial-review panel. These integrate with the existing workflows, not a new CRM or replacement product decision engine.

## Observations and model governance

Frozen first-daily features reuse `analytics_snapshots`; predictions and append-only outcomes reuse `analytics_predictions`. Prediction customer, product, anchor, window, evidence, model/version and source snapshot never change. Same-day records, backdated purchases and records already known before observation are not timing labels.

Only closed future windows are evaluated. “No purchase in window” remains distinct from a subsequent later purchase; it never means permanent churn. Inactivity uses a separate future follow-up window and is not included in reorder-model timing MAE. Affinity is not falsely scored as a reorder forecast.

The champion starts as `interval_iqr_baseline`; `median_mad` is a transparent statistical challenger. The dependency-free local Python diagnostic checks actual intervals but does not claim a trained churn/credit model. PHP remains functional when Python is unavailable. Promotion requires at least 20 genuine independent customer/purchase outcomes and at least 5% lower timing MAE. Shared baskets are not multiplied into independent model samples. Metrics use the latest 5,000 evaluated reorder predictions within 365 days. Authorized model selection/rollback requires an audit reason and creates an immutable policy version. Test fixtures are not production accuracy evidence.

## Architecture, safety and operation

Two additive derived tables were added: `customer_sales_snapshots` and `customer_intelligence_policies`. No customer, sales, order, inventory or accounting schema is replaced. Backups include them and restore imported evidence archived, predictions expired and governance reset to the baseline; imported IDs/outcomes cannot authorize fresh drafts or promotion.

Access requires all existing permissions: `analytics.view`, `customers.manage`, `daily_sales.manage`. Drafts additionally require `fulfillment.manage`. Gross contribution requires `analytics.finance`; debt/payment context also requires `debts.view`. Shipment detail requires `shipments.view`. Warehouse-only access does not expose customer sales analytics. Company scoping applies to every endpoint, tool, source and task.

Five registered automation events are `customer.intelligence.activity_changed`, `.reorder_window`, `.high_value_inactivity`, `.sales_opportunity`, `.concentration_changed`. They carry guarded source references, not unguarded financial details. Review tasks deduplicate by issue/customer/product; high-value inactivity shares its activity issue key. There is no added customer messaging action.

Eight read-only tools reuse the AIMS tool layer: `get_customer_intelligence`, `get_customer_activity`, `get_customer_reorder_opportunities`, `get_sales_opportunities`, `get_at_risk_customers`, `get_customer_product_affinity`, `get_sales_concentration`, `get_customer_trend`.

Web: the existing Laravel scheduler runs `customer-intelligence:refresh` hourly. Business events mark saved evidence dirty. Read endpoints use frozen summaries and batch draft/observation lookups, not full recomputation. Source calculation is bounded to 50,000 records/lines; workers have a time budget. Manual refresh is rate-limited. `customer-intelligence:status` prints read-only counts, not customer identities. Desktop source chains the same maintenance command through its hidden local PHP process. A new installer was not generated during this phase.

## Verification and genuine evidence

The focused V8 tests cover canonical-sale deduplication, personal cadence, tiny associations, inactivity/seasonality, permissions/tenants, no business mutation, draft idempotency, changed price/stock, unit precision, source/outcome timing, promotion/rollback, unknown COGS and the eight tools. Related Analytics, Automation and V7 regressions passed. Backup tests verify archived restore behavior. Python/presentation/navigation/desktop checks and production build passed. One isolated wholesale browser workflow verified review → current-fact confirmation → one draft, unchanged balances/stock, responsive widths and Albanian dark theme.

At the first live calculation, company 1 had five canonical baskets but no linked customer purchase history; company 5 had no baskets. Personal opportunities, supported affinities and genuine later outcomes were zero. Python was available and the baseline remained champion. Missing customer identities and product references are explicit health warnings. None of the synthetic wholesale test history was inserted into the live database.

Recommended next phase: a grounded, read-only business assistant using these existing permission-aware tools, after collecting clean linked customer history and real completed outcomes. V9 was not started.
