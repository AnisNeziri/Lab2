# AIMS Enterprise Architecture and Feature Audit

Audit date: 13 August 2026  
Scope: Laravel backend, React frontend, MySQL/SQLite schemas, services, API routes, tests, jobs, integrations, Electron runtime, licensing, update and backup paths.

## 1. Executive summary

AIMS is best classified today as an **Inventory + Procurement System evolving into a lightweight ERP**. It is beyond a basic stock application: it has company-isolated inventory, an auditable stock ledger, daily sales, customer debt ledgers, purchase orders with partial receiving and payments, Kosovo-oriented B2B invoices, an expense/AP and VAT-preparation Finance Center, shipment tracking, reports, notifications, granular permissions, activity logging, and a self-contained licensed desktop runtime.

It is not yet ERP-level accounting or WMS architecture. The main constraint is that physical inventory is still sourced primarily from one `products.quantity` value. Warehouses/sections are visual assignments rather than an independent location balance ledger. Procurement, shipment, goods receipt, supplier invoice and landed cost are not one continuous transaction chain. Finance is a strong pre-accounting layer, but there is no general ledger, chart of accounts, bank/cash account ledger, reconciliation, fixed-assets ledger or mathematically complete financial statements.

The correct direction is to keep the modular monolith and incrementally add ledger-backed submodules. A rewrite or microservice split would increase risk without solving the current data-model gaps.

## 2. Existing feature map

| Area | Implemented capability |
|---|---|
| Platform | Laravel 12 API, React 19/Vite client, MySQL web mode, bundled Laravel/PHP/SQLite Electron mode |
| Authentication | Hashed passwords, access/refresh tokens, logout invalidation, password change/reset, email verification, throttling |
| Tenancy | `company_id` global scopes plus route-level company-context middleware; company-less superadmin separated from operational APIs |
| RBAC | Role/permission models and route guards for inventory, purchasing, finance, debts, reports, imports/exports, users and CMS |
| Products | Optional/generated SKU, barcode, category, preferred supplier, description, image, quantities, units, thresholds, location code, purchase/selling price and VAT attributes |
| Stock | Transactional movement creation, row locking, negative-stock prevention, before/after quantities, reason, low-stock notifications, exports |
| Warehouses | Company warehouse and editable visual sections/floors, product section assignment, 2D/3D layouts and section distribution |
| Sales | Multi-line daily-sale sheets, day notes, immediate stock deduction, cost snapshots, finalization, PDF and day history |
| Receivables | Customer debt sheet/ledger, dated charges and payments, balances, corrections through reversal, statements and idempotency |
| Procurement | PO lines, editing/change history, status transitions, partial/full receiving, partial payments, payment deadline, statement and deletion controls |
| Invoicing | Kosovo B2B tax profile, buyer snapshots, VAT treatments, drafts/issue/void/credit notes, immutable issued documents, payments/reversals, PDF/XLSX |
| Finance | Expense/supplier-bill drafts, proof attachments and hashes, post/reverse lifecycle, partial payments/reversals, Kosovo VAT books/XLSX, cash-source summary, aging, RB500 preparation and compliance calendar |
| Shipments | Own-shipment records, PO/warehouse reference, history, archive/favorite, risk/alerts, global vessel map, AISStream position consumer and provider registries |
| Analytics | Dashboard stock KPIs, activity, low-stock alerts, weekly/monthly/yearly sales/cost/profit analysis, inventory reports |
| Operations | In-app/email notifications, imports/exports, global search, activity logs, settings, bilingual English/Albanian UI |
| Desktop | Self-contained runtime, no terminal window, per-machine signed licence, Windows protected secrets/device binding, local SQLite, automatic backups, signed-update path and hidden Electron menu |
| Tests | 110 backend tests covering auth, company isolation, inventory, daily sales, debts, PO receiving/payments, invoices, finance, superadmin and shipments |

## 3. Enterprise gap analysis

Status vocabulary follows the requested audit categories.

