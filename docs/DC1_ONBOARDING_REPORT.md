# DC1 — Controlled Company Onboarding & Opening Reconciliation

This report covers synthetic/test onboarding only. No real-company source data was supplied, imported or cut over. No merge, deployment or next development phase was performed. Draft review: [PR #5](https://github.com/AnisNeziri/Lab2/pull/5), stacked on DR0 PR #4.

## 1. Current HEAD

Development started from the fetched DR0 head `a828482e798228153a7c32dd92e04d9f3d7dc313`, rather than assuming the older application SHA from the brief. Verified application source: `1a94190275b480b59d8c30146abd9283c7d1ee7f`. This report and compact evidence follow without changing application behavior; use `git rev-parse HEAD` for the documentation head. Branch: `codex/dc1-controlled-onboarding`.

All implementation and disposable tests ran in the reused isolated worktree. The original `C:\Users\anisi\Lab2` checkout, its existing branch, running application and business database were preserved. Final preservation evidence is in the evidence JSON.

## 2. P1 bank-match concurrency

A real MariaDB reproduction used two PHP processes, two statement rows and the same posted financial transaction, with a barrier after the initial ownership read. Before the fix, both acquired authoritative ownership. The fix locks the shared candidate transaction before the statement row and revalidates current account, amount, status and active ownership with a locking read. The final race produced one match and one rejection, exactly one active owner. Shared suggestions remain allowed; same-row replay, unmatch/rematch, reversal, historical matches and audit were checked. No global unique candidate constraint was added.

## 3. Approval / Purchase Request atomicity

ApprovalService now locks/revalidates the parent Purchase Request and commits its lifecycle update with the decision. Controller-side separate parent mutation was removed. A failure injected after the actual parent UPDATE previously left a persisted decision; it now rolls back parent and decision together. Retry, same-decision replay and conflicting replay have feature coverage. Existing approval design is retained.

## 4. Onboarding architecture

One company-scoped staging framework covers sessions, source batches, rows, append-only decision events and private encrypted backup artifacts. Separate validation, reconciliation, fingerprint, posting, guard and backup services delegate to existing authorities. The dedicated Administration screen provides company/cutoff, sources/mapping, reconciliation, review/approval, bounded posting and verify/lock stages.

Only one non-cancelled opening is allowed per company. Existing zero-balance masters can be reviewed and reused; existing operational history, balances or ledger/stock activity block opening. Sessions resume after reload, interrupted parsing and bounded posting. The [operator guide](dc1/ONBOARDING_GUIDE.md) describes normal use and recovery.

## 5. Supported source formats

UTF-8 CSV with explicit comma/semicolon/tab separator, decimal convention and date format; flat JSON records, including a list under `data`. XLSX is exported as UTF-8 CSV first. Current-schema templates with synthetic examples cover all 11 domains. Files are limited to 10 MB / 10,000 rows. The browser uploads files without parsing entire spreadsheets into a large client-side table.

Original source bytes, uploader, checksum, format/mapping/options, attempts and counts remain private. Identical checksums are detected; intentional reprocessing deactivates the prior attempt. The browser clears the successful upload selection so the same source can be selected again. Parsing uses buffered inserts inside an atomic batch transaction; a malformed row after 100 staged rows rolls back the whole file. Interrupted batches can reparse stored bytes without duplication. Exclusion requires a reason and retains source/audit history.

## 6. Product import and matching

Candidates use exact SKU, primary/alternative barcode, canonical name and bounded fuzzy evidence. Unique identifier collisions block; fuzzy/name candidates never merge automatically. The workbench offers explicit existing match, separate creation, staged edit or reasoned exclusion. It distinguishes staged duplicates from existing business IDs. A normalized staged `Milano 04` / `Milano-04` pair requires review even without an existing product.

Posting uses ProductService with zero initial stock and existing category/unit/barcode rules. Source references and posted business pointers retain lineage. Original identifier text is preserved; floating JSON identifiers must be corrected to text rather than silently losing digits.

## 7. Customer / supplier matching

Matching uses available names/business names, email, normalized phone and customer tax identifiers. Similar names are candidates, never automatic merges. Existing matches must be in the current company and active. Staged duplicate identity evidence is also reviewed. Financial positions reference an explicitly staged reference or one unambiguous existing identity; no fuzzy financial allocation occurs.

## 8. Unit normalization

Known aliases such as meter/metre/metra become `m`, and pieces/copë become `pcs`. Quantity is three decimals; cost/conversion factors use six; money and master prices use two. Alternative unit definitions may use explicit identifiers and fixed factors. Existing product-unit definitions are reused and frozen into the reviewed data. JSON numeric values retain round-trip digits before fixed-decimal validation; a reproduced six-decimal cost truncation is fixed, and excess decimal digits/boolean amounts are rejected. The contract fingerprint was advanced to v2 so older approvals cannot reuse the old normalization rules. Staged editing retains original source decimal/date conventions. No roll length or packaging conversion is guessed. Unknown/ambiguous conversions block; costs are always per base inventory unit.

## 9. Warehouse mapping

Warehouses and zone/rack/shelf/bin hierarchy use existing warehouse authorities. Parent type, warehouse membership and selected existing-location consistency are validated. Opening inventory requires a valid warehouse; an optional location must belong to it. Warehouse-level stock is allowed without invented bin history. Location parents can be staged after their children because validation resolves the hierarchy separately and posting orders parent types first.

## 10. Opening stock

StockMovementService receives an internal typed onboarding context, deterministic row idempotency key, source session/row/checksum, warehouse/location, base quantity, reviewed cost and cutoff timestamp. No direct product quantity assignment is used. Multiple reviewed warehouse positions for one product are supported through the existing opening boundary, without relaxing ordinary opening-once behavior. Existing valuation, stock states and Inventory Integrity remain authoritative.

The cutoff is the end of the selected date in company time, stored as UTC movement time with the business opening date retained for accounting. Posting before that cutoff is refused. Later ordinary transactions cannot reuse dates inside the locked opening.

## 11. Opening AR / customer advances

CustomerDebtService records pure opening receivables and pure opening advances separately. No fake historical invoice or cash payment is created, and no silent netting occurs. The full vertical test includes a single customer with both €3,700 debt and €500 advance. A separate required three-customer test verifies €3,700 + €1,000 = €4,700 AR and a different customer's €500 advance. Unknown due dates remain warnings; payment terms are not invented. Normal dated corrections/reversals preserve the pure opening-position semantics.

## 12. Opening AP / supplier advances

ExpenseService records a clearly labelled opening payable statement through the existing AP authority, with source reference and cutoff, without fabricating PO, supplier invoice tax history or historical cash flow. PurchaseOrderService records a separate supplier advance using the existing payment/allocation authority, nullable PO and explicit supplier/session lineage. €25,000 AP and €6,000 supplier advance remain distinct. Allocation requires the same company/supplier/currency; it is never automatic.

Opening AP is excluded from expense/VAT purchase books, and opening supplier advances are excluded from historical cash-payment totals. Existing normal documents/payment flows remain covered by regression tests.

## 13. Cash / bank opening

FinancialAccountService creates a reviewed account or opens an unused zero-balance account and posts its opening through existing accounting. Account type and base currency must match; bank/cash totals reconcile separately. An onboarding-linked opening amount/date cannot later be rewritten in account settings, even before any ordinary transaction exists. Display details may change. Dated money-account adjustments provide the correction path.

## 14. Accounting opening

Existing AccountingService / OperationalAccountingService provide double-entry journals: inventory/AR/supplier advances/cash/bank against configured opening equity; customer advances and AP against that same explicit equity boundary. Source equity is independently declared and checked against net opening assets. Unknown cost, unsupported money precision, excessive calculated value and source differences block approval. No independent balance tables or forced balancing entries were introduced.

Per-row journal rounding must equal the resulting per-product carried inventory value. Conflicts stop before posting and require source/cost review or row consolidation. Imported opening positions suppress ordinary business-event/webhook/automation learning emissions; source audit and ledger evidence remain.

## 15. Reconciliation workbench

Inventory value, AR, customer advances, AP, supplier advances, cash, bank and equity show independent source, staged, actual AIMS and exact differences. Quantities compare separately by base unit. Links open the affected paged source domain and posted business records. Verification also invokes accounting reconciliation and product Inventory Integrity. A locked opening retains its verified historical snapshot when later normal operations/corrections change current balances.

## 16. Dry-run behavior

Upload, mapping, normalization, duplicate review, staged edits and dry validation have zero operational stock/debt/payment/journal/PO/receipt/sale effects. Tests snapshot these tables before and after staging. Dry validation does not initialize accounting accounts. Unknown source totals remain blank and block; confirmed zero must be entered explicitly. Missing mapping, invalid references, unresolved duplicates, unknown units/costs, currency/rounding differences and failed source attempts remain visible blockers.

## 17. Approval / fingerprint behavior

Review requires independently confirmed source totals, acknowledged warnings/exclusions and a fresh authenticated pre-cutover backup tied to the revision and operational baseline. Approval is a separate explicit action. View/manage/review/approve/post/lock privileges remain distinct. An optional different-reviewer policy rejects self-approval; the default supports a small company owner while recording both decisions.

The source fingerprint binds the contract, company/session/settings, source hashes, mappings/options, staged values/resolutions, reviewed identity snapshots and backup reference. Every source/settings/mapping/row change invalidates review/approval. Optimistic revisions reject stale changes. Before the first posting, source controls and the operational baseline are rechecked under company/session locks.

## 18. Posting / idempotency

API requests post at most 50 rows; the UI uses 25. Company/session/row locks serialize authoritative work. Each row's existing-service effects, source pointer and audit commit atomically. A failure rolls back the current row, preserves prior rows and marks the exact restart boundary. Retrying the same approved dataset starts with the first unposted row. Cancellation/source edits close once posting begins; ordinary operations remain fenced until verification/lock.

Failure injection at the stock insert verifies no orphan movement/journal; retry and repeated completed requests do not duplicate them. A two-process MariaDB posting race commits three source rows exactly once: one product, one stock movement and one opening journal.

## 19. Lock / correction behavior

Lock requires complete posting, matching approved fingerprint, zero source differences, inventory/accounting integrity, post-cutover backup and a successful independent restore tied to that exact backup checksum. It sets the company's cutoff/accounting-start markers without posting another opening journal. Source replacement, silent row edits, naive reset/delete and repost are refused.

Existing correction/reversal engines remain in charge. Contextual PO/receipt links expose related supplier payment, expense, landed-cost and supplier-return review where permissions/status allow. Receipt correction uses the established return workflow; no receipt-delete engine was invented. Unallocated supplier opening advances expose the normal payment reversal in source rows, with a reason modal and separate current-position status. Historical opening proof stays frozen.

## 20. Backup / restored-copy verification

Encrypted pre/post company portable backups authenticate their contents, retain private checksums and use passphrases only in memory/stdin. No backup recursively embeds its own artifacts. Independent verification allocates a fresh owned SQLite database and private document directory, migrates/restores separately and inserts decoy business IDs to exercise remapping. It compares source controls, accounting, product integrity, posted source pointers and every restored document byte checksum. Missing PHP CLI/SQLite PDO blocks before production posting.

The document-bearing test supplies an inherited original document root and proves the child writes only to its own destination. Restored approvals are deliberately invalidated and require fresh local review/approval, verification, backup/proof and lock. The company backup is not a full installation backup; the guide requires the existing installation/database/files backup procedure as well.

The full Reports backup selection includes onboarding. New archives identify the onboarding contract. Older full archives that predate onboarding remain recoverable with an explicit warning and cleared destination cutover markers; current selective archives omitting onboarding cannot masquerade as full replacements.

## 21. Tenant / permission results

Session, staged rows, original source downloads, previews, matches, approvals, reconciliation and posted pointers use company scopes and explicit role checks. Two active company sessions were also checked with explicit cross-company review/approve/post/verify/lock/mapping/row-edit denials. Foreign record matches and foreign session/source access are rejected; search/assistant facts do not cross company boundaries. Action privileges also require onboarding view permission before mutation, preventing a write followed by a forbidden detail response. Default admin/superadmin receive cutover privileges; ordinary product/inventory or manager access does not grant them automatically.

## 22. Ask AIMS low stock

`whats low stock` now uses bounded current authoritative available stock below configured minimum/reorder threshold, not a prediction. Tests use 500 on hand with 498 reserved and only 2 available against minimum 10, in EN/SQ/mixed language. Future wording such as next month/week/tomorrow or Albanian equivalents remains a stockout/predictive intent under existing evidence gates. Other read-only tools explain onboarding status, rows needing review and reconciliation blockers; none can approve/post. No paid external AI dependency was added.

## 23. Purchase Request / RFQ lifecycle

Unused uncommitted drafts can be soft-deleted only without approval/RFQ/document history. Submitted/approved/rejected/sourcing requests may be withdrawn with a reason only without downstream awards/commitments and while RFQs are closed/cancelled. Pending approvals are cancelled with retained decisions. Closed/cancelled RFQs reject new issue/quote/award transitions; awards/PO history prevent destructive closure. Fresh locks/status checks prevent stale updates/submissions from undoing withdrawal. Bilingual controls use the existing dirty-state modal pattern.

## 24. Shipment source age

Shipment maps reject null/empty coordinates rather than treating them as zero. They show last verified position, update time and provider/source, or current position unavailable, and clear stale layers. Popup text is inserted safely as text. This communicates source evidence rather than implying a live vessel position.

## 25. Tests run

All eight mandatory jobs passed in final exact-source [AIMS CI #36](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246) for `1a941902`. Backend suites passed 632 tests on each engine: SQLite 5,852 assertions in 172.33 seconds; MariaDB 5,853 assertions in 224.96 seconds. One conditional Redis test was skipped on each engine because a Redis test environment was not configured. The release browser suite passed all 106 tests in 23.3 minutes. The successful-upload file-picker reset also passed its targeted local onboarding journey in 2.0 minutes.

| Mandatory hosted check | Result / evidence |
|---|---|
| Backend SQLite | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458743191) |
| Backend MariaDB and both real two-process races | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458743158) |
| Frontend Node tests, dependency audit and build | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458742909) |
| Browser smoke | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458743061) |
| Full browser release certification | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458743186) |
| Independent populated upgrade and recovery | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458743147) |
| Linux Nginx and supervised workers | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458743126) |
| Windows bundled startup | [PASS](https://github.com/AnisNeziri/Lab2/actions/runs/39054210246/job/117458743023) |

The three optional web/desktop artifact and release-candidate jobs were intentionally skipped with `build_review_artifacts=false`. Test evidence artifacts were retained by their certification jobs. No customer deployment was performed. The preceding source `6e30c527` also passed all eight mandatory jobs in [AIMS CI #35](https://github.com/AnisNeziri/Lab2/actions/runs/39052164570).

Relevant commands were `php artisan test` from `backend` on isolated SQLite/MariaDB environments; `php tests/Support/bank-match-concurrency.php` and `php tests/Support/onboarding-concurrency.php` on an owned MariaDB test database; `npm test`, `npm run build` and `npm run test:e2e:release` from `frontend`; desktop `npm run test:startup`; and `bash scripts/certify-linux-server.sh`. The populated recovery job generates the owned synthetic baseline at the user-selected `5db86866`, upgrades separate installations, verifies both SQLite and MariaDB recovery, and force-kills stock/payment/journal/receipt writes inside real transactions. This is process-interruption coverage, not a physical power-cut claim.

Local Node verification passed 89 tests. Python verification passed 30 tests in groups of 16, 8 and 6; CI runs the first two groups, with the other six covered locally. Additional MariaDB recovery/onboarding regression checks passed 29 tests and 493 assertions; a two-active-company isolation case passed 47 assertions on each engine. These overlapping runs are supplementary evidence, not added together as distinct coverage.

The precision regression reproduced `123456789.123456` becoming `123456789.123460` before correction and verifies the exact six-decimal value afterward, including `0.000001` and invalid extra digits/booleans. The browser backup regression that detected a missing onboarding module in full backup selection now passes with full encrypted backup/restore, and legacy full archives/selective current archive handling have feature coverage. Final core test summaries and source hashes are recorded in the evidence JSON.

Representative browser onboarding uses 30 products, existing identity choices, two warehouses, fractional inventory and explicit roll/box conversions, AR/AP/advances/cash/bank, independently declared negative opening equity, approval invalidation, dirty edit retention, reload/resume, independent restore and lock. It checks source/reconciliation/locked views at 1920×1080, 1366×768, 768×1024 and 390×844 in all EN/SQ × light/dark combinations, then performs a normal supplier advance reversal without changing historical opening proof.

Measured synthetic staging/dry validation and a 50-row page on backend source `6e30c527` (unchanged by the final file-picker fix). These are single local trials, not p95 or a full 10,000-row posting SLA:

| Engine | Rows | Upload ms | Dry validation ms | Page ms | Queries | Peak MB | Operational effects |
|---|---:|---:|---:|---:|---:|---:|---:|
| SQLite | 500 | 45.07 | 679.29 | 6.56 | 587 | 42 | 0 |
| SQLite | 2,000 | 95.22 | 2,696.26 | 4.78 | 2,128 | 46 | 0 |
| SQLite | 10,000 | 394.29 | 14,506.90 | 19.08 | 10,368 | 60 | 0 |
| MariaDB | 500 | 58.39 | 1,201.69 | 13.11 | 585 | 42 | 0 |
| MariaDB | 2,000 | 129.28 | 5,221.96 | 17.09 | 2,128 | 46 | 0 |
| MariaDB | 10,000 | 571.79 | 27,007.78 | 42.78 | 10,368 | 64 | 0 |

The query count grows approximately once per staged product during validation; it is bounded, not constant. All controls passed. Compact evidence is in [dc1/VERIFICATION.json](dc1/VERIFICATION.json); private databases, source bytes, encrypted archives, passphrases and document contents are not committed.

## 26. Remaining limitations and readiness

Supported scope is a new operationally empty company, base-currency opening statements, UTF-8 CSV/flat JSON, reviewed identities and explicit fixed unit conversions. Position rows require positive amounts/quantities; confirmed zero domains use a zero source total and no position rows. Negative cash/bank positions are not supported. Positive tracked inventory requires the existing lot/serial trace workflow; DC1 does not invent trace history. Foreign currency needs independently reviewed conversion first. XLSX parsing is not added. Limits are 10 MB / 10,000 rows per file, pages of 50 and bounded posting. Company document portable backups retain the existing 32 MB cap. PHP 8.3+ CLI with SQLite PDO is required for the separate-copy check. Ordinary operations should be paused throughout cutover preparation/posting. Ten-thousand-row full posting throughput and real-company source quality were not certified by the staging benchmark.

Actual installation credentials, host/storage configuration, source statement accuracy, legal/accounting approval and installation-level disaster recovery still require the authorized company's own preflight. There is no claim of a real-company reconciliation or production cutover.

| Required answer | Result |
|---|---|
| Bank reconciliation concurrency protected | YES |
| Approval + Purchase Request transition atomic | YES |
| Dry-run creates zero operational effects | YES |
| Duplicate product/customer candidates require review | YES |
| Opening stock uses authoritative inventory service | YES |
| AR/customer advances remain separate | YES |
| AP/supplier advances remain separate | YES |
| Opening journal balanced | YES |
| Source vs AIMS inventory reconciled | YES — synthetic fixtures |
| Source vs AIMS AR reconciled | YES — synthetic fixtures |
| Source vs AIMS AP reconciled | YES — synthetic fixtures |
| Source vs AIMS cash/bank reconciled | YES — synthetic fixtures |
| Posting retry is idempotent | YES |
| Reviewed-data mutation invalidates approval | YES |
| Two-company isolation passed | YES |
| Independent restore verification passed | YES — separate owned database and document root |
| `whats low stock` uses factual inventory | YES |
| Real company data used | NO — only synthetic/test onboarding verified |
| Ready for controlled real-company onboarding | YES — within the supported scope, after installation-specific backup/preflight, authorized source review and explicit human decisions; no real-company cutover has been verified or authorized |

Do not automatically start real-company cutover or a next deep phase. Obtain the authorized company sources and installation-specific preflight/review first.
