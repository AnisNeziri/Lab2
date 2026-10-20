# DC5 — Real-Time Inventory Automation & Operational Control

Review date: 20 October 2026. Current HEAD: `dd3e4e213fd32be824d56357be61744d079c95d3`.
DC5 implementation is in the working tree, ready for the user's normal commit.

## One working folder and branch

Use **`C:\Users\anisi\Lab2`**, branch **`main`**.

The original checkout was at `07c66520`; the completed phases through PX1 were in the Codex worktree at `ac2ce041`. A conflict-free local merge preserved both histories, including the original checkout's generated certification files. Both checkouts were clean before consolidation. No environment files or business database were changed. Old branch references and the old checkout remain recoverable; DC5 was developed only in the normal folder on `main`. No new feature branch, remote-main push, PR merge or deployment was performed.

```powershell
Set-Location C:\Users\anisi\Lab2
git branch --show-current
git status
git diff --stat
```

## Completion evidence

| Area | Result |
|---|---|
| 1. Authoritative states | Extended `InventorySnapshotService`; `WarehouseStock` and `StockMovementService` remain the authorities. Physical, available, exclusive reserved, allocated/picking, quality hold, damaged and blocked are explained. Picked is an allocated subset; internal transfer stock remains owned outside warehouse physical balances. |
| 2. Reservations / backorders | Existing partial reservations retained. Explicit **Reserve waiting orders** processes up to 50 orders by priority, order date and ID, using existing credit/stock validation. Durable company-scoped run fingerprints prevent a retry from allocating newly arrived supply. No automatic expiry of customer promises was invented. |
| 3. PO / shipment / receipt | Optional posted-receipt cargo links and frozen arrived base quantity support partial receipts and multiple cargo lines/shipments. Outstanding PO supply is counted once; verified transit is a subset. Cargo over-receipt and changes/deletion of received cargo quantities/source links are blocked. Unattributed historical receipts and uncertain conversions produce unknown transit rather than guessed allocations. |
| 4. Transfer / count | Existing dispatch/receive, state, trace and stale-count/recount/approval workflows retained. Company-before-product lock ordering aligns transfers/count approval with reservations/receipts. Inventory Control can prepare a product/warehouse cycle count in the existing count workspace; the user still reviews scope and takes the snapshot. |
| 5. Realtime | Existing company stock events and 15-second application poll are reused. Position reads use a database transaction for coherent source reads. Relevant SalesOrder events now invalidate inventory planning. Live/refreshing/disconnected status and last update are visible; failed reads retain last valid quantities. No second realtime framework. |
| 6. Inventory Control Center | Added as a tab in Warehouse Operations: bounded, searchable, paginated product/warehouse positions, exceptions, incoming evidence and permission-gated investigation/replenishment/order/count links. No mixed-unit company quantity sum or second forecast engine. |
| 7. Order availability | Ordered, reserved, dispatched and backordered quantities, available stock, incoming and supported estimated availability appear in Order Hub. Existing ATP subtracts other confirmed unreserved demand; warehouse scope is respected. Reservation/picking/dispatch remain source-owned actions. |
| 8. Shipment delay impact | Current cargo ETA/status is read with incoming evidence and waiting-order links. A later cargo ETA cannot leave an earlier PO date as the expected availability date. Unknown dates remain unknown; dates are estimates requiring receipt/inspection, not delivery promises. Existing shipment intelligence remains the predictive/risk workspace. |
| 9. Barcode / warehouse | Reused scanner/mobile receiving, transfer and picking verification. Existing product/location/lot checks, FEFO and source/bin allocations remain enforced. No hardware driver or scanner-specific claim. |
| 10. Ask AIMS | Existing read-only product capability now returns exclusive position, warehouse locations and bounded incoming evidence. Added English/Albanian product-quantity phrasing with unique tenant warehouse resolution, e.g. `sa Milano 04 kemi ne Ferizaj`. Read-only fact tools remain separate from stockout forecasts; no purchasing/reservation/movement tool was added to AI. |
| 11. Accounting | Existing quantity/valuation/accounting paths retained. Representative opening, receipt, inspection and dispatch reconcile inventory value with the posted Inventory GL; inventory integrity and journal presence are asserted. Landed cost continues through the existing value-only path. |
| 12. Concurrency / integrity | Existing movement/action keys, product/bin locks and negative-stock prevention retained. MariaDB processes verify competing reservations and identical/different scanner receipt keys. System Integrity additionally flags negative states and orphan/cross-company warehouse/product/location balances. |
| 13. Tests actually run | See the validation manifest and logs below. No PM3 regeneration or large performance fixture. |
| 14. Remaining device / provider gaps | See limits below; no external provider, physical scanner or production rollout certification claimed. |