| Feature | Existing | Status | Problem / recommendation | Priority | Difficulty | Dependencies |
|---|---|---|---|---|---|---|
| Product core | SKU, barcode, category, supplier, image, prices, tax, thresholds | IMPLEMENTED | Keep; remove legacy ambiguous `price` after a safe data migration | P1 | Low | Product API/UI/export |
| Product lifecycle | No active/discontinued workflow | MISSING | Add `status`, discontinue reason/date; never delete transacted products | P1 | Low | Product/history |
| Units/conversions | Free-text unit; decimals restricted to meter aliases | IMPLEMENTED BUT ARCHITECTURALLY WEAK | Add unit definitions and conversions; support kg/litre/box/roll without hardcoded meter logic | P0 | High | Product, sales, PO, invoice, stock |
| Roll identity | Quantity can be meters but individual rolls are not tracked | MISSING | Add optional inventory units/lots for roll-based items only | P2 | High | Receiving/location ledger |
| Product sourcing | One preferred supplier only | PARTIALLY IMPLEMENTED | Add product-supplier references, lead time, MOQ and price history | P1 | Medium | Suppliers/PO |
| Landed attributes | No weight, volume, origin, HS/customs data | MISSING | Add only fields required for import/landed-cost allocation | P1 | Medium | Shipment/landed cost |
| Quantity states | One physical `products.quantity` | IMPLEMENTED BUT ARCHITECTURALLY WEAK | Introduce location balances and derived physical/available/reserved/incoming/transit/damaged quantities | P0 | High | Stock ledger/reservations/receipts |
| Stock movement history | Atomic in/out, before/after and reason | IMPLEMENTED | Keep service and locking | P0 | — | — |
| Stock movement semantics | Only `in/out`; lacks source, warehouse/location and user columns | PARTIALLY IMPLEMENTED | Add immutable movement code, source type/id, warehouse/location, unit and actor | P0 | Medium | All inventory writers |
| Initial product quantity | Product creation/import can set quantity directly | IMPLEMENTED WITH BUGS / RISKS | Create an opening-balance movement atomically; import currently updates quantity without the full ledger service | P0 | Medium | Product/import services |
| Warehouse locations | Visual warehouse/sections and product location code | IMPLEMENTED BUT ARCHITECTURALLY WEAK | Replace string assignment as stock source with warehouse/location balances; current `warehouse_stock` integer model is not integrated and conflicts with decimals | P0 | High | Movement migration |
| Transfers | None | MISSING | Add paired transfer-out/in with in-transit state and one transaction | P1 | High | Location stock |
| Lot/batch/serial | None | MISSING | Optional tracking policy per product; prioritize roll/lot, defer generic serial tracking | P2 | High | Goods receipts |
| Reservations | None | MISSING | Add reservation ledger so availability differs from physical stock | P1 | High | Sales/order workflow |
| Adjustments | Manual in/out with reason | PARTIALLY IMPLEMENTED | Add adjustment-specific codes, configurable large-adjustment approval and valuation impact | P1 | Medium | Permissions/valuation |
| Stock counting | None | MISSING | Count sessions, frozen snapshot, variance, recount, approval and generated adjustment | P1 | High | Location stock |
| Replenishment | Min threshold and alerts | PARTIALLY IMPLEMENTED | Derive suggestions from on-hand + incoming - reserved; avoid forecasting until enough history exists | P1 | Medium | Quantity states/PO |
| Forecasting | Sales history exists | PARTIALLY IMPLEMENTED | Start with moving averages/stockout date; seasonality only after reliable history | P3 | Medium | Clean sales history |
| Slow/ABC inventory | Not provided | MISSING | Add movement-age/value and ABC reports after valuation is reliable | P2 | Medium | Cost ledger |
| Valuation | Dashboard uses stored purchase price; daily sales snapshot costs | IMPLEMENTED BUT ARCHITECTURALLY WEAK | Implement AVCO first; FIFO only if a real operational need appears | P0 | High | Receipts, movements, landed cost |
| Purchase orders | Edit/history, status, payments and partial receipts | IMPLEMENTED | Keep core service and tests | P0 | — | — |
| PO approval | No configurable approval step | MISSING | Add approval policy by company, amount and permission | P2 | Medium | RBAC/history |
| Goods receipts | Receiving events are recorded through PO changes and stock movements | PARTIALLY IMPLEMENTED | Create a first-class receipt header/lines with destination, accepted/damaged quantities and attachments | P0 | High | Location ledger/PO |
| Three-way match | None | MISSING | Match PO vs receipt vs supplier bill with readable discrepancies | P1 | High | Goods receipt/AP |
| Supplier profile | Basic contact/profile and PO links | PARTIALLY IMPLEMENTED | Add payment terms, currency, lead time and legal/tax identity | P1 | Medium | PO/AP |
| Supplier performance | History exists, no reliable score | PARTIALLY IMPLEMENTED | Derive on-time/lead-time/value only from recorded facts | P2 | Medium | Receipt/delivery dates |
| Supplier price history | PO lines retain historical price, no consolidated history | PARTIALLY IMPLEMENTED | Add product-supplier price timeline; do not overwrite past prices | P1 | Medium | PO/product-supplier |
| Shipment records | Tracking, history, alerts, PO/warehouse references | PARTIALLY IMPLEMENTED | Add shipment contents, many-to-many POs, documents and transport/customs cost | P1 | High | PO/receipt/finance |
| Live providers | AISStream consumer exists; registries default to demo and only demo parcel/air providers are registered | IMPLEMENTED WITH BUGS / RISKS | Make live provider readiness explicit, disable fake progress in production and supervise AIS worker/scheduler | P0 | Medium | Deployment/keys/provider contracts |
| Landed cost | Costs may be classified in Finance but are not allocated into received stock | MISSING | Allocate freight/customs/insurance by value/qty/weight/volume; post once to inventory cost | P0 | High | Shipment/receipt/valuation |
| Customer AR | Invoice payments and separate Borxhet ledger | PARTIALLY IMPLEMENTED | Add controlled invoice/debt allocation and reconciliation to avoid parallel balances | P1 | High | Customer/invoice/payment |
| Supplier AP | PO payments plus posted supplier-bill/expense payments | PARTIALLY IMPLEMENTED | Choose supplier bill as canonical AP and link PO deposits rather than summing both | P0 | High | Three-way match |
| Cash/bank accounts | Payment methods only; no account balance ledger | MISSING | Add cash/bank accounts, transfers and immutable journal entries | P0 | High | All payment services |
| Multi-currency | Finance expense original/base values and historical rates exist; PO currency is not fully reconciled | PARTIALLY IMPLEMENTED | Centralize immutable FX rate source/date and base-amount rules | P1 | Medium | PO/AP/payments |
| Bank reconciliation | None | MISSING | CSV import first, matching suggestions and confirmed reconciliation; APIs later | P2 | High | Cash/bank ledger |
| Kosovo tax invoices | B2B/pre-accounting tax invoices, VAT rules and exports | IMPLEMENTED | Keep; never label output as a certified retail fiscal receipt or filed EDI return | P0 | — | Profile/data quality |
| Expenses/VAT preparation | Posted/reversed bills, proof, payments, VAT books, calendar | IMPLEMENTED | Keep; complete UI translation of server data-quality messages | P0 | — | — |
| General ledger | None | MISSING | Required before P&L, balance sheet and statutory-grade cash flow | P1 | Very high | Valuation, AP/AR, cash/bank |
| Profitability | Daily sales use purchase-cost snapshots; invoice gross profit exists | PARTIALLY IMPLEMENTED | Replace supplier-price cost with AVCO/landed COGS, then report by product/category/time | P0 | High | Valuation |
| Financial statements | Finance summaries, not double-entry statements | NOT RELEVANT TO CURRENT MATURITY | Do not present formal P&L/balance sheet until GL is built | P1 | Very high | GL |
| Budgets/cost centers/assets | Expense investment flag only | MISSING | Cost centers/budgets P2; fixed assets/depreciation P2 after GL | P2 | High | GL/approvals |
| Dashboard KPIs | Real inventory/sales/alert data | IMPLEMENTED | Keep accurate KPIs; hide untrustworthy turnover until valuation/time denominator is defined | P1 | Low | Analytics definitions |
| Notifications | In-app/email stock and shipment alerts | PARTIALLY IMPLEMENTED | Add entity links, deduplication/rule preferences and AP/AR due reminders | P1 | Medium | Jobs/entities |
| Permissions | Useful functional permissions | PARTIALLY IMPLEMENTED | Separate cost/margin visibility, payment creation/approval and stock adjustment/transfer rights | P0 | Medium | RBAC/UI/API |
| Audit trail | Generic activity, PO changes, stock/debt/invoice/finance reversal histories | PARTIALLY IMPLEMENTED | Require reasons on sensitive changes; stop hard deletes for posted operational data | P0 | Medium | Domain services |
| Documents | Product image and finance proof only | PARTIALLY IMPLEMENTED | Add secure company-scoped attachment entity for PO, receipt, shipment, supplier and payment | P1 | Medium | Storage/authorization |
| Global search | Search service and header search | PARTIALLY IMPLEMENTED | Extend to PO, movements and supplier bills; keep company context mandatory | P2 | Low | Search indexes |
| Background work | Laravel schedule declares shipment/vessel refresh jobs | PARTIALLY IMPLEMENTED | Deployment must actually run scheduler/queue/AIS consumer; desktop currently starts only HTTP servers | P0 | Medium | Process supervision |
| Offline desktop | Bundled app/data/licence/update/backup | IMPLEMENTED | Keep; add tested restore/export UI and production signing certificate | P0 | Medium | Release operations |
| Mobile scan mode | Barcode lookup exists; no focused workflow | PARTIALLY IMPLEMENTED | Future responsive Receive/Move/Count/Pick mode after location ledger | P3 | Medium | WMS foundations |

