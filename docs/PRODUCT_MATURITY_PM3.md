# AIMS PM3 — synthetic-company validation report

Validated on 7 October 2026. This is an isolated test-company exercise, **not** a claim of production certification, tax compliance or proven ML accuracy. The existing real-company database was not reset, replaced or populated with these records.

## 1. Seed and configuration

- Seed: **20261006**, scenario version `pm3-v1`.
- History: **9 July–6 October 2026**, 90 chronological days; current-day advisory validation is separately dated 7 October.
- Company: **AIMS Demo Wholesale — SYNTHETIC / TEST DATA**.
- 36 products, 24 customers, 4 suppliers, 3 active warehouses, four scheduled order batches per business day.
- Opening operating-bank cash: €160,000. Cold-start products enter on day 60. Candidate training attempts occur on days 58, 72 and 86.
- Central configuration: `backend/config/synthetic.php`; safe commands and credentials: `docs/SYNTHETIC_COMPANY.md`.
- Business choices use seed/key hashes. UUIDs, wall-clock timings and later current-day analysis identifiers are not promised to be byte-identical.
- Generation uses existing services and day-level transactions, rather than bulk inserting retrospective forecast labels. Initial generation took 16,839.47 seconds; ordinary startup reuses the completed database.

## 2. Generated entity counts

These are the saved report counts after current-day advisory revalidation. Opening screens or asking further questions can add analysis/audit records; operational history stays unchanged.

| Domain | Records |
| --- | --- |
| Products / customers / suppliers | 36 / 24 / 4 |
| Active warehouses / locations | 3 / 9 |
| Sales orders / Order Hub intakes | 308 / 308 |
| Daily sales / sale lines / day books | 301 / 1,838 / 90 |
| Customer debt transactions | 560 |
| Optional issued invoices / invoice lines | 7 / 40 |
| Purchase requests / RFQs / supplier quotes | 24 / 22 / 88 |
| Awards / POs / supplier payments | 72 / 72 / 160 |
| Goods receipts / receipt lines | 79 / 79 |
| Shipments / milestone records | 68 / 476 |
| Stock movements / transfers | 9,503 / 31 |
| Physical count sessions / quality inspections | 4 / 26 |
| Landed-cost documents | 316 |
| Expenses / expense payments | 12 / 12 |
| Financial accounts / financial transactions | 1 / 583 |
| Journal entries | 3,620 |
| Analytics snapshots / predictions | 12,828 / 194 |
| Candidate and archived inventory models | 125 |
| Enterprise decisions / learning records | 119 / 3,218 |
| Financial snapshots / cash observations | 92 / 91 |
| Customer-sales snapshots / shipment-intelligence records | 14 / 594 |
| Supplier delivery-risk records | 72 |
| Operational tasks / business events / automation executions | 69 / 15,239 / 102 |
| Documents / optimizer plans / simulations | 10 / 12 / 2 |

The warehouse table also contains one inactive initial fixture, giving four total warehouse rows. It is not an additional operating site.

## 3. Covered domains

Connected history covers catalogue/units, stock/warehouses/bins, Order Hub, outbound fulfillment, Daily Sales, receivables/advances, procurement/RFQ/awards, PO receipts/payments, shipment milestones, counts/recounts, quality, landed cost, finance/cash/journals, reports, documents/search, Automation Studio/Action Center, personalization and intelligence V1–V12.

Business services create the stock and financial effects. Optional invoices document an existing dispatch; they do not produce a second sale, stock deduction or receivable. Internal warehouse transfers are not customer demand. Shipment quantities describe PO timing rather than additional incoming inventory.

## 4. Business scenarios represented

Demand profiles: stable, growth, decline, intermittent, slow, high-volume, low-volume expensive, seasonal-like and cold-start. Units include metres, pieces, kilograms, rolls and cartons; fixed piece-to-box sales use real conversion rules. Customer cohorts include frequent, regular, occasional, new, declining, inactive, growing and concentrated buyers.

