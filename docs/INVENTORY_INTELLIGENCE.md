# Inventory Intelligence V2

Open **Inventory Intelligence** in AIMS navigation. Review a product, choose a
7/30/90-day horizon, and prepare a candidate for review. This requires Analytics dataset,
finance and inventory permissions; ordinary viewing does not expose restricted
supplier prices. Predictions are saved, not retrained on page visits.

## Local architecture

Laravel freezes versioned demand datasets and invokes `backend/ml/forecast.py`
through `DemandForecastProvider`. Python 3.10+ uses only its standard library:
seasonal means and a small regularized ridge model implemented in this project.
There is no AI subscription, network dependency, GPU, pickle or executable model
upload. JSON numerical artifacts are hashed. The Windows desktop bundles Python
and its licence; web servers configure `AIMS_ML_PYTHON` to their Python executable.
If Python is unavailable, forecasting reports an error; normal AIMS work continues.

## What the numbers mean

- Demand is gross fulfilled base-unit quantity, counted once across linked sales,
  orders and invoices. Returns are reported separately, not negative demand.
  Late-posted transactions enter demand no earlier than their recognition date.
- Historical inventory observations must exist. Blank days are unknown unless a
  finalized operating day and a matching in-stock observation support a zero.
  Day notes alone are insufficient. Stockout-censored days are not zero demand.
  Daily inventory observations are samples, not continuous availability evidence.
- Each product is trained independently in its recorded inventory unit; current
  supplier or category information is not retroactively used as historical input.
- At least 14 recent observed days are required. Longer horizons require more
  observations. Sparse fallbacks are explicitly unvalidated; unsupported horizons
  stay empty. There are no invented probabilities or confidence intervals.
- Validation is time-ordered, recursive, non-overlapping multi-day holdout.
  Ridge needs two measured windows and must beat baseline MAE by at least 2%
  without worsening horizon-total error. Measured incumbents are retained if a
  challenger does not improve them or new validation is unavailable. V2 never
  automatically promotes a candidate. Outputs
  cannot exceed 3× maximum trailing-90-day observed daily demand.
- Mature outcomes report MAE, WAPE (undefined at zero actual demand), and bias.
  Incomplete/stockout periods are excluded from complete-horizon scoring. Company
  summaries separate units/horizons and remove overlapping windows per product.

## Replenishment and operations

The explanation uses authoritative available stock (already net of reservations),
unreserved backorders, dated incoming POs, supplier lead time, fixed safety/minimum
stock and review days. Late/undated arrivals are uncertain, not firm supply.
Supplier catalogue lead time takes precedence; observed lead time is a fallback.
Existing supplier scorecards provide real delivery/quality context.

Purchase quantities respect base units, fixed conversions, MOQ and pack multiples.
Missing prices remain unknown estimates. The draft action rechecks permissions,
expiry, unit, live stock, supply and supplier terms. Changed conditions require
review. Repeated requests reuse one draft. The chosen supplier is recorded as a
preference in PR notes because existing PRs select suppliers during RFQ sourcing.
No approval, PO, stock movement or financial posting is automated.

Risk changes feed existing Automation Studio and Action Center; safe read tools
expose forecasts and explanations. Viewing, dismissal and draft acceptance are
audited. Forecasts/models are preserved in Analytics backups; imported models are
archived and imported recommendations cannot be executed without regeneration.

`php artisan intelligence:maintain` captures closed observations, evaluates matured
outcomes, monitors sustained warnings and refreshes approved forecasts. Web
scheduling runs it hourly; desktop runs on startup and hourly while open. Retraining is opt-in:
`AIMS_ML_SCHEDULED_TRAINING=true`, `AIMS_ML_RETRAIN_DAYS=7` (minimum),
`AIMS_ML_BATCH_LIMIT=10`. Scheduled retraining of an existing forecast needs at
least seven new observed days. Models are never reused across inventory-unit
changes. Only products needing fresh data are attempted; manual
generation remains available. Desktop packaging needs `AIMS_PYTHON_SOURCE`.

## Real-world validation and controlled learning

Closed daily observations are immutable and company/product/date-idempotent.
Automatic collection catches up 14 days; authorized backfill accepts up to 90 days
per request. Missing historical stock remains unknown, never copied from today's
stock. Intraday zero-stock movements censor demand even if the sampled closing
stock is positive. Fulfilled-sales accuracy is qualified by sampled availability.