## 4. Architecture problems

1. **Multiple inventory write paths.** `StockMovementService` is safe and locked, but product creation/import and some domain services can set/update quantity directly. All inventory changes must pass one inventory transaction boundary.
2. **Balance-as-source architecture.** `products.quantity` is both cached balance and source of truth. ERP growth requires an immutable movement ledger plus per-location balances derived/maintained atomically.
3. **Parallel financial islands.** PO payments, expense/supplier-bill payments, invoice payments, daily sales and customer debt records overlap without one cash/bank or allocation ledger.
4. **Warehouse model split.** Visual sections use `location_code`; legacy `warehouse_stock` exists but is not integrated, is not company-scoped directly and casts quantities as integer.
5. **Tracking execution gap.** Provider adapters are a good pattern, but configuration defaults to demo and real AIS consumption needs a continuously supervised process.
6. **UI refresh duplication (fixed in this change).** Global polling fired three events while Dashboard had a second timer. A single coalesced, silent background event now refreshes visible data without full-page loaders or input loss.

## 5. Data-model problems

- Product units are free text and unit rules are duplicated as meter aliases across validators/services.
- Stock movements lack `movement_code`, `warehouse_id`, source/destination location, source document polymorphic reference, actor and idempotency key.
- Product quantity has no reserved/incoming/damaged/quarantine split.
- Goods receipts, receipt lines and receiving discrepancies are not first-class records.
- No product-supplier pivot for alternative sources, MOQ, lead time and dated supplier prices.
- No inventory layers/cost snapshots for AVCO/FIFO or landed-cost allocation.
- PO, shipment and supplier bill relationships are too loose for three-way matching.
- No finance account/journal model to establish actual cash/bank balances or a general ledger.
- Daily sale and invoice sources must remain separately reported until a validated linking/reconciliation field is consistently written.

