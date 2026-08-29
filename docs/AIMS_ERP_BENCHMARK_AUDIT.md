# AIMS ERP Benchmark and Evolution Audit

Audit date: 13 August 2026  
Repository: `C:\Users\anisi\Lab2`  
Scope: web application, Laravel API, React frontend, MySQL and SQLite schemas, Electron desktop runtime, integrations, security controls, tests, deployment assumptions, and end-to-end business workflows.

This is an audit and implementation blueprint. It deliberately does not add major business functionality. The findings about AIMS are based on the repository and its tests. Competitor comparisons are conceptual benchmarks based on the requested capability list and public product material available on the audit date; they are not a certified procurement comparison. Vendor marketing and Kosovo fiscal claims must be independently verified before a commercial or legal decision.

## Classification legend

- **IMPLEMENTED** - present, connected, and supported by meaningful backend behavior.
- **PARTIALLY IMPLEMENTED** - useful capability exists, but the full business chain is incomplete.
- **MISSING** - no usable implementation was found.
- **IMPLEMENTED BUT WEAK** - works for the current scale but is not a durable ERP design.
- **IMPLEMENTED WITH ARCHITECTURAL PROBLEMS** - the feature exists but has conflicting models or boundaries.
- **IMPLEMENTED WITH DATA-INTEGRITY RISKS** - a reachable path can make the recorded business state disagree with reality.
- **NOT CURRENTLY NECESSARY** - valid ERP capability, but not justified for AIMS now.
- **FUTURE FEATURE** - useful after prerequisite ledgers and sufficient data exist.

Priority: **P0** critical foundation, **P1** high business value, **P2** intelligence/optimization, **P3** advanced finance, **P4** commercialization/localization/future.

## SECTION 1 - WHAT AIMS CURRENTLY IS

AIMS is an **Inventory + Procurement + Operational Finance platform evolving into a lightweight ERP**.

It is already more than a basic or advanced inventory application. The code contains company-isolated products and stock, daily sales, customer debt ledgers, purchase orders with partial receipt and payment, supplier-bill/expense workflows, Kosovo-oriented B2B invoices, VAT preparation, shipments, reports, notifications, granular permissions, activity history, and a licensed offline desktop distribution.

It is not yet a complete ERP because the modules do not all post into shared operational and financial ledgers. The most important missing links are:

- physical stock is still represented mainly by one `products.quantity` balance;
- warehouse sections are not true location-level stock balances;
- receiving is an action inside a PO rather than a first-class goods-receipt document;
- a shipment does not carry authoritative PO-line quantities into receipt and valuation;
- supplier PO payments and supplier-bill payments are parallel financial records;
- invoice receivables and the Borxhet ledger are parallel customer balances;
- profit uses stored/snapshotted purchase cost rather than inventory valuation and landed COGS;
- there is no canonical cash/bank ledger, chart of accounts, double-entry engine, or bank reconciliation;
- the desktop app is an independent local installation, not an offline-sync replica of the web database.

The right classification is therefore:

| Classification | Verdict |
|---|---|
| Basic Inventory System | Exceeded |
| Advanced Inventory System | Mostly achieved for single-balance stock; WMS states and locations remain incomplete |
| Inventory + Procurement | Achieved, with receiving/AP integration gaps |
| Inventory + Finance | Partially achieved; strong operational/pre-accounting, not full accounting |
| Lightweight ERP | Current best classification |
| ERP-ready architecture | Partially ready; modular monolith is suitable, but shared ledgers must be established first |

## SECTION 2 - EXISTING MODULES

The following modules were found in actual routes, models, services, UI pages, configuration, desktop runtime, or tests.

| Domain | Actual capability |
|---|---|
| Platform | Laravel 12 API; React 19/Vite client; MySQL web database; bundled PHP/Laravel/SQLite Electron runtime |
| Authentication | Hashed passwords, access/refresh tokens, logout invalidation, email verification, password reset/change, throttling |
| Company tenancy | `company_id` scopes plus route-level company-context enforcement; operational APIs separated from company-less superadmin functions |
| Users and access | Companies, users, roles, permissions, profile/preferences, superadmin user management |
| Product master | Optional/generated SKU, barcode, category, one preferred supplier, description, image, unit, quantity thresholds, location code, purchase/selling prices, VAT fields |
| Inventory | Stock-in/out operations, before/after balances, row locks, negative-stock prevention, low-stock notification and history/export |
| Warehouse presentation | Warehouses, floors/sections, product section assignment, 2D/3D visualization and section distribution |
| Daily Sales | Day sheet, multiple product lines per entry, notes, immediate stock deduction, cost snapshots, day finalization/history and PDF |
| Customer debts | Customer debt sheets, dated charges/payments, balances, correction by reversal, statements and duplicate-request protection |
| Purchase orders | Supplier and lines, edit/change history, lifecycle status, deposits/partial payments, partial/full receipt, balance and due date |
| Supplier expenses/AP | Draft/post/reverse supplier documents, evidence attachments, immutable tax data, partial payments/reversals, aging and duplicate protection |
| Sales invoices | Company tax profile, customer snapshots, VAT treatments, draft/issue/void/credit note, payments/reversals, PDF and XLSX |
| Kosovo finance preparation | Sales/purchase VAT books, RB500 preparation, compliance calendar, expense analysis, cash-source summaries and receivables aging |
| Shipments | Own-shipment records, PO/warehouse reference, tracking/status history, favorites/archive, alerts/risk, vessel map and AIS position consumer |
| Provider abstraction | Vessel/shipment/air registries, AISStream consumer and scheduled tracking commands; parcel/air production adapters remain incomplete |
| Dashboards/reports | Inventory, activity, low-stock, weekly/monthly/yearly sales/cost/margin, finance and operational reports |
| Notifications/email | In-app notifications, email notifications, clear actions and stock/shipment events |
| Search/import/export | Global search, data import, report exports, invoice PDF/XLSX and finance workbooks |
| Audit/CMS/settings | Activity logging, settings, CMS content, Albanian/English translations and company configuration |
| Desktop | Self-contained local service, hidden terminal/menu, signed licence payload, machine binding, protected local secrets, local SQLite, backup rotation, signed-update path |
| Scheduling | Laravel scheduler definitions for vessel/shipment refresh; AIS requires a continuously supervised consumer in web deployments |
| Tests | Most recent complete backend run: 96 passed, 676 assertions, one expected Redis-only skip; frontend production build also passed |

### Current workflow map

#### Product -> stock

Product creation stores the initial quantity directly on the product. Later controlled stock changes use `StockMovementService`, a transaction and a row lock. Daily sales, invoice issuing, and PO receiving call domain logic that protects against negative inventory. Imports and opening quantities do not consistently enter the same immutable movement boundary. Result: normal operations are relatively safe, but an audit cannot prove every historical quantity from movements alone.