Order states: 298 delivered, 3 partially dispatched, 5 cancelled and 2 draft. PO states: 63 received, 5 ordered and 4 cancelled; the history includes staged receipts even though those particular orders are subsequently fully received. Shipments: 63 delivered and 5 in transit, with planned milestones frozen before later actual events.

Credit/cash orders, partial collections, customer advances, outstanding debt, late collections, staged supplier payments, delayed suppliers/customs, low stock, a recorded stockout, verified one-unit count variances, damaged receipts and posted freight/customs/insurance/inland allocations are represented.

**Warehouse imbalance:** Door Handles 03 at Prishtina Dispatch has a genuine Ferizaj Reserve transfer opportunity. The normal current-day putaway is explicitly recorded; it is not backdated demand.

**Optimizer:** the broader mixed-history two-warehouse scope remains `INFEASIBLE` because hard coverage cannot be established for every selected scope. That failure is preserved. A separately identified five-product dispatch scope is qualified:

- €500 balanced: `OPTIMAL`, €0 purchasing commitment, one transfer, three unresolved scopes and 31 aggregate stockout-scope days.
- €500 service-first: `OPTIMAL`, €407.16 purchasing commitment, two purchase lines, one transfer, two unresolved scopes and 20 aggregate stockout-scope days.
- €60,000 balanced: `OPTIMAL`, €659.16 purchasing commitment, two purchase lines, one transfer, one unresolved scope and 20 aggregate stockout-scope days.

`OPTIMAL` means best within the stated candidate/constraint model, **not** zero shortage. Existing open sourcing and arrival dates can leave shortages unresolved. No orders or transfers are executed by these analysis runs.

## 5. Intelligence eligibility and results

- 3,000 immutable demand observations; **1,802** complete non-stockout observations are forecast-eligible.
- **0** evaluated inventory-demand forecast windows and **0** eligible completed windows. There is no active inventory ML champion: 80 archived models and 45 candidates remain behind the existing evidence gates.
- Supplier histories: Balkan 19 orders/15 completed, Adriatic 20/19, Anatolia 16/13, Dardania 17/16. No supplier training rows meet all current ML attribution/eligibility requirements; the 40-completed-order training threshold also remains unchanged.
- Shipment outcomes: **564**, of which **471** are eligible under existing rules.
- Cash observations: **91**; evaluated financial snapshots: **90**.
- Learning records include 37 decision outcomes, 149 responses, 30 shadow records and 2,714 prediction outcomes. Accepted/modified/dismissed human responses remain in history even when the current decision is superseded or resolved.
- V4 uses reconciled observed-history baselines and explicitly qualified warehouse scope; it does not claim ML accuracy. Unknown no-sale Sundays remain unknown rather than fabricated zero-demand labels.
- Both V12 scenarios completed; the qualified stress comparison uses demand +20%, supplier/logistics delays and slower collections. Operational table hashes are unchanged.
- Promotion gates remain 56 observed days, two validation windows and 80% recent coverage. No gates were relaxed to make synthetic data appear successful.

## 6. Integrity results

All 36 product integrity checks pass. Tenant violations, future-cutoff violations and duplicate stock/financial idempotency effects are zero. Replaying the same low-stock automation event twice creates no additional execution.

| Financial control | Operational amount | GL amount | Difference |
| --- | --- | --- | --- |
| Receivables | €54,792.59 | €54,792.59 | €0.00 |
| Customer advances | €2,613.54 | €2,613.54 | €0.00 |
| Accounts payable | €0.00 | €0.00 | €0.00 |
| Supplier advances | €162,246.98 | €162,246.98 | €0.00 |
| Inventory | €94,175.62 | €94,175.62 | €0.00 |
| Cash/bank | €186,381.28 | €186,381.28 | €0.00 |

Supplier payments without matched supplier invoice documents correctly remain advances. Open PO commitments are tracked separately and are not falsely presented as posted AP invoices.

The owned synthetic database needed one separately audited **€0.25** accumulated rounding-carry journal (3620) after a precision defect was found. Historical journals were not rewritten. The real-company database received no such correction.