## 6. Integration problems

- PO receipt updates stock, but does not produce a separate receipt that shipment, damaged stock, supplier bill and landed cost can share.
- Shipment may reference a PO, but its contents/costs are not the same receiving/cost records used by inventory valuation.
- Finance expenses can classify freight/customs, but allocation to inventory layers is absent, creating double-counting risk if treated as both expense and stock cost.
- Customer invoice payments and the Borxhet ledger are robust independently but need an explicit allocation/reconciliation policy.
- PO deposits and supplier-bill payments need one AP flow and one cash-account effect.
- Background schedules exist in code but require queue/scheduler/AIS processes in deployed web infrastructure; Electron intentionally has no long-running AIS worker.

## 7. Finance accuracy risks

| Risk | Severity | Required control |
|---|---|---|
| Profit based on current purchase price rather than receipt/landed-cost layer | Critical | AVCO receipt layers and immutable COGS snapshot |
| PO payments and supplier-bill payments counted as separate spending | Critical | Canonical AP bill plus linked advances/allocations |
| Freight/customs expensed and later added to inventory | Critical | Landed-cost allocation with mutually exclusive posting treatment |
| Formal cash balance inferred from payment methods | High | Cash/bank account ledger and reconciliation |
| Invoice sales and daily-sale totals combined without reliable linkage | High | Separate display or validated reconciliation link |
| Direct initial/import quantity without a matching movement | High | Opening/import movement in the same transaction |
| Historical foreign-currency values recomputed | High | Immutable rate/date/source/base amount at posting |
| Formal financial statements shown before double-entry ledger | High | Keep Finance Center labelled pre-accounting/EDI preparation |

## 8. Security risks