## Required status answers

| Control | Answer |
|---|---|
| Physical/Available/Reserved separated correctly | **YES** |
| Allocated/Picking represented correctly | **YES** — exclusive allocation bucket, picked subset |
| Incoming/In-Transit represented without double counting | **YES** — unknown attribution is explicitly disclosed |
| Quality Hold excluded from available stock | **YES** |
| Partial reservation/backorder works | **YES** |
| Partial shipment/receipt works | **YES** — exact loaded cargo/base-unit evidence required |
| Reservation concurrency protected | **YES** — real MariaDB processes |
| Transfer does not duplicate stock | **YES** — existing WMS regression coverage |
| Retries idempotent | **YES** — order dispatch, reservation run and concurrent receipt checks |
| Inventory ↔ GL reconciles | **YES** — representative test company |
| Shipment ETA/risk connected to incoming stock | **YES** — current evidence and existing risk tools, estimates labelled |
| Ask AIMS distinguishes factual low stock from predictive stockout | **YES** — distinct existing fact/forecast tools and planning routes |
| Two-company isolation verified | **YES** — new read/filter tests and existing order/WMS isolation regressions |
| Realtime inventory usable for controlled operational pilot | **YES** — within the tested scope and limits below |

## Validation

These slices overlap; their counts must not be added as distinct tests.

- Final SQLite DC5/performance slice: **8 tests / 200 assertions PASS**, including the four DC5 journeys (`output/dc5-backend.log`).
- New DC5 MariaDB journeys: **4 tests / 113 assertions PASS**, including System Integrity and standalone cargo uncertainty (`output/dc5-mariadb.log`).
- Existing inventory/WMS/outbound/quality slice: **32 tests / 454 assertions PASS** (`output/dc5-regression.log`).
- Final count/transfer/mobile/DC5 slice: **25 tests / 358 assertions PASS** (`output/dc5-final-inventory.log`).
- Order Hub/availability/DC5 slice: **25 tests / 324 assertions PASS** (`output/dc5-final-sqlite.log`).
- Performance/lifecycle/DC5 slice: **12 tests / 230 assertions PASS** (`output/dc5-final-controls.log`).
- Shipment/search/availability slice: **21 tests / 177 assertions PASS** (`output/dc5-final-source.log`).
- MariaDB separate processes: 15 + 5 reserved from 20; unchanged physical and idempotent retry (`output/dc5-concurrency.log`). Two scanner races: identical key gives one receipt; distinct competing keys reject over-receipt; 6 received from 8 leaves 2 in transit (`output/dc5-receipt-concurrency.log`).
- Focused frontend tests: **9 PASS** (`output/dc5-frontend.log`).
- Representative Chromium warehouse → order → linked partial receipt → live refresh → backorder reservation → disconnected preservation journey: **1 PASS** (`output/dc5-browser.log`, screenshot `output/dc5-inventory-browser.png`).
- Production build: **PASS** (`output/dc5-build.log`); existing large PDF/3D chunk warnings remain.
- Populated PX1 upgrade clone: **203 tables / 2,860 rows preserved**, comparing original-column data hashes, new receipt link/run table present, **no migrate:fresh** (`output/dc5-upgrade.json`).
- Focused Control Center fixture: **16 queries / 2,255 bytes**. Existing bounded-read regression guards pass. This is a small-fixture measurement, not a large-warehouse throughput certification.

## Limits and operation

- No business database was migrated or seeded. Deploy the additive migration through the normal backed-up upgrade procedure before serving DC5 against an existing installation. Test databases are separately named SQLite files and MariaDB `aims_dc5_20261020_test` on isolated port 3315.
- Historical PO receipts are not backfilled to shipments. Unlinked cargo, unverified loaded quantities and variable units without base measurements cannot support precise transit totals or firm promises. Supplier shortage/claim, return, quality and landed-cost work remains in its existing owning module.
- Cycle counts use explicit selected scope and existing snapshot/recount/approval controls. Automatic ABC classification, recurring count schedules and reservation expiry policy were not introduced. Existing Automation tasks can request review/count work; no unattended stock movements or purchase orders were enabled.
- Physical barcode devices/cameras, external tracking availability, Reverb under network interruption and warehouse-scale load still require site testing. The browser journey verifies the existing polling/event integration and failure disclosure using synthetic data.
- Stock ownership retains current company-owned semantics. Full consignment/3PL ownership, manufacturing and restaurant consumption remain future integrations. No DC6 work was started.

Implementation entry points: `backend/app/Services/InventorySnapshotService.php`, `OutboundService.php`, `PurchaseOrderService.php`, `ShipmentLogisticsService.php`; `frontend/src/components/InventoryPosition.jsx`; `frontend/playwright.dc5.config.js`.
