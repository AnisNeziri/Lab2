# DR0 — Development re-entry and next-cycle baseline

Inspection date: 16 October 2026. This is an engineering baseline, not a production approval or a claim that the real-company pilot has taken place.

## 1. Current HEAD and preservation

DR0 starts at PR3 documentation commit `f77b0e8f8f723cb38d48b10ce5a9faa62c1974ea`, on isolated branch `codex/dr0-development-baseline`, based on `codex/pr3-real-company-pilot`. Final verified application source: **d4339886672cfebcd3a2dc5d99c85205b2b74f0b** (initial fixes `21d4d178`, then password-route fix `d4339886`). This report is stored in a later documentation-only commit whose application code matches that source revision. Backend and performance checks exercise `21d4d178`; the only later application change is the frontend password-route loading guard, followed by fresh frontend build/tests and the auth browser group.

The original checkout remains on `codex/pr1-release-certification` at `e9581ea6d1ba1640a565a230b08983804aac3361`. DR0 does not alter its source, environment, credentials or business database. Existing web services on ports 8000/5173 and MariaDB on 3306 are separate from disposable checks. Worktree dependencies are independent directories. No merge, deployment, live import, live migration or password reset was performed.

PR3 preparation is finished in [draft PR #3](https://github.com/AnisNeziri/Lab2/pull/3). Actual pilot adoption remains blocked on the company, source data, authorized operators/reviewers, reconciled opening positions and operational observation period. See [PR3's explicit decisions](PR3_PILOT_REPORT.md). DR0 does not convert those unknowns into approvals.

## 2. Build status and explicit baseline verdicts

| Required decision | Result and scope |
|---|---|
| Current HEAD verified | **YES**, source revision above; targeted DR0 verification, not a new full release certification |
| Frontend build | **PASS**; production build 16.58 seconds, 3,268 transformed modules |
| Backend baseline | **PASS**; SQLite results recorded in section 22 |
| MariaDB path | **PASS**; isolated loopback 3312, owned datadir, fresh `aims_dr0_test_20261016a`; results in section 22 |
| Python intelligence tests | **PASS**; 30 tests across forecasting, supplier, financial timing, customer, optimizer and simulation modules |
| Representative browser workflow | **PASS**; 15 route/layout/interaction cases, 4 populated Orders/shipment cases, then 7 final auth/language cases; overlapping coverage is not summed as unique tests |
| Known dead UI remaining | **NONE confirmed** in the exercised representative routes; no whole-repository reachability proof |
| Critical inventory-integrity problems | **NONE found** in reviewed services and exercised fixtures |
| Critical finance-integrity problems | **NONE reproduced**; bank-match concurrency risk requires a focused investigation (section 17) |
| Tenant-isolation issues | **NONE found** in the representative two-company tests; not a proof for every endpoint/job |
| Critical security problems | **NONE known remaining in this review's exercised paths** after masking unexpected assistant exceptions; external integration/host certification remains incomplete |
| Ask AIMS current quality | **NEEDS WORK** |
| Shipment/import workflow maturity | **NEEDS WORK** |
| Desktop readiness | **PARTIAL** |
| Web deployment readiness | **PARTIAL** |
| Ready for next deep development cycle | **YES** as an isolated engineering starting point; primary operational use remains unapproved |
| Recommended next deep phase | **Controlled company data onboarding and opening reconciliation** |

Composer validation passes with constraint warnings; production locked Composer audit reports no advisories. Frontend production npm audit reports zero vulnerabilities and `npm ls --depth=0` resolves cleanly. Verified runtime: PHP 8.3.35, Node 22.14.0/npm 10.9.2, React 19.2.7, Vite 6.4.3, Playwright 1.64.0. Desktop and Linux certification used their workflow toolchains; the local disposable MariaDB engine is 11.8.9, hosted MariaDB certification is 11.4.

## 3. CI status and evidence boundaries

[AIMS CI #31](https://github.com/AnisNeziri/Lab2/actions/runs/38933923677) passed all eight mandatory jobs on **`c1d377d104de3bddd224bf9c27b4aeb0758da7ab`**, the PR3 application baseline. It includes SQLite 608 passing tests/5,329 assertions, MariaDB 608/5,330, one conditional Redis skip each; frontend audit/build/tests; Python; browser smoke and **102/102 release browser tests**; Linux Nginx/workers/scheduler; Windows bundled startup; and independent populated upgrade/recovery from the approved `5db86866` baseline. Optional candidate and review artifact jobs were intentionally skipped, with packaging disabled.

[Bounded-read performance CI](https://github.com/AnisNeziri/Lab2/actions/runs/38933732277) also passed on `c1d377d1`. Neither run is presented as full certification of the later DR0 source. PR3 final commit `f77b0e8f` only adds its report. DR0 validation below directly exercises the small later changes. No additional complete desktop/recovery/105-case hosted run was dispatched during DR0. AIMS CI triggers automatically for main-targeted changes; release certification is manual/reusable-workflow gated. Stacked PRs run the separate bounded-read workflow automatically, so absence of an automatic full release run is a configuration fact, not an inferred green result. Hosted actions also report Node 20 action-runtime deprecation notices: maintenance work, not a failed product check.

PR3 corrected the audit finding by updating only Symfony HttpFoundation 7.4.16 to 7.4.21, including the vendor's follow-up fix; see [the vendor advisory](https://symfony.com/cve-2026-108082). DR0 performs no broad dependency upgrades.

## 4. Current system map

Classification describes repository and test maturity. “Strong” does not mean commercially or physically certified.

| Area | Current implementation and entry points | Classification / integration gap |
|---|---|---|
| Identity, roles, company settings | JWT access/refresh rotation, temporary-password flow, role permissions, tenant context; AuthService/JwtService, settings/workspace routes | Strong fixture controls; real administrator access recovery and SMTP still installation tasks |
| Product master and stock | Products, units/conversions, barcodes, availability, traceability, valuation, adjustments; StockMovementService and InventorySnapshotService | Strong authoritative core; master-data cleanup needed before real adoption |
| Warehouse | Locations, stock states, transfers, receiving/putaway, pick/pack/dispatch, counts, variance and recount; WarehouseOperationsService, InventoryCountService, OutboundService | Functional broad lifecycle; physical device/workplace certification pending |
| Sales/customer operations | Order Hub, external channels/portal, SalesOrder, DailySale, invoices, debt and customer credit/advances | Functional integrated slices; several screens still expose related records separately |
| Procurement | Requests, RFQs, quotes, awards, PO status/payments/receipts, supplier catalog/performance, quality claims | Functional; import timeline and correction navigation can be clearer |
| Shipments/logistics | Vessel tracking, linked POs/cargo, warehouse destination, milestones, receipt scope, incoming-stock risk/control tower | Functional with explicit provider gaps; live AIS and non-vessel adapters unverified/disabled |
| Finance | AR/advances, AP/expenses/supplier invoice matching, allocations, cash/bank, reconciliation, journals/periods, recovery, landed cost | Strong service controls in fixtures; operator closure flow and bank concurrency need work |
| Documents | Versions, confidentiality, download, expiry/review/approval, archive, source links | Functional; real retention and document process unproven |
| Operational coordination | Action Center, tasks, exceptions, approvals, business events, Automation Studio | Functional; duplicate presentations and stale work need real-user measurements |
| Search/workspace | Ctrl+K entity/navigation search, contextual links, EN/SQ, user layouts/navigation, shared widgets | Functional; relevance coverage and search query fan-out remain costs |
| V1–V12 intelligence | Analytics/forecast and supplier evidence, replenishment/decisions, logistics, financial/customer intelligence, Ask AIMS, learning, optimizer, strategic simulation | Implemented and fixture-tested; qualification and utility depend on real history/outcomes |
| Onboarding/pilot | DataImportService, product CSV, Order Hub CSV; pilot preflight, rollout/review/evidence/feedback/aggregate usage | Partial real-company adoption: preflight is not a general controlled posting engine |
| Platform/release | Portable backups/restores, installation certification, worker/scheduler health, Linux scripts, Electron portable SQLite runtime | Verified automation foundations; production host, signed distribution and real restore remain external gates |

Inventory measured at re-entry: 518 application PHP files (~48,574 lines), 231 frontend JS/JSX files (~30,419 lines), 118 migration files, 73 backend test files. These are inspection counts, not code coverage claims.

## 5. Production-strength areas

The strongest foundations are authoritative stock movements with ledger history, state/availability separation, receipt and dispatch retry protection, financial posting/reversal controls, scoped business models, tenant-first binding, token revocation, permission-aware read tools, explicit AI evidence gates, immutable pilot evidence, and independently exercised backup/upgrade/startup workflows. These should be extended through their current service contracts.

The tests exercise fractional units, partial receipts, cash/advance allocation, duplicate commands, closed periods, stale review fingerprints, cross-company access and safe model fallback. Repository verification supports continued development; real stock/finance reconciliation and staff operation are still required for production approval.

## 6. Functional but immature areas

Orders now show a next action and connect dispatch to one Daily Sale and linked invoice. Procurement, receiving, returns, supplier matching and landed cost exist. Remaining friction is the number of separate screens needed to answer “what happens next?” and correct an exception. Generic finance expense routes can reverse posted expenses or delete drafts; absence of a dedicated supplier-invoice delete button does not imply missing accounting reversal support.

Action Center combines tasks, approvals and evidence cards, with permission filters and pagination. It can still present several manifestations of one business problem. A real-user study should measure whether a resolved source stops demanding attention, whether recommendations become stale, and how many notifications become actionable tasks.

Lifecycle spot check, based on routes and service behavior:

| Record | Supported closure/correction | Gap or domain restriction |
|---|---|---|
| Purchase request / RFQ | Draft/rejected request revision, submission/approval, RFQ issue/quotes/award/conversion | No exposed owner withdrawal, unused-draft delete or RFQ close/cancel path found. Approval cancellation maps the parent request to rejected, not a distinct withdrawal. A lingering active draft can block a later replenishment draft. **Incomplete vertical closure**, not a reason to add unrestricted deletion. |
| Task / automation | Task update, complete/cancel/reopen; rule edit/enable/disable, immutable versions and bounded failed-run retry | Retain run history; disabling a rule should be paired with review of its already-created unresolved tasks |
| Approval | Authorized approve/reject/cancel with decision history, separate document review behavior | Creator withdrawal is not equivalent to permission to decide; lifecycle and parent-status transaction need review |
| Inventory count / transfer | Unused count draft delete, allowed cancel, recorded/submitted/approved history protection, recount/variance workflow; transfer cancel before completion | Posted physical history must be corrected through authoritative movements, not erased |
| Document / recommendation | Document metadata/version/review/archive/restore; recommendation feedback and decision reviewed/accepted/modified/dismissed, automatic supersession/resolution | Preserve legal holds, source lineage and posted/consumed outcomes; no generic delete-all requirement |
| PO / invoice / landed cost | Domain-limited draft edit/delete, completion/cancel where permitted, posted reversal/correction and audit | UI navigation can make existing correction routes hard to discover |

## 7. Partial and experimental areas

Real pilot qualification is absent. Forecast/supplier promotion requires existing evidence gates and independent windows; synthetic scores cannot satisfy the real-source requirements. Optimizer alternatives, decision learning/challengers and strategic scenarios remain advisory with explicit human conversion boundaries. No V13 or new learning architecture was added.

Parcel/air provider adapters, public updater/signing, host-specific TLS/Redis/mail operations and physical barcode/warehouse devices lack current real-world certification. CSV templates and preflight are useful preparation, not proof that a company's balances and opening stock can be safely posted and reconciled end to end.

## 8. Broken or regressed functionality found

1. **Unexpected simulation exception disclosure, fixed:** IntelligenceAssistantService caught arbitrary exceptions and copied `getMessage()` into a successful assistant response, bypassing the global safe renderer. A fault containing SQL/private markers reproduced the unsafe path. The new test first failed against the old code, then passed with a generic EN/SQ response and safe correlation metadata.
2. **Direct password-page reload redirected away, fixed:** the route decided authentication before local session hydration. It now waits for `authReady`, consistent with the other auth routes. The initial populated diagnostic reproduced this redirect; a permanent browser smoke test covers direct loading and refresh, plus anonymous denial.
3. **Missing password-page translation, fixed:** ChangePassword headings, labels, explanations and actions were English-only under Albanian preferences.
4. **Long visual test reliability, fixed structurally:** one test spent a single 240-second budget on 75 route/viewport visits. The local PR3 rerun reproduced that timeout despite hosted success. DR0 isolates three viewport/theme cases, retains the 240-second budget per case and all page-error/overflow checks, and additionally verifies/reapplies language/theme after each navigation.
5. **Stale setup claims, corrected:** README's 31-table total, five generic import lists, “no polling”, Node 18/pnpm instructions and universal repository/company-scoping claims did not describe the current implementation.
6. **Remaining query gap:** `whats low stock` routes to an insufficient generic record request. See section 14; this is retained as a bounded next-cycle language issue, not hidden by a passing routing assertion.

No confirmed dead button or dead route was removed. Placeholder form text and intentionally disabled provider controls are not dead-code evidence. Static scans and route smoke do not establish that every control is reachable or useful; uncertain services/models were retained.

## 9. Low-risk fixes performed

| Files | Change and verification purpose |
|---|---|
| `backend/app/Services/IntelligenceAssistantService.php` | Unexpected scenario failures use existing PilotSupportService, safe AIMS reference, translated retry message; 403 still propagates and validation stays actionable |
| `backend/app/Services/StrategicSimulationAssistant.php` | Deliberate missing/ambiguous-input prompts use ValidationException, distinguishing expected clarification from internal failures |
| `backend/tests/Feature/IntelligenceAssistantTest.php` | Three regression tests cover redaction/log metadata in both languages, unchanged stock/journal counts, validation, permission denial and real adapter missing/ambiguous input |
| `frontend/src/pages/ChangePassword.jsx` | EN/SQ copy and accessible alert, with request/token/password policy unchanged |
| `frontend/src/App.jsx`, `frontend/e2e/specs/auth.spec.js` | Wait for session hydration before deciding the password route; direct load/refresh and anonymous denial smoke coverage |
| `frontend/e2e/specs/ui-ux.spec.js` | Three independently budgeted visual cases, stronger theme/language checks; existing interaction cases retained |
| `README.md` | Current toolchain/schema/import/polling/architecture information and links to PR3/DR0 evidence |

No authoritative stock/finance implementation, schema, roles, credentials, model thresholds or promotion rules changed. The password route still requires authentication; only its premature redirect during hydration changes.

## 10. UX findings remaining

WorkspaceModal, SearchField, shared status utilities, layout styles and focus/scroll helpers improve consistency, and search restores focus and supports keyboard operation. Large finance/procurement screens still implement substantial local button/form/table/status logic. Prefer gradual extraction into existing shared components when a real correction workflow benefits; avoid a framework rewrite.

Orders: distinguish intake status, payment, fulfillment and delivery at a glance; preserve customer/items/total/availability and “next action” in the detail. The existing tested reserve → ready → dispatch → delivery → Daily Sale → invoice flow avoids a second stock effect. Remaining opportunities: reduce secondary-action hunting, explain backorders and show correction/reversal links in context. Dedicated warehouse fulfillment remains appropriate for detailed picking. Fresh 390px populated screenshots show that availability columns require scrolling within the product table and the next-action card sits below a tall overview; some SQ status/date text, including Draft, remains English.

Shipments: the populated Albanian dark view visibly shows tracking reference, origin/destination, recorded ETA versus AIMS ETA, destination warehouse, linked PO/supplier, unit-aware cargo, receipt quantity and stock risk. It also honestly shows absent vessel details/provider configuration. The map can imply more precision than the unknown current position; emphasize last verified observation. Supplier/PO-level receipt attribution is explicitly labeled and must not be represented as per-shipment proof.

Visual evidence: PR3's 108-view audit and full hosted run are baseline evidence. DR0 repeats required representative widths/themes/languages and inspects fresh populated Orders/shipment screenshots (section 22). No universal pixel-perfect claim is made. Long dense tables, small-screen multi-action panels, separate correction screens, partial SQ validation copy and provider explanations remain improvement areas.

## 11. Backend integrity, transactions, idempotency and permissions

| Workflow | Reviewed authority and safeguards | Remaining review limit |
|---|---|---|
| Stock movement/valuation | StockMovementService: transaction, product/source locks, request fingerprints, movement history and after-commit publication | No whole-codebase proof; raw DB use in backup/synthetic tools is not itself an operational bypass |
| Receipt | PurchaseOrderService::receive locks PO, checks idempotency/fingerprint and uses authoritative movement services | Continue real concurrent receive/partial/cancel drills before cutover |
| Transfer/count | WarehouseInventory/operations and InventoryCountService handle state/location projection, variance approval and recount | Projection assignments are expected; do not replace them with generic product writes |
| Fulfillment/returns | OutboundService reservations, allocated bins/traceability and retry-safe dispatch | New channel adapters must continue using this boundary |
| Customer debt/payments | CustomerDebtService and FinancialAccountService maintain ledger, separate advances and reversals | Operator account/AR opening reconciliation not yet real-world verified |
| Supplier advances/allocations | PurchaseOrderService, SupplierPaymentAllocationService, SupplierInvoiceMatchingService | Cross-screen correction experience is immature |
| Journal/period/recovery | AccountingService posts/reverses, period controls and audited one-time recovery | Do not edit posted history to make a report agree |
| Landed cost | LandedCostService locks receipt/product/allocation, draft delete and posted reverse | User comprehension of allocation/valuation deserves a focused walkthrough |
| Automation | AutomationService: event/rule-version execution key, locked transactional attempt, per-action dedupe, creator permissions, depth limit and bounded retry | Real notification quality and long-lived task freshness not measured |
| Scenario/optimizer preparation | Isolated result persistence, source fingerprints, explicit review/conversion permissions | A scenario may persist advisory runs; `read_only` means no operational stock/order/payment effects |

No accidental direct product-quantity or account-balance controller mutation was found in the spot searches and reviewed critical paths. This is a bounded review, not an assertion that all 518 PHP files received line-by-line inspection. Bank matching has a concrete serialization concern below and was not casually rewritten.

**P1 transaction-boundary concern:** ApprovalController::decide calls ApprovalService's transactional decision, then updates a purchase request's parent status outside that transaction. If the second write fails, the approval can be committed while the request remains submitted. This follows directly from the two boundaries in code; DR0 did not inject a database fault to demonstrate it. Move both lifecycle changes under one authoritative transaction with rollback/retry tests in a focused follow-up. This is separate from a stock or journal imbalance.

Tenant controls: authenticated tenant-first route binding, `BelongsToCompany` create/update protections, company channel authorization, permission middleware and service/tool gates. When no authenticated company exists, the trait does not magically filter background queries: jobs must set explicit context. Representative tests cover product reads/writes, customers/predictions, orders/channels, suppliers/quality, POs, shipments, confidential documents, finance, tasks, decisions, optimizer/simulation and user workspace isolation. Tests include forbidden IDs and foreign records absent from suggestions, not just hidden navigation.

## 12. Database findings

Fresh E2E SQLite inspection found **181 tables, 118 applied migrations and 300 indexes**. These counts include framework metadata. Migrations execute on SQLite and MariaDB in the current baseline; DR0's fresh MariaDB path repeats relevant behavior and timestamp safety checks. No business database schema change is made.

High-value follow-ups: enforce exclusive active bank-match ownership under concurrency; inventory duplicated quantity/currency projections with documented authority; profile indexes for actual search predicates before adding indexes; separate expensive frozen-result read projections where measured. FI/CSI/optimizer/simulation evidence JSON retains reproducibility value but whole-document decoding and response size are real costs. Do not normalize every JSON field or remove seemingly duplicate money columns without understanding currency/history semantics.

Nullable dates and denominators are often deliberate unknowns, not zero. PR3 company-timezone and closed-calendar checks retain that distinction. Production row quality, orphan counts and actual opening totals were not inspected because no company dataset was authorized for mutation or supplied for reconciliation.

## 13. Performance findings

Fresh DR0 authenticated in-process profile reused the marked SQLite PR2 fixture: 1,001 products, 2,000 customers, 100 suppliers, 50,000 orders, 101,000 movements and 100,000 debt transactions. One cold plus three warm reads per endpoint; numbers below are warm medians. This excludes network/browser/bootstrap and runs on the development machine alongside other checks. It is neither a load test nor a customer SLA.

| Endpoint | Median ms | Final response bytes | Final query count |
|---|---:|---:|---:|
| Dashboard | 460.05 | 79,987 | 12 |
| Order Hub | 64.16 | 13,372 | 29 |
| Product detail | 40.87 | 33,282 | 17 |
| Stock movements | 54.59 | 135,500 | 9 |
| Customer debt list | 9.35 | 18,419 | 4 |
| Purchase orders / goods receipts | 35.61 / 18.83 | 17,582 / 19,086 | 6 / 8 |
| Shipments | 39.53 | 80,605 | 7 |
| FI full / overview | 96.66 / 56.18 | 5,071,564 / 1,050 | 3 / 3 |
| CSI full / overview | 93.26 / 107.55 | 4,701,215 / 1,018 | 7 / 7 |
| Global Search negative / positive | 378.53 / 192.05 | 2 / 7,915 | 80 / 80 |
| Global Search exact order | 274.69 | 1,930 | 69 |
| Ask AIMS debt query | 264.00 | 7,660 | 4 |
| Action Center | 1,267.52 | 4,885 | 31 |

A compact, payload-free measurement and language-probe record is committed in [BASELINE_EVIDENCE.json](dr0/BASELINE_EVIDENCE.json).

Profile exits PASS because measured requests succeed, not because every target is met. There are no completed heavy optimizer/simulation records in this fixture; **detail measurements are NOT TESTED**, not substituted by list timings. Baseline CI tests protect their bounded read contracts separately. Known PR2 MariaDB worst-case search (~836 ms) remains historical evidence, not a fresh DR0 MariaDB timing.

Priorities: Action Center fan-in/frozen evidence decode, dashboard aggregate work, Global Search candidate/query fan-out, and legacy unbounded customer ledger/daily-sales/credit report paths. The large full FI/CSI responses are ~9.77 MB combined even though paginated/summary views are small; keep normal consumers on bounded shapes and avoid decoding whole snapshots repeatedly. Frontend build still warns about lazy PDF (~541 KB), map (~941 KB), PDF worker (~1.32 MB), charts (~414 KB) and main entry (~466 KB) chunks. Do not hide this by increasing warning thresholds.

## 14. Ask AIMS findings

Fresh API probe used a disposable two-company fixture with Milano 04, its supplier, a PO and incoming shipment, plus a foreign-only product. All 13 requests returned controlled responses, foreign records were absent, and stock movements, journals, POs, debt rows and product quantity stayed unchanged. Persisting an isolated advisory scenario is distinct from writing an operational order.

| Imperfect input | Actual intent/tool/result |
|---|---|
| `whats low stock` | Generic `record`, insufficient; asks for an entity. **Routing gap.** |
| `which customer owe me most` | `debt`, `get_receivable_intelligence`; insufficient because this fixture supplies no debt evidence. No fabricated ranking. |
| `milnao 04 stock` | `record`, fuzzy resolution to Milano 04, `get_product_availability`, success |
| `ku osht porosia prej kines` | `shipments`, requires a record selection in this fixture. Broad origin wording is not silently treated as a unique shipment. |
| `cilat produkte kan me met pa stock` | `stockout`, inventory recommendations/replenishment tools; insufficient qualified evidence |
| `which suplier is better` | `compare`, asks for product context; with a known product follow-up it uses `get_supplier_options` |
| `what if shipment late 10 days` | Isolated `strategic_simulation`, `run_strategic_simulation`, success and explicit scenario limitation |

The follow-up chain `whats stock risk milano 04` → `why first one` → `which suplier can cover` → `what if shipment 10 days late` → `how much i need order` retains the authorized product, selects its incoming shipment, calls the existing delay tool and restores product replenishment context. These are fixture routing/lineage observations, not evidence of forecast accuracy.

Quality verdict: **NEEDS WORK**. Typo/entity/context controls and known/unknown separation are useful. Improve short business aliases, origin-based clarification, bilingual validation and the distinction between stock-below-minimum facts versus forecast stockout risk. A missing forecast must not be made to appear available merely to answer “low stock.” Some read tools catch failures as generic insufficient source evidence; correlation coverage should expand without exposing exceptions. Repeated inventory tool entries in the broad stockout response also deserve composition cleanup.

## 15. Intelligence health and product direction

Treat V1–V12 as one decision flow: operational facts → qualified evidence → prioritized decision → human review → source-linked draft → operational outcome → measured learning. Current snapshot/version/gate boundaries, explanation/source links, local-only provider fallback and scenario isolation are worth preserving.

Use daily: source-linked stock/replenishment, debt/credit facts, supplier delivery evidence and shipment exposure in the record being worked on. Surface those in Orders/PO/receiving/Action Center rather than requiring users to tour every intelligence dashboard. FI/CSI/analytics risks overlap with separate decision/task cards; a shared business-problem identity can consolidate presentation while retaining evidence lineage.

Near-term high value: robust bilingual business phrasing, clearer unknown/source-age statements, contextual next actions and measured task outcomes. Requires more real data: forecast calibration, supplier reliability, customer reorder/collections behavior, decision outcome learning and promotion evidence. Research/later: broader autonomous planning, richer long-horizon simulation and new model families. Do not add predictive sophistication before data quality and observed operational usefulness are established.

## 16. Shipment and importer workflow

Existing chain: purchase recommendation/request → supplier/RFQ/award → PO → advance/payment → shipment/vessel/milestones → incoming stock/risk → receipt → landed cost → warehouse inventory → sale/debt. Company-scoped links and unit-aware cargo are present. Supplier/PO receipt scope is explicit; shipment allocation and loaded quantities remain unknown where records do not establish them.

Gaps: a single understandable import timeline, production/waiting state ownership, customs/logistics cost/document handoffs, multiple-shipment receipt attribution and correction navigation. These are orchestration/adoption gaps between existing modules, not justification for another isolated importer subsystem. Live provider accuracy, port/customs partner integration and real ETA outcomes are unverified.

## 17. Finance maturity

AR, separate customer advances, supplier advances/AP, PO payments and reversals, expense-based supplier invoice matching, payment allocation, cash/bank transactions, journal posting/reversal, periods, reports, recovery and landed cost all have implemented slices. Matching and close-readiness controls are exercised. Do not describe them as missing because the dedicated screen lacks a shortcut to the generic correction path.

**Concrete P1 risk, not reproduced ledger corruption:** BankReconciliationService::reconcile locks the statement row, then performs an `exists` check for a different reconciled row using the same transaction. Two different statement rows do not lock a common transaction/ownership record. The schema permits repeated `matched_transaction_id` because suggestions can legitimately share a candidate. Under concurrent matching, exclusive active ownership is not visibly serialized by this path. Build a two-connection MariaDB reproduction and agree a lock/uniqueness strategy covering reconcile/unmatch/reversal. Do not casually add a global unique constraint that would reject suggestions. No bank balance or journal change was observed from this review, and no finance architecture was rewritten.

Missing next vertical closure: independently reconciled opening AR/AP, advances, bank and inventory values with signed source totals; operator-readable correction navigation and a real month-close drill. Those are more valuable before expanding finance feature breadth.

CustomerController still computes overdue-list business dates using `Europe/Tirane`, while newer services use company-local dates. This is a concrete legacy consistency gap for companies outside that timezone, especially near midnight; verify and centralize reporting-day semantics before multi-timezone rollout. It does not demonstrate a changed debt balance.

## 18. Warehouse maturity

Implemented: receiving, putaway/location stock, warehouse/bin transfers, stock states/traceability, picking/packing/dispatch/returns, counts, recount/variance approval, barcode normalization and alternative identifiers. Inventory invariant tests cover allocated outbound, FEFO, reservation limits, partial receipt/incoming dates and unknown/future availability.

Not certified: physical scanners/cameras/printers, labels in the actual warehouse, patchy connectivity, worker ergonomics, actual lot/expiry rules and end-of-day reconciliation. Run a receiving-to-putaway-to-pick-to-return/count exercise on real devices before claiming warehouse operational readiness. Keep mobile screens concise and approvals with authorized reviewers.

## 19. Security, errors, logging and configuration

P0: no reproduced tenant leak, unauthorized stock/finance write or remaining critical disclosure in the reviewed paths. P1 repaired: unexpected strategic-simulation exception text now becomes a safe support reference; deliberate clarification remains typed validation and 403 remains denied. P1 operational work: authenticated host setup, real administrator recovery, real-source approval and independent restored-copy verification before rollout. P2: expand safe references to swallowed source/tool failures and review configuration/documentation drift.

ProductionSecurityTest covers token reset/replay/rotation, issuer/algorithm/time rejection, log redaction, safe disabled broadcasting, timestamp safety and tenant binding. PilotSupportService logs route template, exception class, status, company/user and reference, excluding message/body/bindings. Automation failures retain safe class/correlation and bounded retries; workers, backup runs and shipment jobs have their own statuses. This is useful but fragmented; expose the existing references/statuses together before adding infrastructure.

`.env.example` documents local database/cache, offline mode, mail/Reverb, provider credentials and pilot company selection. Local example secrets/defaults are examples, not production keys; deployment requires generated keys, debug off, appropriate queue/cache/provider mode. Optional providers and Redis must remain explicit in health. Assistant/optimizer/synthetic defaults live in dedicated config and operational docs; consolidate documentation rather than indiscriminately exposing every internal knob. Composer's exact amphp websocket constraint and wildcard DomPDF/Predis/Stripe constraints warrant maintenance review; audits are clean at this revision, not a guarantee about future advisories.

## 20. Desktop status

**PARTIAL.** PR3 source `c1d377d1` passes hosted Windows build/startup tests with bundled production dependencies, local assets/Python, fresh SQLite, recovery identity, workers/scheduler, crash/restart and idempotent upgrade. Offline/local database and backup paths have fixture evidence. DR0's changes are application error handling, translated copy and test/docs improvements; DR0 did not rebuild an installer or perform another complete desktop certification.

Unverified commercially: signed installer, trusted distribution/update server, installed-user update/rollback across real versions, actual devices, customer-specific backup recovery and long-duration use. A fixture startup and unsigned review package do not establish production readiness. No customer installation was updated.

## 21. Web deployment status

**PARTIAL.** The current lineage has hosted Linux Nginx, worker/scheduler and MariaDB checks, plus documented Ubuntu/PHP-FPM/Redis/Python configuration and independent recovery. DR0 confirms source build and both database paths. No actual production domain/host, TLS, real SMTP, Redis failover, scheduled off-host backups, provider credentials or host reboot drill was certified. Supply those and perform a real restored-copy reconciliation before primary operational use. No new web architecture or deployment was introduced.

## 22. Test health, browser coverage and synthetic infrastructure

| Fresh DR0 check | Result / evidence |
|---|---|
| Frontend Node | 89/89 passing; `output/dr0-frontend-tests-final.txt` |
| Frontend production build | PASS; `output/dr0-frontend-build-final.txt` |
| SQLite targeted baseline | 139 passed / 1,177 assertions, 49.17 seconds; `output/dr0-backend-complete.txt` |
| MariaDB targeted baseline | 107 passed / 983 assertions, 158.66 seconds; `output/dr0-mariadb-final.txt` |
| Post-final clarification/security/domain supplement | SQLite 49 passed / 357 assertions, 13.34 seconds; MariaDB 81 passed / 565 assertions, 92.00 seconds. These intentionally overlap the baseline and cover the final missing/ambiguous-input adapter test plus stock/debt/security/PO/workspace paths; `output/dr0-backend-supplement.txt`, `output/dr0-mariadb-supplement.txt`. Counts must not be added as unique tests. |
| Python | 30/30 tests passed across all six Python test modules; `output/dr0-python-all.txt`. Fixture math, not real qualification |
| Ask AIMS two-company diagnostic | 1 test / 55 assertions, 13 actual API answers plus supplier/customer foreign read/write/list denials; `output/dr0/assistant-probe.json`, no operational count/quantity changes |
| Existing large-fixture performance | PASS request statuses, 26 measured endpoint shapes; `output/dr0/performance-current.json`; two heavy detail shapes explicitly NOT TESTED |
| Browser / populated visual | 15/15 targeted release cases passed (11.8 minutes), including all 3 split visual cases, 768/1920 EN-light/SQ-dark platform sweeps, product dialogs, Ctrl+K and mobile navigation. A separate run passed 4 populated Orders/shipment cases, including 16 populated screenshots at 1920, 1366×768, 768 and 390 in EN/light and SQ/dark. Its initial password-page case exposed the hydration redirect and failed. After repair, all 7 auth/language cases passed (58.0 seconds), including direct load/reload, anonymous denial, staff restrictions and EN/SQ password rendering without credential submission. Logs: `output/dr0-browser-final.txt`, `output/dr0-populated-browser.txt`, `output/dr0-auth-browser-final.txt`; screenshots under `output/dr0/visual-results` and `output/dr0/password-final`. |
| Dependency resolution/audits | Composer valid with constraint warnings, no production advisories; npm production audit zero and installed dependency tree resolved |

High-value tests protect operations, reversals, tenant binding, stale-source review, duplicate commands, safe unknowns, restoration and actual role-specific journeys. Small copy fixes do not need implementation-mirroring unit tests; password-page rendering is checked without submitting credentials. The new backend fault-injection test is valuable because it exercises a disclosure boundary the global renderer cannot protect.

PR3 local full browser run had 98 pass/4 failures, then 12 pass/1 long visual timeout on reruns. Hosted PR3 was 102/102. The original failures included whole-test limits and `ERR_NO_BUFFER_SPACE` during a very long route sweep; subsequent retained SQ preferences amplified one failure. DR0 splits only the monolithic viewport test, adding two cases to the overall suite, retaining assertions and budgets. The new password-route smoke adds one further case. It does not claim that the new full 105-case release suite was run. Keep route matrices independent from business lifecycle journeys and reset server preferences between cases.

Representative release coverage already includes login/dashboard, products, order dispatch/sale/invoice, debt/payment, PO/receipt, shipment, Action Center, Ask AIMS and workspace settings. Fresh DR0 targeted checks and new populated diagnostics are listed above, with older whole-suite evidence clearly separated. Missing high-value coverage: real concurrent bank match, real operator cutover/close/recovery, flaky network/hardware/provider behavior and AI usefulness with actual history.

PM3 synthetic commands retain fixed owned SQLite paths, marker/seed/completion checks, unrecognized-file/reset refusal, symlink checks, isolated mail/queue/cache and disabled demo recovery identity. SyntheticCompanyTest verifies refusal preserves bytes and seeded reproducibility. The full chronological generator and faster small configured datasets coexist; PR2 bulk-performance fixtures have a different marker and cannot establish chronological/model accuracy. DR0 reuses existing marked performance data and does not regenerate a long chronological certification dataset or backdate evidence. Do not run synthetic generation against business storage as a substitute for onboarding.

## 23. Top technical-debt hotspots

| Hotspot | Evidence / bounded improvement |
|---|---|
| Invoices.jsx | ~2,008 lines; separate editor, compliance preview and lifecycle sections without changing posted rules |
| CustomerDebts.jsx | ~1,509 lines; extract statement/payment/advance presentation around existing server totals |
| PurchaseOrders.jsx | ~1,488 lines; status/payment/receipt/correction navigation needs a cohesive detail flow |
| DailySales.jsx | ~1,167 lines; split entry/history/correction responsibilities |
| Products.jsx | ~1,136 lines; reusable selectors/forms, preserve draft state across refresh |
| Warehouse3DMap.jsx | ~864 lines plus ~941 KB lazy chunk; isolate interaction state and defer expensive features |
| PortableBackupService | ~1,534 lines across integrity, streaming, export/import; keep recovery tests before extracting internals |
| InvoiceService | ~1,030 lines; compliance/posting/lifecycle responsibilities deserve small seams |
| StockMovementService | ~997 lines; protect one authority, extract validators/calculators only under invariant tests |
| PurchaseOrderService | ~852 lines; receiving/payments and revisions tightly coupled |
| InventoryCountService | ~852 lines; count/recount/variance state transitions require shared review helpers |
| WarehouseOperationsService | ~806 lines; coordinate bin/stock-state/trace actions with one transaction boundary |
| TraceabilityService | ~606 lines; document projection ownership before edits |
| Financial/Customer intelligence services | Dense frozen JSON processing; query small views without repeated full decode, preserve lineage |
| AssistantPlanner/IntelligenceAssistantService | Regex precedence and dense orchestration; add behavioral language corpus, typed tool outcomes and safe diagnostics incrementally |
| ApprovalController / ProcurementService | Dense controller status propagation and missing request/RFQ closure; put parent lifecycle changes inside the authoritative transaction before adding actions |
| ActionCenterService / SearchService | Multi-domain fan-in; measured 1.27s and up to 80 queries justify profiling before extraction |
| Browser route/theme matrix | Repeated hard navigations and shared server preferences; isolate contexts/budgets and use populated assertion points |

Counts are a re-entry snapshot, not an instruction to split files solely because they are long. Avoid deleting dense but active code as “legacy.” No abandoned service/job/route was confidently established by references and tests in this bounded inspection.

## 24. Prioritized development backlog

| Priority | Work / acceptance evidence |
|---|---|
| P0 | No confirmed unresolved P0. If a tenant leak, stock bypass or unbalanced posting is reproduced, stop feature work and add a failing invariant/authorization test before repair. |
| P1 | Reproduce and close exclusive bank-match concurrency using two MariaDB connections; cover reconcile/unmatch/reversal and suggestions. |
| P1 | Controlled onboarding: customer/supplier/product identity mapping, duplicates/units/currency/warehouse rules, preview totals, resumable idempotent batches through authoritative services, independently reconciled openings and restored-copy review. |
| P1 | Close operator paths for order exceptions, supplier invoice/payment corrections, receipt/landed-cost review and month close, linking existing records rather than duplicating engines. |
| P1 | Purchase-request withdrawal/unused-draft cleanup and RFQ closure with downstream guards; make approval decision and parent-request status atomic, with a fault/retry test. |
| P1 | Ask AIMS short-query corpus, low-stock fact versus stockout prediction, origin clarification, bilingual expected errors and safe tool-failure references. Acceptance: named/ambiguous/foreign/unknown cases, unchanged operational effects. |
| P2 | Action Center business-problem grouping, resolved/stale behavior and notification deep-link/noise measurements with real staff. |
| P2 | Profile and bound dashboard/search/frozen evidence decode and remaining legacy report/ledger shapes; keep target/budget/fixture envelope distinct. |
| P2 | Shared form/table/status/correction components in high-use screens, narrow mobile actions, translation gaps and viewport-test isolation. |
| P2 | Dependency constraint review, Node action-runtime maintenance, current configuration/API/schema docs and consistent existing diagnostic references. |
| P2 | Signed desktop distribution/update rollback, real host/SMTP/Redis/reboot/recovery and warehouse devices as explicit commercial gates. |
| P3 | New providers/advanced integrations, richer learning/model families or expanded autonomous planning only after real data and observed workflow value justify them. |

## 25. Recommended development tracks

1. **Company onboarding and data control:** import identity/preview/batch recovery/opening reconciliation; saves cutover time and prevents duplicate or unbalanced openings.
2. **Sales and cash-control closure:** connected order/AR/advance/payment/correction/close paths and bank-match safety; reduces operational and cash errors.
3. **Procurement, import and warehouse execution:** one PO/shipment/receipt/landed-cost timeline plus real-device validation; makes incoming stock understandable and dependable.
4. **Evidence-based decision assistance:** contextual stock/supplier/customer guidance, bilingual Ask AIMS and clear unknowns; improves daily decisions without bypassing review.
5. **Operational inbox and automation:** source-linked problem grouping, stale/resolved work, safe retries and notification quality; reduces missed work and noise.
6. **Performance and maintainability:** measured query/payload work and incremental seams in oversized screens/services; supports multi-user growth while preserving authorities.
7. **Commercial operation and distribution:** real rollout/restore, host/provider certification, signed desktop updates and support diagnostics; turns test verification into installation evidence.

## 26. Quick wins before the deep phase

1. Add a narrow low-stock factual query alias and bilingual behavioral cases, keeping it distinct from predictive stockout evidence.
2. Translate remaining scenario clarification and Orders status/date text using the existing translation pattern.
3. Add contextual “open correction/reversal” links to existing finance/PO routes where permission and lifecycle allow it.
4. Display source age/unknown position next to shipment map and ETA, avoiding implied live certainty.
5. Surface safe source/tool failure references in assistant limitations using the existing support service.
6. Make browser preference reset helpers consistent after failed theme/language cases.
7. Add a concise current domain/API/schema index linked from the historical lab documentation.
8. Update deprecated CI action runtimes in a dedicated dependency-maintenance change with existing release checks.

These are proposals, not silently implemented additional scope. Bank-match concurrency is important but not a casual quick win; it requires a realistic competing-transaction test and design review.

## 27. ONE recommended next deep phase

**Controlled company data onboarding and opening reconciliation.**

Why now: the repository already contains substantial inventory, purchasing, sales, warehouse, finance and advisory capability, while the real-company pilot has no reconciled source dataset. Generic import covers four lists and dedicated product/order CSV paths; pilot preflight deliberately does not post. Adding more intelligence before a reliable opening dataset increases complexity without validating business usefulness.

Business value: reconcile product identities/units/warehouse stock, customers/suppliers, AR/AP/advances and bank positions once; eliminate spreadsheet re-entry and prevent accidental duplicate operational or financial effects. Provide source totals, preview differences, explicit human review and a separately restored-copy check before committing the cutover.

Dependencies: one named company/installation, authorized roles and independent reviewers, real source exports and cutover date/calendar, backup and restore destination, agreed authoritative mappings and the existing service contracts. Resolve any newly confirmed integrity/security blocker first; bank-match investigation is a finance prerequisite before operational bank reconciliation.

Scope: a medium cross-module phase, larger than a screen cleanup and smaller than a finance/AI/platform rewrite. Deliver one reviewed batch lifecycle with idempotent resume/reject/correct behavior, lineage and reconciliation evidence, then use it for an actual pilot opening. Do not implement a new accounting engine, AI generation or general integration platform. Main risks are identity ambiguity, units/currency conversion, historical double posting and irreversible cutover; mitigate with dry runs, bounded batches, existing authorities, human sign-off and a tested restored copy.

DR0 ends at this recommendation. The phase and V13 are **not started**.