#### Purchase order -> supplier -> payment

POs store supplier, product lines, quantities, prices, due date, totals, edit history, partial payments, and partial receipts. Receiving raises stock through the stock service. The missing link is a first-class supplier invoice/AP document: PO payments and Finance supplier-bill payments can represent the same economic event without a canonical allocation.

#### Shipment -> tracking -> inventory

Shipments have tracking metadata, events, alerts, and loose PO/warehouse references. They do not have authoritative contents by PO line/container. Shipment arrival does not itself make inventory available; PO receipt does. That separation is correct in principle, but the missing shipment-content and goods-receipt records prevent reliable incoming/in-transit quantities.

#### Sale -> stock -> profit

Daily Sales records multi-line sales and deducts stock immediately. Invoice issue can also deduct stock. Cost snapshots exist, but they are based on purchase price rather than a valuation layer. If one sale is entered in both Daily Sales and Invoices without a validated link, revenue and stock can be duplicated. Profit is operationally useful, not yet landed-cost accounting profit.

#### Expense -> finance

Supplier documents can be drafted, posted, paid, reversed, supported by proof, and classified for VAT preparation. The lifecycle is strong. It does not post to a central cash/bank ledger or double-entry general ledger, so the Finance Center is a pre-accounting/operational finance module rather than statutory accounting.

#### Invoice/customer debt -> receivables

Invoices support legal-document immutability, payment, reversal, and credit notes. Borxhet supports a flexible customer sheet of charges and payments. Both work independently, but there is no canonical allocation that prevents the same obligation being represented in both.

## SECTION 3 - FEATURE MATRIX

Legend for competitor columns: **Yes** indicates a publicly stated or benchmark-assumed concept; **Partial** indicates a narrower public capability; **N/A focus** means the product is aimed at another operational niche. These entries do not certify a particular edition, Kosovo localization, or implementation partner.

| Feature | AIMS Current State | KuBIT | Klikont | SPINP | Finex | Global ERP Standard | Gap | Recommendation | Priority |
|---|---|---|---|---|---|---|---|---|---|
| Company isolation | IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | Preserve route and query enforcement for every new table/export | Mandatory `company_id`, policy and hostile cross-tenant tests | P0 |
| Product/SKU/barcode | IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | Supplier code, brand, subcategory and lifecycle status absent | Add only commercially useful master-data fields | P1 |
| Multiple units/conversions | IMPLEMENTED BUT WEAK | Yes | Partial | Yes | Partial | Yes | Free-text unit and meter-only decimal rules | Unit master plus purchase/inventory/sales conversion | P0 |
| Roll/length identity | MISSING | Possible lot/WMS | Not evidenced | Possible lot/WMS | N/A focus | Usually lot/serial extension | Cannot identify the remaining length of a particular roll | Optional roll/lot subledger for configured products | P1 |
| Stock movement audit | PARTIALLY IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | Movement lacks source document, actor, location, unit and idempotency identity | Enrich immutable movement ledger and route all writers through it | P0 |
| Physical/available/reserved/incoming states | MISSING | Yes | Partial | Yes | Partial | Yes | One product quantity cannot express commitments or transit | Add derived state balances after movement normalization | P0 |
| Multi-warehouse balances | IMPLEMENTED WITH ARCHITECTURAL PROBLEMS | Yes | Yes | Yes | Partial | Yes | Warehouse visualization is not location stock; legacy balance is integer and unintegrated | New decimal, company-scoped location balance ledger | P0 |
| Warehouse transfers | MISSING | Yes | Yes | Yes | Partial | Yes | No transit or paired location movements | Atomic transfer header/lines with ship/receive stages | P1 |
| Counting/cycle counts | MISSING | Yes | Partial | Yes | Partial | Yes | Manual adjustments are not a controlled count workflow | Snapshot, count, variance, approval and movement posting | P1 |
| Lots/batches/serials | MISSING | Yes | Partial | Yes | Partial | Yes | Needed selectively for rolls/import lots, not every tenant | Product-configured tracking policy | P2 |
| Reorder points | PARTIALLY IMPLEMENTED | Yes | Partial | Yes | Partial | Yes | Threshold alerts ignore incoming/reserved and lead time | Replenishment suggestions after stock states | P1 |
| Procurement/PO | IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | Confirmation/approval and AP match are incomplete | Keep PO core; add controlled approvals and matching | P1 |
| Purchase request/approval | MISSING | Partial | Partial | Yes | Partial | Yes | No configurable pre-PO approval | Add only for tenants that enable it | P2 |
| First-class goods receipt | PARTIALLY IMPLEMENTED | Yes | Partial | Yes | Yes | Yes | PO receipt action lacks receipt document, damaged/accepted destinations and documents | Receipt header/lines as the only path to physical stock | P0 |
| Three-way match | MISSING | Partial | Partial | Yes | Partial | Yes | PO, receipt and supplier bill are disconnected | Match quantities/prices/tax with explicit variances | P1 |
| Supplier price history | PARTIALLY IMPLEMENTED | Yes | Partial | Yes | Partial | Yes | PO line history exists but no consolidated product/supplier timeline | Materialized/read model from traceable PO data | P1 |
| Supplier analytics | PARTIALLY IMPLEMENTED | Yes | Partial | Yes | Partial | Yes | No reliable on-time, shortage, quality or lead-time measures | Derive only after receipt dates/discrepancies exist | P2 |
| Shipment tracking | PARTIALLY IMPLEMENTED | OMS/logistics | Limited | Partial | N/A focus | Often integrated/partner | Strong UI/history, but some providers remain demo and worker supervision is external | Production readiness states, provider health and monitored jobs | P0 |
| Container/PO-line contents | MISSING | Yes | Not evidenced | Partial | N/A focus | Yes | Shipment cannot prove what quantity is in which container | Container and shipment-line allocations | P1 |
| Shipment documents | PARTIALLY IMPLEMENTED | Yes | Documents | Partial | Partial | Yes | No common secure document entity for BL, packing list, customs, insurance | Company-scoped attachment service with document type/version | P1 |
| Landed cost | MISSING | Cost control | Not evidenced | Partial | N/A focus | Yes | Freight/customs do not update inventory cost | Allocate once by quantity/value/weight/volume/manual | P0 |
| Inventory valuation | IMPLEMENTED BUT WEAK | Yes | Yes | Yes | Partial | Yes | Stored purchase price is not AVCO/FIFO or landed cost | Adopt AVCO first; defer FIFO until justified | P0 |
| Accurate COGS/gross margin | PARTIALLY IMPLEMENTED | Yes | Yes | Yes | Partial | Yes | Snapshot cost is not valuation-layer cost | Freeze issued cost from valuation layer | P0 |
| Daily operational sales | IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | May overlap invoices; purpose must remain day ledger | Add source linkage/reconciliation, not a second finance truth | P1 |
| Customer invoices/credit notes | IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | B2B/pre-accounting only, not certified retail fiscalization | Keep legal lifecycle; modular fiscal adapter later | P0/P4 |
| Receivables and aging | PARTIALLY IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | Invoice AR and Borxhet can duplicate obligations | Allocation/reconciliation with one customer balance view | P1 |
| Supplier AP and aging | PARTIALLY IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | PO payments and supplier-bill payments can duplicate cash/liability | Supplier bill canonical; PO deposits become advances | P0 |
| Cash/bank accounts | MISSING | Yes | Yes | Yes | Yes | Yes | Payment method is not a financial account | Immutable account transaction ledger | P1 |
| Bank reconciliation | MISSING | Yes | Yes | Yes | Partial | Yes | No statement import or confirmed matching | CSV import and match workflow after account ledger | P2 |
| Expenses/VAT preparation | IMPLEMENTED | Yes | Yes | Yes | Partial | Yes | Not a complete accounting ledger | Keep strong post/reverse model and data-quality warnings | P0 |
| General Ledger/double entry | MISSING | Yes | Yes | Yes | Partial | Yes | Cannot generate formal trial balance/statements | Build only after operational subledgers stabilize | P3 |
| Budgets/fixed assets/cost centers | MISSING | Yes | Yes | Yes | Partial | Yes | Too early without GL and approval semantics | Phase after accounting foundation; make modules optional | P3 |
| Multi-currency | PARTIALLY IMPLEMENTED | Yes | Yes | Yes | Partial | Yes | Finance preserves historical FX; PO/payment rules are not unified | Shared immutable exchange-rate snapshot service | P1 |
| Dead stock/ABC/turnover | MISSING or WEAK | Yes | Partial | Yes | Partial | Yes | Costs and states are not reliable enough | Build transparent analytics after valuation | P2 |
| Demand forecasting | FUTURE FEATURE | Partial | Partial | Partial | Partial | Yes | History exists but clean availability/stockout data does not | Start with explainable moving averages; no black-box auto-ordering | P2 |
| Granular permissions | PARTIALLY IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | Cost/profit visibility and approve/create separation need refinement | Add permissions at service/API and UI levels | P0 |
| Audit logging/reversal | PARTIALLY IMPLEMENTED | Yes | Yes | Yes | Yes | Yes | Several strong domain histories, but no uniform sensitive-event contract | Shared audit metadata/reason policy; no hard delete of posted data | P0 |
| 2FA | MISSING | Partial | Publicly claimed | Partial | Partial | Yes | Password/token flow is good but privileged accounts lack second factor | TOTP/passkey readiness for web superadmin/admin | P2 |
| Offline desktop | IMPLEMENTED local-only | Partial | Publicly claims sync | Partial | Yes | Varies | Local app works, but no web/offline synchronization | Keep local-only clearly labelled; design sync separately if demanded | P0/P4 |
| Idempotent offline sync | MISSING | Not evidenced | Publicly claims sync | Not evidenced | Yes | Mature products use durable queues | No outbox, versions, conflicts or reconciliation | Do not claim sync; future outbox/inbox with client IDs | P4 |
| Automatic backup/update/licence | IMPLEMENTED | Varies | Cloud managed | Varies | Yes | Commercial standard | Restore drills, production code-signing and licence operations need completion | Operational release checklist and signed installer | P4 |
| Albanian/English/EUR | IMPLEMENTED | Yes | Yes | Local | Local | Localization-dependent | New server messages must remain bilingual | Keep localization keys and EUR default configurable | P4 |
| Kosovo fiscal readiness | PARTIALLY IMPLEMENTED | Vendor-specific | Publicly claimed | Vendor-specific | Publicly claimed | Localization/partner | Tax invoice/VAT preparation is not EFS certification or EDI filing | Separate Kosovo adapter; integrate only with verified official/certified interface | P4 |