Only exact completed 7/30/90-day periods with matching units and complete usable
observations contribute to measured accuracy. MAE, absolute/signed error, WAPE,
bias and the frozen baseline are saved with observation references. Future-trained
or retrospective forecasts cannot be scored as genuine production accuracy.
The performance screen separates these outcomes from historical candidate holdouts.

Promotion requires 56 observed days, 80% recent coverage, two validation windows,
at least 14 validation targets, and no worse error than baseline and comparable
production. Authorized reviewers must explicitly confirm with a reason. Production
changes invalidate stale reviews; rollback is limited to the immediately previous
version. Decisions, model evidence and old predictions are retained.

Champion replacement additionally requires `AIMS_ML_PROMOTION_IMPROVEMENT_PERCENT`
(default 2%) lower MAE without worse horizon-total error. Training attempts retain
immutable audit evidence, input and engine hashes, frozen dataset version and failure
reasons. Unchanged insufficient inputs are reused; a newer date alone does not justify
another challenger. Only one local training process runs at a time.

The same screen shows pending windows, health/evidence status and retraining history.
New observations include source references, net recorded sales, separate inventory
adjustments, posted receipts and known movement-based stockout intervals. Older
completed observations are not rewritten. Recommendation/receipt evidence is audited
with its knowledge timestamp; future datasets must not use it before that timestamp.

Retraining requires new observations, elapsed time and sustained degradation or a
stale model. Three independent completed periods are needed for accuracy/bias
warnings; demand-shift warnings require two weeks. Alerts are episode-deduplicated
in Automation Studio and Action Center. Jobs use configurable batch/time budgets
and resume without duplicate observations or evaluations.

Recommendation outcomes link decisions to PRs and resulting POs, supplier delays
and observed stock changes. These are associations, not causal improvement claims.
Safe tools expose performance, evaluations, warnings, candidates and policy state.

Forecasts are company-wide. Warehouse filtering locates stocked products; it does
not invent warehouse-specific demand. The next step is accumulating genuine daily
observations, manually approving an eligible model and reviewing mature errors
before considering calibrated ranges. No synthetic benchmark is company accuracy.

## V4 inventory planning

Open **Inventory Intelligence → Inventory Planning**, or use its Procurement link.
Review a product and supplier, set a versioned service/priority policy, compare
up to four read-only scenarios, then save a recommendation. Select saved rows,
review the consolidated budget allocation and explicitly create PR drafts.
Existing procurement submission and approval rules continue to apply.

Planning reads canonical available stock, reservations, confirmed PO remainders,
V2 reconciled demand observations, V3 delivery evidence and current supplier terms.
For sufficiently complete stationary demand it uses
`z × sqrt(meanLead × demandVariance + meanDemand² × leadVariance)` for safety stock.
Intermittent demand uses qualified empirical lead-window quantiles. Short, stale,
censored or outlier-heavy histories retain explicit configured safety floors;
missing demand/lead evidence is not invented. Service targets are not measured guarantees.

Warehouse forecasts require reconciled sales movements, matching unit snapshots
and observed availability. Otherwise an explicit company-demand share is required;
declared shares cannot exceed 100%. Internal transfers never become sales demand.
Transit estimates require completed evidence for the same warehouse route or an
explicit scenario assumption. Unknown arrivals remain outside firm coverage.

MOQ, fixed packs, base-unit precision, declared stock ceilings and explicit budgets
constrain quantities. PR price precision is reused; missing FX/cost information
stays unknown. Landed-cost estimates use historical posted allocations. Free storage,
credit availability, holding costs and proven savings are not inferred.

Draft conversion locks and revalidates current inputs, policies and pricing,
prevents duplicate open requests, and preserves review history. Policies and
recommendation evidence are immutable. Portable imports retain archived evidence
and require fresh planning before action. Scheduled work is bounded and relevant
business events request targeted updates. Automation and read-only tools expose
planning risks, assumptions, scenarios and observational receipt/coverage outcomes.

The optimization views now separate action, replenishment and excess states. Product
review shows canonical inventory position, the unrounded requirement and fixed-pack
purchase, supplier price/delivery/quality trade-offs, measured warehouse transfer
opportunities and separate recorded finance sources. Unknown cash/FX/lead evidence
is left unknown. Mature, unique forecast errors can increase the variance allowance;
future or incomplete observations cannot enter demand history.

Decisions retain review history. Quantity/supplier edits create a freshly calculated
recommendation without rewriting frozen evidence; postponements are respected by
scheduled planning. Transfers require an actual authorized transfer reference.
Meaningful changes expose `inventory.optimization.*` events and direct planning
links. Read-only tools cover position, replenishment, supplier options and scenarios.