System Integrity: **21 healthy, two attention, zero critical**. The attention states are real scenario exceptions requiring review and absence of a recent verified backup; neither is concealed as healthy.

## 7. Broken workflows discovered and repaired

1. Fulfillment debt lacked its Daily Sale source link; the link now preserves correct source semantics.
2. Cash/debt reconciliation could double-count overlapping daily-sale, dispatch, optional-invoice and bank-deposit sources. Existing authorities now reconcile without creating additional sales.
3. RFQ numbering used the PR sequence and collided during realistic repeated procurement. Independent company-scoped RFQ numbering is covered by a regression.
4. Receipt-list eager loading omitted the monetary inputs needed by appended PO payment fields, producing HTTP 500. The compact relation now includes those authoritative fields; a populated receipt-list regression passes.
5. Repeated fractional inventory costs lost valuation carry. Outflows retain six-decimal value conservation; posting uses separately traceable cent-carry lines, and reconciliation sums per-product money values. Weighted-average/landed-cost regressions pass.
6. Warehouse demand incorrectly treated empty reserved buckets as stockouts; only available/legacy-available stock now establishes a local stockout.
7. Sampled-availability warnings discarded otherwise reconciled fulfilled demand. Known stockouts still censor demand; the warning remains separate metadata, not a fabricated continuous-availability claim.
8. Global search lacked decisions. Permission- and tenant-scoped decision results now have real detail links.
9. Common attention/stock-risk/supplier/reorder questions were unrecognized without a local language model. They now route to existing read-only evidence tools. Risk answers can include qualified recorded decisions without claiming a promoted ML champion.
10. The shipment-delay adapter looked for a flat product ID while V6 returns a nested product object, silently dropping affected products. It now uses the actual product and warehouse scope; the regression checks non-empty scenario cards and preserved source state.

## 8. UI findings and checks

Browser checks cover **33 routes**, **all 25 registered dashboard widgets**, populated product/search/source-link/assistant interactions, and **32 responsive route checks** at 1920, 1366, 768 and 390px. Light/dark themes and English/Albanian preferences are saved through the real preference API and asserted after navigation, not merely changed temporarily in memory.

No captured JavaScript page errors, HTTP 500s or document-level horizontal overflow remain in the final browser run. The product modal is centered, shows Available to Sell, closes with Escape and restores scrolling. Direct decision, PO and Order Hub source links resolve. The temporary 25-widget stress layout is restored afterwards.

Mobile breadcrumbs now clear the fixed navigation toggle. Synthetic shipment map copy no longer promises a live AIS broadcast for a synthetic carrier. Dark-mode warehouse and dashboard screenshots were visually reviewed after persistent theme checks.

## 9. Performance findings

Representative local API timings from the browser run:

| Endpoint | Time | JSON size |
| --- | --- | --- |
| Dashboard | 255 ms | 143,803 bytes |
| Monthly sales analytics | 98 ms | 6,673 bytes |
| Inventory intelligence | 60 ms | 4,052 bytes |
| Order Hub, 20 rows | 110 ms | 13,697 bytes |
| Financial intelligence | 207 ms | 281,842 bytes |
| Customer-sales intelligence | 314 ms | 459,775 bytes |
| Global search | 212 ms | 1,975 bytes |
| Optimizer list | 71 ms | 3,911 bytes |
| Simulation list | 57 ms | 805 bytes |

These are local diagnostic samples, not multi-user/load-test guarantees. Route timings include navigation and intentional readiness waits. The customer/financial intelligence payloads are relatively large and should be watched at larger company volumes. The 25-widget layout deliberately stresses reads; normal role defaults remain smaller.

Production build passes. Existing large-chunk warnings remain for the main bundle, PDF tools and 3D warehouse module; no heavy dependency was introduced for PM3.

## 10. Functions not fully exercised and why