### Benchmark interpretation

- KuBIT's public site positions it across ERP, WMS, OMS, warehouse/order/logistics and cost control. It is a useful WMS/process benchmark for AIMS, not a reason to add every retail or production module: [KuBIT](https://kubit-ks.com/).
- Klikont publicly describes inventory, wholesale, accounting, HR, multi-device/cloud/offline operation, roles and Kosovo business workflows. Its public claims are a localization/usability benchmark and require product/certification verification: [Klikont](https://www.klikont.com/) and [Klikont application](https://app.klikont.com/auth/login).
- SPINP publicly describes procurement, goods receipt, multi-location warehouse, valuation, supplier catalogs, approvals, accounting, backups and audit controls. These are relevant process benchmarks: [SPINP ERP Suite](https://spinp.tech/spinp-erp-suite/).
- Finex is hospitality/POS-focused, so AIMS should not copy its tables/restaurant workflow. Its offline encrypted transaction capture, idempotent synchronization, recovery, device control and backup concepts are relevant: [Finex](https://www.meetfinex.com/).
- Microsoft states that Business Central markets without a Microsoft localization can be served by partner localization apps based on the international version. A specific Kosovo partner/app and its current certification must be verified rather than assumed: [Business Central country and localization availability](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/compliance/apptest-countries-and-translations).
- Mature global patterns used in this audit include Odoo units, replenishment, locations and landed-cost allocation ([units](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/configure/uom.html), [replenishment](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/warehouses_storage/replenishment.html), [inventory locations](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/warehouses_storage/inventory_management.html), [landed costs](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/inventory_valuation/landed_costs.html)); NetSuite receiving/valuation and vendor-bill variance controls ([FIFO/receiving](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2194541.html), [vendor-bill variances](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2371184.html)); and Dynamics/Business Central landed cost, warehouse receipt, item-charge and purchasing concepts ([Dynamics Landed Cost](https://learn.microsoft.com/en-us/dynamics365/supply-chain/landed-cost/landed-cost-overview), [warehouse receipts](https://learn.microsoft.com/en-us/dynamics365/business-central/warehouse-how-receive-items), [item charges](https://learn.microsoft.com/en-us/dynamics365/business-central/payables-how-assign-item-charges), [purchase receipts/invoices](https://learn.microsoft.com/en-us/dynamics365/business-central/purchasing-how-record-purchases)).

## SECTION 4 - KEEP / IMPROVE / REFACTOR / REPLACE

| Major module | Decision | Reason |
|---|---|---|
| Laravel modular monolith | KEEP | Appropriate for current team/product scale; transactions can remain local and understandable |
| React/Vite frontend | KEEP + IMPROVE | Functional, bilingual, desktop compatible; continue reusable hooks/components rather than a rewrite |
| Company scope + company-context middleware | KEEP | Strong defense in depth; every new operational endpoint must inherit it |
| Token authentication | KEEP + IMPROVE | Good foundation; add privileged-user 2FA/session management later |
| Roles/permissions | KEEP + IMPROVE | Useful granular base; split cost/profit, approval, adjustment and payment authority |
| `StockMovementService` transaction/lock core | KEEP + IMPROVE | Correct foundation; enrich metadata and make it the exclusive inventory writer |
| `products.quantity` as sole stock truth | REFACTOR | Keep temporarily as reconciled cache; introduce state/location balances without a big-bang removal |
| Current `WarehouseStock` semantics | REPLACE incrementally | Unintegrated, integer-oriented and insufficient for multi-unit/location inventory |
| Visual warehouse/3D layout | KEEP + IMPROVE | Differentiating and useful, but must read real location balances later |
| Daily Sales | KEEP + IMPROVE | Matches the requested daily paper workflow; define invoice link and operational cash role |
| Customer debt ledger | KEEP + IMPROVE | Strong sheet/reversal model; reconcile with invoice AR rather than replace it |
| Purchase order service | KEEP + IMPROVE | Partial payments/receipts and change history are valuable; delegate receipt/AP to first-class documents |
| PO inline receive as final receipt model | REFACTOR | Preserve endpoint compatibility but post through a goods-receipt service |
| Shipment provider registries | KEEP + IMPROVE | Correct adapter direction; add real provider health/readiness and supervised processes |
| Demo provider as production behavior | REPLACE | Development only; production should report unavailable/configuration-required honestly |
| Invoice domain | KEEP | Numbering, company snapshots, issue/credit/void/payment/reversal and exports are strong |
| Finance Center/supplier bills | KEEP + IMPROVE | Strong pre-accounting; establish canonical AP/cash links instead of duplicating it |
| Dashboard/report shell | KEEP + IMPROVE | Good presentation base; show only KPIs backed by reliable ledgers |
| Electron local runtime/licensing/backups | KEEP + IMPROVE | Professional local-only baseline; complete restore testing and release signing |
| Idea of immediate web/desktop sync | DO NOT ADD YET | It requires a separate conflict/idempotency architecture and could corrupt company records if rushed |

## SECTION 5 - CRITICAL ARCHITECTURE PROBLEMS

1. **Inventory has multiple write paths.** The locked movement service is safe, but product opening quantities and imports can alter quantity without an equivalent authoritative movement. New features built on the current ledger could report false historical stock.
2. **A cached balance acts as the source of truth.** `products.quantity` cannot express warehouse, bin, reservation, transit, damage, quarantine, roll or valuation layers.
3. **Warehouse concepts are split.** Visual section assignment, `location_code`, warehouse records and the legacy `warehouse_stock` model are not one inventory model.
4. **Receiving is not a document boundary.** A PO action updates stock, but shipment contents, accepted/damaged amounts, evidence, AP matching and landed costs need one immutable receipt identity.
5. **Financial subledgers overlap.** PO payments versus expense payments, and invoice AR versus Borxhet, can describe the same business event twice.
6. **No canonical money accounts.** Cash/bank is inferred from payment labels instead of immutable account transactions. Accurate cash position and reconciliation are therefore impossible.
7. **COGS is not valuation-backed.** Purchase-price snapshots cannot answer what imported stock really cost after freight/customs or changing receipt prices.
8. **Shipment tracking and inventory are weakly linked.** A shipment is visible, but it does not establish incoming quantities per PO line/container or the receipt/valuation chain.
9. **Background execution is deployment-dependent.** Schedules exist, but the web server must supervise scheduler/queue/AIS consumer processes. The desktop runtime intentionally starts local HTTP services, not an always-on AIS process.
10. **Offline is being used for two different concepts.** The desktop app is a self-contained local product. It is not a disconnected client that later synchronizes with the web tenant. These must remain explicitly separate until a real sync protocol exists.

The appropriate architectural response is still a modular monolith with explicit domain services, immutable subledgers, database transactions and additive migrations. Microservices, Kafka, CQRS and event sourcing would add operational risk without fixing these boundaries.

## SECTION 6 - DATA INTEGRITY RISKS

| Risk | Can make incorrect | Severity | Required control |
|---|---|---|---|
| Opening/import quantity not represented by full movement metadata | Stock and stock history | Critical | Opening/import movement in the same transaction; reconciliation report |
| Direct or future bypass of central inventory service | Stock, availability, valuation | Critical | One inventory command boundary; prohibit model-level quantity writes in business code |
| Single quantity used for all stock states | Available/incoming/reserved/damaged | Critical | State and location ledger with invariant checks |
| No first-class receipt | PO remaining, shipment quantity, stock, AP | Critical | Immutable receipt header/lines and accepted/damaged quantities |
| Purchase price used as cost | Inventory value, COGS, profit | Critical | AVCO valuation layer plus landed-cost allocation |
| Freight/customs both expensed and capitalized | Expense and inventory value | Critical | Allocation posting with a mutually exclusive accounting treatment and source reference |
| PO payment and supplier-bill payment both recorded | Supplier debt and cash out | Critical | Canonical AP bill; link deposits/advances and allocations |
| Invoice and Borxhet both record one sale | Receivables, revenue and potentially stock | High | Explicit source link and allocation/reconciliation guard |
| Daily Sale and invoice both deduct the same sale | Stock and revenue | Critical | One fulfillment identity/idempotency key and a tested source-link policy |
| Historical price/FX overwritten or recomputed | AP, margin, reports | High | Immutable currency/rate/date/base values at posting |
| Shipment without contents | Incoming quantity and ETA-based planning | High | Shipment-line allocations referencing PO lines |
| Hard delete of a posted/received financial or inventory source | Audit history and balances | High | Draft delete only; posted records reverse/void |
| Concurrent final sale/receipt/payment without row locks | Stock or balance | Critical | Lock authoritative balance rows; database transaction and concurrency tests |
| Duplicate retry without idempotency | Stock, payment, receipt, fiscal record | Critical | Required idempotency key and payload-match validation |
| Free-text unit and inconsistent decimal policy | Quantity and conversion | High | Unit master, precision rules and base-unit normalization |
| Legacy warehouse integer balances | Decimal/roll quantities | High | New decimal balance table and verified backfill |

Existing positive controls include row locking/transactions in major stock operations, negative-stock protection, PO/debt/invoice/finance payment idempotency tests, immutable issued invoice behavior, and reversals rather than destructive edits in several financial domains. These patterns should be generalized, not replaced.

## SECTION 7 - SECURITY

### CRITICAL

- **Company isolation on every new entity, export and lookup.** Current route-level company context plus model scopes protect representative operational APIs. A missing middleware/policy on one new report or attachment can expose another tenant. Every new table should carry `company_id`; every direct-ID request and export needs hostile Company A -> Company B tests.
- **Secrets and fiscal/provider credentials.** Keys belong only in environment/OS secret storage. Never bundle provider private keys or fiscal signing material in the React build or plain Electron resources.

### HIGH

- **File/document uploads.** Finance proof access has company checks and integrity data, but a general attachment module must validate file signature as well as extension/MIME, limit size, randomize storage names, deny executable formats, keep files outside public paths, and authorize every download.
- **Desktop licensing is deterrence, not perfect anti-copy protection.** Machine binding, signed licences and Windows protected storage are useful. A determined administrator can inspect a locally installed application. Commercial control must come from signed licences, activation records, contractual terms and signed releases, not secret source packaging.
- **Privileged access.** Superadmin/admin should gain TOTP or passkey-based 2FA, recovery-code controls, session/device review, throttling and security-event notification.
- **Financial/inventory authority.** Viewing quantity should not automatically reveal purchase cost, supplier pricing or profit. Creating a payment/adjustment should be distinct from approving it.

### MEDIUM

- Add a uniform audit contract for price changes, stock adjustments, role changes, payment/reversal and document download.
- Require current-password confirmation for password, 2FA, company and high-impact permission changes.
- Add explicit content-security and production security-header verification for web and Electron.
- Rotate/redact provider credentials from logs and structured exception context.
- Review mass-assignment allowlists and policies whenever fields are added; frontend hiding is never authorization.

### LOW

- Improve session/device naming and revoke-all-other-sessions UX.
- Continue dependency auditing and signed build provenance.
- Document backup encryption, retention and restore ownership for each client installation.

## SECTION 8 - KOSOVO MARKET GAPS

Compared with the public capability positioning of KuBIT, Klikont and SPINP and with partner-localized global ERP deployments, AIMS most visibly lacks:

1. real multi-warehouse/location balances, transfers, counts and warehouse receipt documents;
2. unit conversions and packaging support beyond free-text/meter rules;
3. inventory valuation and landed-cost integration;
4. canonical supplier AP, cash/bank accounts and bank reconciliation;
5. a chart of accounts, double entry and formal accounting statements;
6. configurable purchase/payment/adjustment approval rules;
7. supplier price catalogs/history and measurable supplier performance;
8. stronger document management across PO, shipment, customs, receipt and payment;
9. 2FA and mature privileged-session management;
10. independently verified Kosovo fiscal/EFS integration and accountant workflows beyond preparation/export;
11. web/cloud-to-offline synchronization, if the market truly requires the same tenant to operate in both modes.

AIMS should not try to close every gap at once. The Kosovo market advantage will come from making import, shipment, receiving, landed cost, roll/meter inventory and wholesale cash/AP/AR exceptionally coherent, then adding optional accounting/localization.

## SECTION 9 - AIMS COMPETITIVE ADVANTAGES

### Advantages already present

- A focused, modern bilingual interface rather than a broad legacy ERP screen set.
- A self-contained Windows desktop mode with local SQLite, machine-bound licensing, protected secrets, automatic backups and update infrastructure.
- Purchase orders with partial receipt/payment and history.
- A flexible Albanian-style Borxhet customer sheet with dated charges, dated payments, reversals and statements.
- Kosovo-oriented B2B invoice lifecycle and genuine PDF/XLSX exports.
- Supplier expense/VAT preparation with proof, posting/reversal and payment controls.
- Shipment tracking, alerts, map concepts and a replaceable provider-registry direction.
- Visual warehouse layout/3D presentation that can later be connected to real location balances.
- Strong use of transactions, row locks and idempotency in several critical workflows.

### Realistic differentiation to build

1. **Import Control Tower:** PO readiness -> container -> route/ETA events -> receipt -> discrepancy -> documents -> costs.
2. **Landed Profit Truth:** allocate freight/customs/port/forwarder/transport once, then show real unit cost, COGS and margin.
3. **Roll/Meter Inventory:** individual rolls/lots, measured remaining length, warehouse/rack and trace back to PO/shipment.
4. **Supplier Intelligence:** price history, actual lead time, shortages, delays, quality exceptions, outstanding balance and open shipments.
5. **Wholesale Replenishment:** explainable reorder suggestions from available + incoming - reserved, lead time and demand.
6. **Local-first commercial edition:** reliable single-computer operation without an internet dependency, with verified backup/restore and clear separation from cloud sync.

These capabilities fit AIMS's existing code and intended customers better than copying restaurant POS, payroll, production, CRM marketing, or a universal industry suite.

## SECTION 10 - WHAT NOT TO BUILD

- Restaurant tables, kitchen display, hotel room, clinic, school or construction-specific workflows.
- A generic POS hardware ecosystem before wholesale/import workflows are complete.
- Manufacturing MRP, bills of material and production scheduling unless real target customers demand them.
- Payroll/HR as a custom-built subsystem; integrate/export to a specialist product first.
- A universal no-code workflow designer. Start with explicit configurable approval rules for known operations.
- Microservices, Kafka, CQRS, event sourcing or distributed databases.
- AI forecasting before clean stockout, availability, lead-time and valuation data exists.
- Automated supplier ordering before suggestions and approvals are proven.
- FIFO plus AVCO plus standard-cost selection in the first valuation release. Implement AVCO first.
- Generic serial tracking for every product. Add roll/lot identity only where configured.
- Full General Ledger before inventory/AP/AR/cash subledgers agree.
- Formal P&L, Balance Sheet or statutory Cash Flow computed from incomplete operational summaries.
- Invented Kosovo tax/fiscal rules, fake fiscal QR codes, or UI that claims an EDI return was filed.
- Web/desktop synchronization by periodically copying databases or “uploading everything later.”
- Blockchain, chat/social feeds, gamification, or animations that obscure operational work.

## SECTION 11 - RECOMMENDED PRODUCT POSITION

AIMS should become a **Wholesale and Import Inventory ERP for distributors and inventory-heavy businesses**.

This is a hybrid of Inventory ERP, Wholesale ERP, Import ERP and Distribution ERP, with these product pillars:

1. **Inventory truth:** units, locations, states, movements, valuation and counts.
2. **Procurement truth:** supplier terms, PO, goods receipt, invoice, payment and balance.
3. **Import truth:** shipment/container contents, events, documents and landed cost.
4. **Sales truth:** daily wholesale sales/invoices, stock fulfillment, receivables, COGS and margin.
5. **Operational finance truth:** AP, AR, cash/bank and reliable pre-accounting.
6. **Kosovo-ready commercial delivery:** Albanian/English, EUR, modular tax/fiscal adapters, web and local Windows editions.

The central promise should be: **AIMS tells an importer or wholesaler what they have, where it is, what is coming, what it really cost, what is owed, what sold, what profited, and what should be ordered next.**

## SECTION 12 - ROADMAP

### PHASE 0 - Architecture & Data Integrity

- **Features:** exclusive inventory command boundary; movement taxonomy/source/actor/unit/idempotency; opening/import reconciliation; granular permissions; immutable posted-record policy; company isolation coverage; concurrency/idempotency test harness.
- **Dependencies:** none.
- **Risks:** legacy movements cannot always identify their source; unsafe backfill could fabricate history.
- **Database impact:** additive nullable movement metadata, unique idempotency indexes, reconciliation tables/markers; no destructive quantity rewrite.
- **Backend impact:** centralize all product quantity mutations through `StockMovementService`; require transaction and row locks.
- **Frontend impact:** movement source/reason visibility; cost/profit permission masking; no major redesign.
- **Tests:** Company A/B IDOR matrix, two-seller final-stock race on MySQL, duplicate receipt/payment/sale, balance-vs-ledger reconciliation.

### PHASE 1 - Inventory Foundation

- **Features:** unit master/conversions; decimal precision; location balances; physical/available/reserved/incoming/damaged/quarantine; transfers; count sessions; optional roll/lot tracking.
- **Dependencies:** Phase 0 movement contract.
- **Risks:** double conversion, fractional rounding, dual-write drift, legacy location ambiguity.
- **Database impact:** units/conversions, inventory locations, location balances, reservations, transfers, count sessions, lots/rolls; seed current quantity to a defined primary/unassigned location.
- **Backend impact:** inventory balance service and invariant checks; compatibility cache for `products.quantity`.
- **Frontend impact:** product unit setup, location/state stock view, transfer/count/roll workflows, barcode-ready operations.
- **Tests:** roll 50 m minus 6.5 m = 43.5 m; transfer preserves company total; reservation changes available only; count posts one approved variance.

### PHASE 2 - Procurement & Supplier Management

- **Features:** product-supplier terms, supplier code/MOQ/lead time, price history, configurable approval, first-class goods receipts, discrepancies, supplier invoice/PO/receipt three-way match.
- **Dependencies:** Phase 1 locations/movements.
- **Risks:** existing PO receipt history may be incomplete; supplier invoice duplicates.
- **Database impact:** supplier-product terms, receipts/lines, approval decisions, match/allocation records and duplicate supplier-invoice constraint.
- **Backend impact:** delegate current PO receiving into receipt service; retain existing API compatibility during transition.
- **Frontend impact:** receipt screen/drawer, accepted/damaged destination, matching discrepancies, supplier history.
- **Tests:** ordered 5,000; receipts 3,000 + 1,500; 500 remaining; over-receipt rejected; repeat receipt idempotent; payments remain historical.

### PHASE 3 - Import & Shipment Management

- **Features:** shipment-to-many-PO lines, containers, quantities in transit, milestones/ETA history, provider health/errors, commercial/packing/BL/customs/insurance documents and receipt linkage.
- **Dependencies:** goods receipts and location/state inventory.
- **Risks:** external provider limits/quality, document security, shipment quantities diverging from PO.
- **Database impact:** containers, shipment lines, PO-line allocations, shipment documents/events/provider attempts.
- **Backend impact:** adapter-only provider calls, queued/scheduled refresh, retry/backoff and truthful unavailable states.
- **Frontend impact:** import control tower, container contents, discrepancies, provider health and document access.
- **Tests:** partial shipment/receipt, ETA revision history, unavailable provider behavior, cross-company document denial.

### PHASE 4 - Landed Cost & Inventory Valuation

- **Features:** AVCO valuation, cost layers, landed-cost documents, allocation by quantity/value/weight/volume/manual, COGS snapshots, returns/reversals.
- **Dependencies:** receipts, shipment costs, units and location ledger.
- **Risks:** cents/rounding, duplicate expense capitalization, reversal across closed periods, missing historical costs.
- **Database impact:** valuation layers, cost components, allocation lines, COGS references and posting/reversal status.
- **Backend impact:** deterministic allocation with residual-cent handling; expense/accounting treatment guard; cost posting exactly once.
- **Frontend impact:** allocation preview, reconciliation warnings, unit landed cost and profit drilldown.
- **Tests:** EUR 28,000 goods + 6,100 costs = EUR 34,100 allocated exactly; no double count; sale uses frozen valued COGS.

### PHASE 5 - Operational Finance

- **Features:** canonical supplier AP, PO advances, AR allocations, cash/petty-cash/bank accounts, account transfers, payment posting/reversal, cashbook and aging reconciliation.
- **Dependencies:** supplier bills/receipts and trustworthy sales sources.
- **Risks:** migrating overlapping PO/expense payments and invoice/debt records without duplication.
- **Database impact:** financial accounts, account transactions, AP/AR allocation records and reconciliation status.
- **Backend impact:** every payment posts one account transaction; balances are derived/reconciled, never manually overwritten.
- **Frontend impact:** account register, allocate advance/payment, unified supplier/customer balances, discrepancy queue.
- **Tests:** EUR 25,000 bill less 6,000 and 10,000 payments = 9,000; reversal restores exactly; duplicate retry creates one payment.

### PHASE 6 - Analytics & Replenishment

- **Features:** inventory value, dead stock 30/90/180/365, ABC, product/category profit, supplier performance, available forecast, reorder suggestions and cash forecast.
- **Dependencies:** Phases 1-5 ledgers and sufficient clean history.
- **Risks:** false precision and misleading KPIs when source completeness is low.
- **Database impact:** mostly indexed read models/snapshots, formula version and data-quality state.
- **Backend impact:** explainable formulas, background aggregation, invalidation after committed transactions.
- **Frontend impact:** drillable KPIs with definition, period, source and data-quality warning.
- **Tests:** formula fixtures, no fake metric on insufficient data, export parity and tenant isolation.

### PHASE 7 - Advanced Accounting

- **Features:** chart of accounts, immutable journals, automatic double entry, periods/locks, trial balance, P&L, Balance Sheet, Cash Flow, budgets, cost centers and later fixed assets.
- **Dependencies:** stable inventory valuation, AP, AR and cash/bank subledgers.
- **Risks:** accounting/legal complexity, opening balances, migration, rounding and localization.
- **Database impact:** accounts, journal entries/lines, posting rules, periods, opening balances and dimensions.
- **Backend impact:** balanced-entry engine and reversals; operational users do not hand-code debits/credits.
- **Frontend impact:** accountant workspace separated from normal operations.
- **Tests:** every journal balances, closed-period controls, subledger-to-GL reconciliation and financial-statement equations.

### PHASE 8 - Kosovo Commercialization

- **Features:** verified Kosovo localization adapter, accountant exports, optional certified EFS/fiscal-provider integration, 2FA, signed installers, commercial licensing operations, restore drills and optional future web/offline sync.
- **Dependencies:** verified official requirements/provider contract and mature core ledgers.
- **Risks:** changing law/provider interface, certification, duplicate fiscal receipts, support burden and sync conflicts.
- **Database impact:** localization/fiscal transaction state, provider attempts/idempotency, device registration, sync outbox/inbox only if sync is approved.
- **Backend impact:** replaceable fiscal adapter and failure/retry audit; never invent “filed” or fiscal states.
- **Frontend impact:** readiness/configuration status, retry/recovery UI, bilingual guidance and support diagnostics.
- **Tests:** provider contract sandbox, retry without duplicate receipt, offline failure behavior, licence/device/backup/restore and installer signature verification.

## SECTION 13 - PRIORITY BACKLOG

1. **Prohibit inventory mutations outside one service.** This comes first because every later warehouse, receiving, valuation and profit feature depends on provable quantity history.
2. **Reconcile existing product balances with opening/import movements.** The new boundary cannot be trusted until existing differences are visible and preserved without rewriting history.
3. **Add movement source, actor, warehouse/location, unit and idempotency identity.** These fields let all later documents be traced and retried safely.
4. **Create the unit/conversion model.** Location balances, rolls, receiving and valuation cannot be correct if “roll,” “box,” “kg,” and “meter” are only strings.
5. **Introduce location/state balances while retaining `products.quantity` as a checked cache.** Transfers, reservations, incoming and damaged stock require this before WMS features.
6. **Create first-class goods receipts.** Receiving must become the common source for PO progress, shipment arrival, inventory and valuation.
7. **Add controlled transfers and stock counts.** These close the most common warehouse integrity gaps once locations exist.
8. **Connect shipment/container contents to PO and receipt lines.** Incoming inventory must be based on documented quantities, not shipment status text.
9. **Add supplier-product terms and price history.** It becomes reliable once PO/receipt identities are stable and feeds later purchasing intelligence.
10. **Establish canonical supplier AP and three-way matching.** This prevents duplicate liability/payment truth before more finance reporting is added.
11. **Implement AVCO valuation and COGS.** Real profit cannot be calculated before receipts and AP costs are connected.
12. **Implement landed-cost allocation.** It follows valuation because allocations must post to a defined cost layer exactly once.
13. **Add canonical cash/bank accounts and payment postings.** AP/AR cash effects need one ledger before bank reconciliation or cash forecasting.
14. **Reconcile invoice AR with Borxhet and Daily Sales with invoices.** One customer obligation/sale must not appear twice.
15. **Add dead-stock, ABC, supplier and replenishment analytics.** These must consume trusted transactions, not repair missing foundations with formulas.
16. **Add configurable approvals and sensitive-data permissions.** Approval thresholds become meaningful once transaction types and amounts are canonical.
17. **Add bank statement import/reconciliation.** Matching requires stable account transactions.
18. **Decide whether customers truly need a General Ledger.** Build it only with confirmed accounting ownership and after subledgers reconcile.
19. **Complete commercial hardening: 2FA, signed release operations, restore drills and verified Kosovo adapters.** Commercial claims should follow, not precede, data integrity.
20. **Evaluate cloud/offline synchronization as a separate product project.** It is last because it multiplies every data-integrity and conflict rule above.

## SECTION 14 - DATABASE MIGRATION PLAN

1. Back up both MySQL web data and each desktop SQLite database; verify a restore before schema work.
2. Use additive migrations only at first: nullable columns, new tables, indexes and foreign keys. Do not rename/drop legacy quantity or location columns in the same release.
3. Add company ownership to every new operational table and index common `(company_id, ...)` query paths.
4. Add movement metadata as nullable, then backfill only facts that can be proven. Mark unknown historical source explicitly rather than inventing a PO, user or warehouse.
5. Generate a reconciliation opening movement per product/company for the difference between known movements and current balance. Store audit batch/time and never edit old movements.
6. Add normalized units and map verified aliases. Preserve the original unit value during transition and produce an exceptions report for unknown/custom units.
7. Seed each product's current quantity into a configured primary warehouse/unassigned location. Do not distribute it among bins without evidence.
8. Dual-write location balance and `products.quantity` in one transaction. Run invariant reports before moving readers to the new balance service.
9. Introduce goods receipts, receipt lines and shipment allocations as new records. Backfill only traceable PO receipt history; mark other legacy receipts as unallocated.
10. Introduce valuation layers with an explicit historical-cost quality flag. Do not fabricate landed costs for old stock; allow a controlled opening valuation.
11. Link existing PO payments to supplier bills/advances and invoices to debt items using nullable allocation records. Provide a manual reconciliation queue for ambiguous legacy rows.
12. Add unique/idempotency constraints only after duplicates are reported and resolved through documented corrective records, never silent deletion.
13. Keep posted/reversed financial records immutable. Schema rollback should disable new readers and retain data; it should not delete posted business history.
14. Test every migration and rollback path on both MySQL and SQLite using copies, verify counts/totals/checksums, then deploy web first and repackage desktop from the same schema/code revision.

## SECTION 15 - IMPLEMENTATION PLAN USING ACTUAL PROJECT FILES

Proposed new migrations should be created under `backend/database/migrations` with implementation-time timestamps; no fictitious migration filename is prescribed here.

### 15.1 Inventory transaction boundary and units

- **Backend models/services:** `backend/app/Models/Product.php`, `backend/app/Models/StockMovement.php`, `backend/app/Models/WarehouseStock.php`, `backend/app/Services/StockMovementService.php`, `backend/app/Services/ProductService.php`, `backend/app/Services/DataImportService.php`, `backend/app/Services/DailySaleService.php`, `backend/app/Services/PurchaseOrderService.php`, `backend/app/Services/InvoiceService.php`.
- **Controllers/API:** `backend/app/Http/Controllers/Api/StockMovementController.php`, `backend/app/Http/Controllers/Api/ProductController.php`, `backend/routes/api.php`.
- **Frontend:** `frontend/src/pages/Products.jsx`, `frontend/src/pages/Stock.jsx`, existing product/stock API modules and bilingual locale files.
- **Database:** additive movement metadata, unit definitions/conversions and reconciliation batch records.
- **Permissions:** extend existing inventory permissions with cost visibility, adjustment and future transfer/count rights through `backend/database/seeders/RolePermissionSeeder.php`.
- **Tests:** extend `backend/tests/Feature/InventoryApiTest.php` and daily-sale/invoice tests; add MySQL concurrency and import/opening reconciliation coverage.
- **Affected features:** product creation/import, Daily Sales, PO receiving, invoice stock deduction, stock reports and dashboard quantity.

### 15.2 Location balances, transfers, counts and rolls

- **Backend:** extend current warehouse models/services rather than using `location_code` as stock truth; replace `WarehouseStock` usage incrementally with a company-scoped decimal balance model.
- **Controllers/API:** current warehouse and stock controllers plus new endpoints registered only in `backend/routes/api.php` behind company context and granular permissions.
- **Frontend:** `frontend/src/pages/WarehouseLayout.jsx`, stock/product detail screens and existing warehouse API modules.
- **Database:** additive locations, location balances, transfer headers/lines, count sessions/lines, reservations and optional roll/lot records.
- **Tests:** transfer invariant, reservation availability, concurrent location mutation, count approval, 50 m roll minus 6.5 m, cross-company denial.
- **Affected features:** warehouse 2D/3D counts, stock availability, sales, PO receipt and low-stock alerts.

### 15.3 Goods receipts and procurement integration

- **Backend services/models:** preserve `backend/app/Services/PurchaseOrderService.php` and existing purchase-order models; add receipt domain records and make the current receive path delegate to the receipt service.
- **Controllers/API:** `backend/app/Http/Controllers/Api/PurchaseOrderController.php`, supplier/finance controllers as applicable, `backend/routes/api.php`.
- **Frontend:** `frontend/src/pages/PurchaseOrders.jsx` and existing purchase-order API module.
- **Database:** receipts/lines, accepted/damaged quantities, destination, source shipment and approval history.
- **Permissions:** purchase receive and approve must be separate from purchase edit.
- **Tests:** extend `backend/tests/Feature/PurchaseOrderTest.php` for partial/over/damaged/concurrent/idempotent receipt and unchanged payment history.
- **Affected features:** PO status, stock, shipments, supplier bills, notifications and reports.

### 15.4 Shipment/container/document chain

- **Backend:** current shipment models/controllers/services, provider registries/configuration, scheduled commands in `backend/bootstrap/app.php`, and company-scoped attachment authorization.
- **Controllers/API:** `backend/app/Http/Controllers/Api/ShipmentController.php`, related tracking controllers and `backend/routes/api.php`.
- **Frontend:** `frontend/src/pages/shipment/MyShipments.jsx`, shipment map/alert screens and existing shipment API modules.
- **Database:** containers, shipment lines, PO-line allocations, document metadata/hash/version, provider attempts/errors.
- **Tests:** extend `backend/tests/Feature/SettingsAndShipmentTest.php` for partial allocation, history, provider unavailable/backoff, documents and tenant isolation.
- **Affected features:** shipment tracking, PO progress, incoming stock, receipts, notifications and documents.

### 15.5 Valuation, landed cost and profit

- **Backend:** `backend/app/Services/StockMovementService.php`, `backend/app/Services/DailySaleService.php`, `backend/app/Services/InvoiceService.php`, finance expense services/controllers and report services.
- **Frontend:** `frontend/src/pages/FinanceCenter.jsx`, `frontend/src/pages/Dashboard.jsx`, `frontend/src/pages/Reports.jsx`, product/receipt/shipment detail views.
- **Database:** valuation layers, cost components, allocation lines, cost posting/reversal and immutable COGS references.
- **Permissions:** inventory cost, landed cost and profit visibility separated.
- **Tests:** extend `backend/tests/Feature/FinanceCenterTest.php`, `DailySaleTest.php`, `InvoiceComplianceTest.php` and inventory tests for exact allocation, return/reversal and no double count.
- **Affected features:** product cost, inventory value, gross margin, expenses, VAT presentation and finance exports.

### 15.6 AP, AR, cash and bank

- **Backend:** existing expense/payment models and services, `PurchaseOrderService.php`, invoice/payment services and customer-debt services.
- **Controllers/API:** current Finance, Expense, Invoice, Payment and Customer Debt controllers; register allocations/account endpoints in `backend/routes/api.php`.
- **Frontend:** `frontend/src/pages/FinanceCenter.jsx`, `frontend/src/pages/Invoices.jsx`, `frontend/src/pages/CustomerDebts.jsx`, `frontend/src/pages/PurchaseOrders.jsx`.
- **Database:** finance accounts, account transactions, AP advances/allocations, AR allocations and reconciliation metadata.
- **Permissions:** finance view, record payment, reverse payment, approve payment, reconcile and view profit/cost.
- **Tests:** extend `FinanceCenterTest.php`, `InvoiceComplianceTest.php`, `CustomerDebtLedgerTest.php` and `PurchaseOrderTest.php` for allocation totals, reversal, idempotency, concurrency and tenant isolation.
- **Affected features:** supplier balance, customer balance, payment history, cash-flow summaries and dashboard.

### 15.7 Analytics, approvals and notifications

- **Backend:** current report/dashboard services, notification services/jobs and scheduler declarations in `backend/bootstrap/app.php`.
- **Frontend:** `frontend/src/pages/Dashboard.jsx`, `frontend/src/pages/Reports.jsx`, notification dropdown/page, Products, Purchase Orders and Finance Center.
- **Database:** configurable approval rules/decisions, optional aggregate snapshots, formula version and data-quality status.
- **Permissions:** view cost/profit, approve PO/payment/adjustment and manage rules.
- **Tests:** transparent formula fixtures, insufficient-data behavior, approval race, notification deduplication/entity link and export parity.
- **Affected features:** low-stock alerts, supplier KPIs, dead stock, ABC, reorder suggestions and cash forecast.

### 15.8 Desktop and Kosovo commercialization

- **Backend/config:** current tax/invoice/finance adapters, environment configuration, desktop runtime scripts and update/licence code under the existing `desktop` tree.
- **Frontend:** Login/Register security UX, Settings, Finance/Invoices readiness labels and desktop-only administration screens.
- **Database:** only verified localization/fiscal/device/sync state; keep jurisdiction data outside the core where practical.
- **Permissions:** company localization settings, fiscal operations and licence administration.
- **Tests:** existing desktop smoke tests, backup/restore drill, licence/machine binding, signed update/installer, provider sandbox and duplicate fiscal-request prevention.
- **Affected features:** web/desktop release, invoices, finance exports, authentication, licensing and support.

### Final implementation gate

Major implementation should begin only after Phase 0 scope is approved. Each later phase must ship as a vertical, tested business chain rather than as isolated pages. A phase is complete only when its database invariants, backend transaction, authorization, bilingual UI, export/report behavior, web mode and desktop mode are verified together.

## IMPLEMENTATION PROGRESS

### Phase 0 inventory ledger foundation - completed 13 August 2026

- Product opening quantities now create explicit opening-balance movements.
- Product quantity corrections require a reason and create an adjustment movement.
- Product and stock imports use the locked inventory transaction boundary and retain import provenance.
- Manual stock adjustments require a reason and UUID idempotency key.
- Purchase-order receipts are protected from duplicate retries and carry PO provenance.
- Daily-sale and invoice movements carry typed source metadata; invoice application/reversal is idempotent per product.
- Movement records now retain code, unit snapshot, source, actor, occurrence time, metadata and idempotency identity.
- Existing web inventory was backed up, migrated and reconciled with ledger-only opening/current-balance entries; no product quantity was changed. The final audit reports 35/35 product balances consistent.
- Inventory mutation routes now enforce the existing product/category/supplier/stock permissions server-side.
- Web/MySQL migration, clean SQLite migration, desktop resource synchronization, frontend build and the complete backend suite were verified.