**Critical:** No currently confirmed cross-company release blocker; company-context middleware protects operational endpoints and tests cover representative isolation. Preserve this guard on every new entity and export.

**High:** Product image and proof binaries are stored in the database. Proof access includes company scoping/integrity checks, but future generalized attachments need size limits, MIME/content validation, malware policy and storage outside the public path. Production desktop anti-copying depends on signed licences/device binding, not secrecy of packaged source.

**Medium:** Staff permissions combine product visibility with management, and cost/profit visibility is not independently restricted. Superadmin/admin grants should be reviewed against least privilege. Real API keys must remain only in environment/secret storage and be rotated if ever committed or shared.

**Low:** Desktop logs are rotated and redact common secret labels; extend structured redaction as integrations grow. Confirm CSP/security headers and release signing on every packaged build.

## 9. Performance risks

- `getAllProducts()` is used in several selectors; large tenants need server-side searchable product pickers.
- Shipment refresh performs one provider request per active shipment; use queued batches, provider rate limits and backoff.
- Dashboard and section aggregates run repeatedly. Cache only defined summaries and invalidate after committed transactions.
- Product image/proof BLOBs enlarge backups and database I/O; move future documents to encrypted/company-scoped object or local file storage with metadata hashes.
- Large reports/exports should stream/chunk; lists should remain paginated.
- The 3D warehouse bundle is about 939 kB and should remain lazy-loaded (it currently is route-lazy); avoid loading it on normal warehouse workflows.

## 10. Recommended roadmap

### Phase A — Foundation / Data integrity

1. Unified inventory transaction service and movement taxonomy/source/idempotency fields.
2. Opening-balance/import movement repair and reconciliation command.
3. Unit master/conversion model and decimal rules.
4. Granular cost/payment/adjustment permissions and immutable posted-record policy.
5. Company-isolation, concurrent-sale and duplicate-receipt regression tests.

Dependencies: none. This phase must precede all others.

### Phase B — Advanced inventory

1. Location balance ledger; physical/available/reserved/incoming/damaged states.
2. Transfers and reservation/release transactions.
3. Goods count sessions and approved adjustments.
4. Optional lot/roll tracking.
5. AVCO valuation, aging, slow-moving and ABC reports.

Dependencies: Phase A.

### Phase C — Procurement and suppliers

1. Product-supplier terms/price history.
2. First-class goods receipts with accepted/damaged quantities.
3. Configurable PO approvals.
4. Supplier performance from real receipt dates.
5. PO/receipt/supplier-bill three-way match.

Dependencies: A, location balances from B.

### Phase D — Shipments and landed cost

1. Shipment contents and multi-PO links.
2. Secure commercial/packing/customs document attachments.
3. Shipment cost components and allocation engine.
4. Post landed cost into inventory valuation exactly once.
5. Production provider health/scheduler/worker supervision.

Dependencies: B valuation, C receipts.

### Phase E — Finance

1. Canonical AP supplier bills and advance allocations.
2. Cash/bank accounts, journals, transfers and reconciliation.
3. Unified AR allocation between invoices and debts.
4. Optional chart of accounts and automatic double-entry postings.
5. Only then add P&L, balance sheet, working capital and statutory-grade cash flow.

Dependencies: B valuation, C/D costs.

### Phase F — Analytics and automation

Replenishment suggestions, profitability, due-date rules, supplier KPIs, dead stock and cash forecast. Use transparent formulas and data-quality warnings.

Dependencies: reliable ledgers from A–E.

### Phase G — Advanced ERP features

Budgets/commitments, cost centers, fixed assets, advanced forecasts, mobile barcode operations and configurable approval rules. Avoid a generic workflow engine until repeated use cases justify it.

## 11. KEEP / IMPROVE / REFACTOR / REPLACE