- Production ML promotion and complete forward inventory forecast-window evaluation: legitimate eligibility requirements are not met; no labels or successes were fabricated.
- Supplier invoice/payment allocation: zero allocation records; this scenario uses PO-based supplier advances. A separate accountant-led scenario should exercise invoice matching and AP settlement before finance deployment.
- Actual external AIS accuracy, SMTP delivery, real carriers/commerce connectors, signing/licensing/update infrastructure, fiscal/tax filing and bank imports: isolated generation must not contact or impersonate those external services.
- Fresh-device desktop packaging and physical camera/barcode hardware: this phase exercises the shared web workflows and existing backend tests, not a new release installer.
- Real backup restore/disaster recovery: synthetic integrity correctly reports no verified backup. Existing backup tests are not a substitute for restoring a client's actual deployment backup.
- Invalid stock, unauthorized/self approval and closed-period violations are regression-test cases, not deliberately corrupted synthetic company history.

## 11. Assistant cross-domain results

The final report records question, state, source links, limitations, read-only flag and timing for each answer.

| Question | Result |
| --- | --- |
| What needs my attention today? | Success; five evidence sources |
| Which products are at highest stock risk? | Success; recorded forecast/decision sources, with their qualifications |
| Why is Milano 01 at risk? | Success; real product planning evidence |
| Which supplier should I consider? | Success; recorded supplier decisions |
| Which customers owe the most? | Success; receivable evidence |
| Which customers may reorder soon? | Success; customer-sales evidence |
| Build the best purchasing plan under €60,000. | Structured advisory plan; its actual feasibility/status remains visible |
| What if the incoming shipment is 10 days late? | Requires clarification: an exact shipment must be selected |
| Same delay question with shipment 68 selected | Success; shipment-linked product scenario |

All answers leave operational stock, orders, debt and journals unchanged. A success state means a sourced response was produced, not that a forecast is guaranteed or a requested plan is feasible.

## 12. Recommended checks before real-company rollout

1. Accountant-led supplier invoice matching/AP settlement and local tax-document review using the deployment's actual company configuration.
2. Create a verified backup and restore it into a separate fresh installation; check attachments, company boundaries and balances.
3. Collect sufficient real forward forecast windows and attributed supplier outcomes before promoting predictive models. Keep current fallback/limited-confidence labels visible.
4. Validate external integrations, secrets, signed release/update channel and licensing on the intended deployment; synthetic milestones are not a live-tracking certificate.
5. Test representative company volume and simultaneous users on the deployment server. Prioritize intelligence payload size and normal dashboard widget count if latency grows.

### Test evidence

- Full backend suite: **538 passed, one existing skip, 4,694 assertions**, 193.29 seconds; evidence in `backend/storage/app/synthetic/pm3-backend-tests.xml`.
- Focused assistant regressions: **17 passed / 142 assertions**.
- Isolation/optimizer/simulation checks: **43 passed / 361 assertions**.
- Focused planning: **18 passed / 159 assertions**; receipt/costing: **5 passed / 45 assertions**.
- Frontend unit tests: **72 passed**; local Python mathematics: **14 passed**.
- Browser: **two journeys passed**, followed by the persistent-theme responsive journey passing; zero page errors/server failures in the saved final browser report.
- Frontend production build: **passed**, 3,236 transformed modules; existing chunk-size warnings only.

### Explicit coverage flags

- Synthetic tenant isolated: **YES**.
- Chronological generation used: **YES**.
- Future-data leakage prevented: **YES** in the recorded cutoff checks and existing regression tests.
- Inventory integrity verified: **YES**.
- Financial integrity verified: **YES**.
- All major modules populated: **YES** for the existing company-operational and intelligence domains; external deployment services are intentionally excluded.
- Dashboard widgets tested with realistic data: **YES**, all 25.
- V1–V12 coverage achieved: **NO** if this means complete end-to-end predictive-model maturity. All V1–V12 functional/evidence/fallback paths were exercised, but completed inventory forecast windows and eligible supplier ML training histories remain absent. Calling that full predictive validation would be misleading.

Machine-readable source of truth: `backend/storage/app/synthetic/aims-pm3-20261006.sqlite.report.json`. Browser evidence: `output/pm3/browser-validation.json` and screenshots. Safe startup: `scripts/start-synthetic.ps1`.