| Module | Decision | Reason |
|---|---|---|
| Laravel modular monolith | KEEP | Appropriate scale and clean service/provider boundaries |
| React route/component architecture | KEEP + IMPROVE | Working, bilingual and desktop-compatible; extract more shared data-loading hooks |
| Company scopes + company middleware | KEEP | Defence in depth; mandatory for new APIs |
| Token authentication | KEEP + IMPROVE | Solid base; consider session/device management later |
| StockMovementService | KEEP + IMPROVE | Correct transaction/locking core; enrich movement schema and route every write through it |
| `products.quantity` as only inventory truth | REFACTOR | Retain as reconciled cache during migration, not sole enterprise balance model |
| Visual warehouse layout | KEEP + IMPROVE | Useful UI; connect to real location stock later |
| Legacy `warehouse_stock` model | REPLACE incrementally | Unintegrated, integer-only and insufficient for current units |
| Daily Sales | KEEP + IMPROVE | Matches required paper-day workflow; reconcile invoice source and VAT snapshots |
| Customer debt ledger | KEEP | Reversal/idempotency architecture is strong |
| PurchaseOrderService | KEEP + IMPROVE | Strong partial receipt/payment core; add receipts/AP links |
| Invoice domain | KEEP | Compliance, numbering, immutability and download paths are well tested |
| Finance Center | KEEP + IMPROVE | Strong Kosovo pre-accounting base; add canonical accounts rather than duplicating it |
| Tracking provider registries | KEEP + IMPROVE | Correct adapter pattern; register real providers and expose readiness |
| Demo tracking as production default | REPLACE | Safe only for development; production must fail clearly instead of simulating |
| Electron runtime/licensing/update/backup | KEEP + IMPROVE | Professional baseline; complete certificate-backed release and restore testing |

## 12. Safe database migration plan

1. Add nullable fields/tables only; add indexes and company foreign keys before activating features.
2. Backfill movement source/actor/unit from existing records and mark unknown historical origin explicitly.
3. Create an opening-balance reconciliation migration/command whose totals equal every existing product quantity; never rewrite historical movements.
4. Introduce unit definitions and map normalized existing strings; keep original strings until every row is verified.
5. Create location balances and seed the current quantity into the primary warehouse/unassigned location.
6. Dual-write product cached quantity and location balance within one transaction; continuously assert equality.
7. Move readers to the new balance service, then keep `products.quantity` as a compatibility cache until a later release.
8. Add goods receipt, valuation layer and landed-cost tables; backfill only from traceable PO receipts—never fabricate costs.
9. Link PO advances/AP bills and invoice/debt allocations with nullable references first; provide reconciliation reports for legacy unlinked data.
10. Every migration must be tested on MySQL and desktop SQLite, preceded by backup and followed by row-count/balance checks.

## 13. Ordered implementation plan using existing modules

1. **Inventory boundary:** extend `backend/app/Services/StockMovementService.php`, `StockMovement` and a new additive migration; update `ProductService`, `DataImportService`, `DailySaleService`, `PurchaseOrderService` and `InvoiceService` to supply source/idempotency metadata. Update Stock/Products UI history. Validate unit/quantity/source and add concurrent/duplicate/company tests.
2. **Units:** add unit and conversion models/migration, replace duplicated meter checks in stock/daily-sale/PO/invoice requests/services, and add product unit selectors while preserving custom legacy units.
3. **Location balances:** replace the unused semantics of `WarehouseStock` through a new company-scoped decimal balance model; connect `WarehouseLayoutService`, receiving, sales and transfers. Add reconciliation and transfer tests.
4. **Goods receipts:** add receipt header/line models, service, controller/routes and PO UI drawer. The PO receive endpoint can delegate to the new service for backward compatibility.
5. **Valuation/landed cost:** add cost layers and allocation records; connect Finance expense categories, shipment, receipt and daily sale/invoice COGS snapshots. Add cent-precise allocation and no-double-count tests.
6. **AP/cash:** extend Expense/ExpensePayment into canonical supplier bills and introduce finance accounts/journal entries. Link PO payments as advances. Update Finance Center tabs/permissions and test payment reversal/reconciliation.
7. **AR reconciliation:** add explicit allocation records joining payments to invoices/debt transactions while preserving both current screens and histories.
8. **Analytics/jobs:** derive reorder, dead-stock, supplier and profitability metrics from the new ledgers; add scheduled commands in `backend/bootstrap/app.php` and document required web queue/scheduler/AIS processes. Keep desktop jobs local/offline-safe.

## Current change verification

The visible refresh defect was corrected without a database/API change:

- One 15-second visible-tab background event replaces three 10-second global events.
- Notification refresh and page data synchronization retain current data on transient failure.
- Products, Stock, Daily Sales, Dashboard and the global map use silent requests for background updates.
- Request sequencing prevents stale responses from overwriting newer filters.
- Dashboard's duplicate 15-second poll and visible “Auto-refresh active” chip were removed.
- Browser verification confirmed a focused Products search remained focused with its value unchanged through a complete background interval and no loading message appeared.

