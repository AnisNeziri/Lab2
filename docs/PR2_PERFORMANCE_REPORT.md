# AIMS PR2 performance, scale and multi-user completion report

Measured 11–13 October 2026; report finalized 14 October 2026. Application commit b720d1db is certified by [full release CI](https://github.com/AnisNeziri/Lab2/actions/runs/38437182869). Later report/tooling changes preserve that application code. [PR2](https://github.com/AnisNeziri/Lab2/pull/2) is a draft stacked on PR1. The running PR1 checkout, its source and business database were preserved.

This is a measured local test envelope. It establishes integrity and selected performance characteristics; it does not certify predictive accuracy, an enterprise user maximum, a small VPS, or customer deployment readiness. No V13/PR3 work was started. Machine-readable evidence is in [pr2-results.json](performance/pr2-results.json); reproducible commands are in [the performance runbook](performance/README.md).

## 1. Baseline before changes

Before application edits, profile an owned copy of the populated, independently restored PM3 company: 36 products, 24 customers, four suppliers/warehouses, 308 orders, 301 sales, 9,553 stock movements, 560 debt transactions, 72 POs, 79 receipts, 69 shipments and 3,620 journals. The first profiler's legacy debt/journal-line table labels falsely returned zero; those values are excluded.

Each API measurement uses authenticated Laravel HTTP middleware/kernel, one cold request and three warm requests, reporting the warm median. It excludes PHP process startup, sockets, browser loading and TLS. The separate baseline production Chromium workflow measured Dashboard 3,551ms, Product 4,767ms, Order Hub 2,117ms, Shipment 3,401ms, FI 1,666ms, CSI 1,716ms, Search 1,809ms and Ask 616ms.

Targets remain engineering goals: summary <300ms, lists/details/search/ordinary Ask <500ms, precomputed heavy summaries <1s. CI uses a tolerant 5s ceiling plus query/payload budgets to catch severe regressions; it does not represent those user latency targets.

## 2. Dataset sizes tested

The guarded fast fixture creates company 2 and a foreign-company sentinel: 1,000 products, 2,000 customers, 100 suppliers, ten warehouses, 50,000 orders and 50,000 sales, 101,000 stock movements, 100,000 debt/payment rows, 5,000 POs/receipts/shipments, 50,000 draft journals and 100,000 journal lines. SQLite and an independent MariaDB instance on loopback port 3312 were tested. API-created race fixtures add small increments; exact counts at each measurement are retained in the JSON.

The generator exposes bounded volumes up to 10,000 products/customers and 250,000 orders; those configurable ceilings were not all tested. The main 50k cohort generated in roughly one minute per engine. A separate 500/2k/10k resolver fixture and a five-product, 90-day queue fixture are distinct from the main cohort.

Bulk history skips the chronological lifecycle. Paired movements/payments and balanced draft journals preserve relational shapes without pretending that all drafts are posted business history. FI/CSI frozen mocks are explicitly qualified INSUFFICIENT_DATA; their amounts/history shapes are not accounting reconciliation or evaluated predictions. PM3 chronological evidence gates remain intact. Each engine also passed 1,000 actual tiny text attachments and valid version metadata, 40 pages of 25 rows, and checksum-verified on-demand reading.

Generation ignores the application's .env. It accepts only internally named, marked loopback MariaDB fixtures or physically owned SQLite paths under output. Production, invalid ports/seeds, unmarked reset, and outside-database probes all fail safely; unmarked bytes remain unchanged.

## 3. Endpoint timings before/after

Unchanged PM3 data, warm in-process milliseconds:

| Endpoint | Before ms | After ms | Bytes before / after | Queries before / after |
|---|---:|---:|---:|---:|
| Dashboard | 166.75 | 56.81 | 142,430 / 75,900 | 118 / 12 |
| Order Hub | 71.43 | 68.63 | 14,795 / 14,795 | 53 / 53 |
| Product detail | 22.83 | 22.29 | 35,488 / 35,645 | 19 / 19 |
| Inventory / Stock | 47.45 | 21.17 | 31,446 / 35,335 | 101 / 7 |
| Stock movements | 110.48 | 49.63 | 143,610 / 145,334 | 108 / 9 |
| Customer debt | 8.18 | 8.23 | 19,973 / 19,973 | 4 / 4 |
| Purchase Orders | 16.36 | 15.19 | 18,602 / 18,602 | 6 / 6 |
| Goods Receipts | 14.58 | 14.33 | 19,652 / 19,652 | 8 / 8 |
| Shipments | 32.59 | 25.12 | 115,742 / 84,534 | 6 / 7 |
| Financial Intelligence | 70.23 | 9.76 | 282,808 / 282,808 | 3 / 3 |
| Financial dashboard summary | 72.59 | 10.32 | 194 / 194 | 3 / 3 |
| Customer/Sales Intelligence | 89.03 | 21.78 | 460,510 / 460,510 | 7 / 7 |
| Customer dashboard summary | 85.30 | 20.82 | 926 / 926 | 7 / 7 |
| Inventory Intelligence | 14.03 | 13.29 | 4,077 / 4,077 | 30 / 30 |
| Supply Optimizer list | 24.90 | 24.52 | 4,711 / 4,711 | 3 / 3 |
| Supply Optimizer detail | 54.20 | 54.63 | 326,065 / 326,065 | 4 / 4 |
| Strategic Simulation list | 12.83 | 11.77 | 1,589 / 1,589 | 3 / 3 |
| Strategic Simulation detail | 84.10 | 84.17 | 442,827 / 442,827 | 3 / 3 |
| Global Search | 142.89 | 99.24 | 2,262 / 2,261 | 79 / 82 |
| Ask AIMS | 241.20 | 181.64 | 18,533 / 18,533 | 4 / 4 |
| Action Center | 469.35 | 431.77 | 45,292 / 45,292 | 129 / 63 |

The legacy full FI/CSI responses remain available with the same evidence. Page overview sizes are reported separately below. The customer-debt timing is the paginated customer-list entry, not certification of an unlimited individual ledger or the company-wide credit report.

Main growth cohort, quiet warm kernel measurements:

| Endpoint | SQLite ms | MariaDB ms | Bytes SQLite / MariaDB | Queries SQLite / MariaDB |
|---|---:|---:|---:|---:|
| Dashboard | 413.73 | 533.39 | 79,715 / 86,388 | 12 / 12 |
| Order Hub | 60.99 | 43.55 | 13,372 / 13,664 | 29 / 37 |
| Product detail | 37.45 | 26.59 | 33,296 / 33,279 | 17 / 17 |
| Inventory / Stock | 21.15 | 23.69 | 37,663 / 37,663 | 6 / 6 |
| Stock movements | 52.09 | 65.59 | 135,020 / 147,841 | 7 / 9 |
| Customer debt | 8.56 | 12.61 | 18,450 / 18,447 | 4 / 4 |
| Purchase Orders | 16.85 | 22.95 | 17,499 / 18,184 | 6 / 6 |
| Goods Receipts | 18.16 | 23.79 | 19,086 / 19,579 | 8 / 9 |
| Shipments | 26.13 | 35.71 | 80,605 / 80,605 | 7 / 7 |
| Finance page overview | 55.26 | 91.34 | 1,050 / 1,050 | 3 / 3 |
| Customer page overview | 101.10 | 119.95 | 1,018 / 1,018 | 7 / 7 |
| Inventory Intelligence | 13.99 | 22.71 | 4,305 / 4,305 | 30 / 30 |
| Global Search | 361.11 | 836.46 | 2 / 2 | 80 / 61 |
| Global Search positive | 168.39 | 411.81 | 6,939 / 6,917 | 77 / 58 |
| Global Search exact order | 257.21 | 624.01 | 1,930 / 1,930 | 69 / 50 |
| Ask AIMS | 253.27 | 247.60 | 7,660 / 7,660 | 4 / 4 |
| Action Center | 1237.39 | 1206.68 | 4,885 / 4,876 | 30 / 31 |

SQLite scale Dashboard fell from 1,343.84ms/115 queries to 413.73ms/12; Stock from 366.87ms/106 to 52.09ms/7; Action Center from 9,423.70ms/1,330 to 1,237.39ms/30. Same-buffer MariaDB comparison: Dashboard 2,085.02 → 891.86ms and Stock 364.09 → 42.21ms, on the earlier 16MiB instance. Final MariaDB measurements use a separate 512MiB buffer pool. Do not attribute that additional improvement entirely to code.

Completed optimizer/simulation detail measurements use the small queue cohort, selecting a completed frozen result explicitly. Main bulk fixtures contain no completed solver runs; their detail routes are NOT_TESTED, not substitutes for index responses.

| Small completed-job endpoint | Median ms | Bytes | Queries |
|---|---:|---:|---:|
| Supply Optimizer list | 21.01 | 1421 | 3 |
| Supply Optimizer detail | 43.59 | 76002 | 4 |
| Strategic Simulation list | 24.28 | 2437 | 3 |
| Strategic Simulation detail | 56.9 | 200775 | 3 |

## 4. Response sizes before/after

On PM3, Dashboard 142,430 → 75,900 bytes. FI initial page 282,808 → 1,125 bytes; CSI 460,510 → 13,603 bytes. On the large frozen read shapes, legacy FI is 5,071,564 bytes and CSI 4,701,215 bytes; overview reads are 1,050 and 1,018 bytes.

The adapter projects authorized frozen evidence without changing domain totals or immutable snapshots. Cash timelines remain available; receivables, commitments, inventory and customer histories are requested by tab/page. Fifty-row CSI detail pages are about 118KB, FI health about 73KB. Summary reductions do not remove legacy full evidence, financial permission checks or qualification text.

Stock and shipment histories use explicit pages with a 100-row maximum. The product adjustment picker requests fifty scalar reference rows, about 19KB, and resolves a selected product separately. Shipment UI requests fifty records and fetches off-page linked details.

## 5. Indexes added/changed

EXPLAIN/EXPLAIN QUERY PLAN, repeated SQL and query counts drove these composite indexes:

- debt: company_id, customer_id, reversed_transaction_id, type;
- stock chronological reads: company_id, effective_occurred_at, id, where the virtual date is COALESCE(occurred_at, created_at);
- stock created order: company_id, created_at, id;
- FI and CSI latest snapshot: company_id, id;
- stock totals: company_id, type, quantity;
- stock usage: company_id, movement_code, type, occurred_at.

They have explicit rollback migrations. No global index removal or production migration occurred locally. On a compacted copy, removing only these seven indexes saves 16,162,816 bytes (15.4MiB), about 9.8% of its 164MB allocation. The original fixture was opened read-only; the control copy preserves the same rows. This is aggregate allocation, not per-index sizing. Indexes add write/storage cost; the two-engine full suites and populated upgrade/recovery validate the generated column. Portable backup excludes generated columns from inserts, and upgrade certification hashes every original column while separately verifying new revision/default and generated-date values.

## 6. N+1 issues fixed

Dashboard uses grouped stock totals and bounded product projections. Product reference serialization uses selected aggregates; inventory locator avoids one snapshot per product. Stock pages group movement totals instead of recomputing each row. Action Center batches customer payment/debt allocation with the same oldest-due-first, reversal and null-due-date semantics. Replenishment shares company currency and CSI shares permission lookups.

Regression checks use tolerant budgets rather than exact incidental query counts. Original Dashboard 118 → 12, locator 101 → 7, stock 108 → 9 and Action Center 129 → 63 queries; the scale Action Center change is 1,330 → 30. Product detail still intentionally loads its existing detail relations.

## 7. Frontend bundle improvements

Authenticated AppLayout is lazy-loaded behind the protected route. The production main JS drops from 614.17KB to 464.49KB; gzip 181.28KB → 133.81KB, roughly 24%/26% reductions. Main CSS drops from 274.89KB to 254.53KB. PDF, 3D and charts remain available; the 541KB PDF and 941KB 3D chunks still produce warnings when built, but are loaded on demand rather than for login.

No build-size warning threshold was increased to conceal large chunks. Production CI passed the ordinary build and 88 frontend tests. The browser run records initial login assets; reload-to-painted route times remain affected by single-process Windows PHP and startup API reads.

## 8. Dashboard performance

Production Chromium exercised all 5/10/18/25 layouts, scrolling every widget into view. All finish with zero widget errors and zero skeletons. The offscreen completion bug was fixed: hidden subscriptions receive completed shared state while only visible subscribers activate reads/refresh. Supplier choices remain disabled until their actual options arrive.

MariaDB / SQLite milliseconds and bytes:

| Widgets | Grid ready ms M / S | All requested widgets ms M / S | API reads | Bytes M / S |
|---|---:|---:|---:|---:|
| 5 | 823 / 860 | 4751 / 5601 | 11 | 103,685 / 97,044 |
| 10 | 779 / 836 | 5763 / 6109 | 13 | 105,000 / 98,359 |
| 18 | 793 / 818 | 9327 / 10327 | 16 | 112,947 / 106,181 |
| 25 | 778 / 811 | 11408 / 11809 | 23 | 278,513 / 271,873 |

Shared widget reads remain capped at four; the observed all-API peak of seven includes three workspace/auth startup requests. No duplicate widget endpoint reads occurred. Notifications are still read twice by the existing shell. The full layout's shipment list contributes about 158KB and is a remaining summary opportunity.

An intentional 500 retained known data and retry recovered just that widget. A simulated document.hidden/visibilitychange probe produced no hidden refresh and successful resume; this is not proof of OS background-tab timer behavior.

Thirty SPA navigations included product dialogs, shipment map, finance, CSI, Dashboard, Search and Ask. MariaDB JS heap after collection grew 13.53 → 14.85MB with DOM nodes stable at 1,846 after the first cycle. SQLite grew 12.36 → 13.33MB with stable 1,730 nodes. This is a bounded short soak, not zero heap growth or an eight-hour leak certification.

## 9. Ask AIMS and search

PM3 Search 142.89 → 99.24ms and Ask 241.20 → 181.64ms. Large Ask medians are 253.27ms SQLite and 247.60ms MariaDB for the deterministic debt question, reading existing evidence. This does not cover every tool chain/provider or Ask under the 25-user burst.

Large global search: positive match 168.39/411.81ms; missing term 361.11/836.46ms; exact order 257.21/624.01ms, SQLite/MariaDB. The 500ms target is not met for all MariaDB cases. Search remains ranked/bounded and SQL-scoped; product/order candidate IDs are resolved before stock/intake filters.

Resolver tests at 500, 2,000 and 10,000 entities use one exact lookup before at most 201 fuzzy candidates. Late exact SKU medians 2.37/4.44/15.50ms. Rare typo probes 7.88/29.92/64.93ms with at most two candidate queries. At 10k the bounded fuzzy probe safely declines the typo; it is not a claim of typo recall. Exact lookup still succeeds. Debounce, stale response guards, permissions and ambiguity/evidence thresholds remain.

## 10. Queue throughput findings

The disposable probe used two normal database-queue workers, one supply-optimizer worker and one strategic-simulation worker. Normal Automation Studio/webhook handlers and heavy work drain separately, including normal follow-up events. Final queues are empty, failed jobs zero, optimizer OPTIMAL, simulation COMPLETED, and queued cancellations remain CANCELLED. Stock, POs, money transactions, journals, PRs and transfers have no operational-count delta from solver/simulation work.

The measured probe takes about 26s including worker startup and the configured 20s normal-worker stay. Heavy worker process durations are roughly 8s in this small cohort. Six health HTTP probes succeed during work. This is not a saturated web/queue benchmark, external webhook/provider throughput, or full analytics training throughput. Schedule heavier maintenance/training outside business peaks.

## 11. Python/optimizer/simulation resources

Pinned Python 3.12.2, NumPy 2.3.5 and SciPy 1.16.2 import including milp: single subprocess wall 0.80–0.92s, about 70MB peak working set and 0.58–0.59 CPU seconds. Three two-process probes confirm the bounded concurrent startup path. Peak measurement is inside the child; coarse queue polling can miss a short child and is not evidence of zero Python memory.

BLAS threads are limited to one. [SciPy's parallel execution guidance](https://docs.scipy.org/doc/scipy/tutorial/parallel_execution.html) supports explicit thread-pool budgeting. The solver has a 25s process timeout, polling cancellation and guaranteed child stop. Job timeout is 240s and retry_after 300s. Existing limits include forty products, three warehouses, six suppliers, simulation twenty-four assumptions and qualified horizons through 365 days; these maximum scopes were not all benchmarked.

Publication locks the job state and only RUNNING work publishes immutable results. Cancellation cannot turn into completed evidence or operational drafts afterward. Actual child cancellation and publication races have focused regression coverage. No persistent ML service was introduced.

## 12. Concurrency results

Twenty-five distinct sessions read the large cohort through eight independent loopback PHP HTTP workers. Writer races use twenty-five separate authorized actors. This is not PHP-FPM or an enterprise capacity guarantee.

All checks pass: same-key stock/order/dispatch/payment/receipt effects post once, twenty-five independent stock keys each post once, two pickers leave the allocation picked once, reservation is released, receipts and PO payments reconcile, and journals checked remain balanced. Base quantity is exactly 72 and debt 17 with zero advance. Additional count/movement, count/receipt and transfer/sale races preserve their exact deltas. A stale count is rejected or posts before the concurrent change; it does not silently overwrite stock.

PO editing has one success and one 409 using revision. Workspace editing has one success and twenty-four 409s; a foreign tenant receives 404. Those expected conflicts are not operational failures. Legacy PO callers that omit the new optional revision retain previous compatibility behavior.

Final unexpected 4xx/5xx, timeout and request errors: zero. Dashboard burst p50/p95 is 2.69/3.53s; stock 1.34/1.87s, search 2.06/2.91s. The ordinary quiet API targets are not promises under this deliberately synchronized Windows worker burst.

## 13. DB lock/deadlock findings

Real MariaDB races found customer-payment/dispatch and transfer/sale lock-order deadlocks. Customer posting now follows company before customer; transfer and stock operations acquire the company parent/FK lock before products. Full outer authoritative transactions have bounded deadlock retries where needed. No stock/financial consistency, idempotency or tenant checks were removed.

The final run has no unresolved or surfaced deadlock error. Lock waits increase by 165 and cumulative wait time by 14,154ms across the measured workload, so there is contention even with no failed requests. MariaDB 10.4 does not expose the requested Innodb_deadlocks counter here. Do not interpret its absence, or a diagnostic section reset after server restart, as proof of zero lifetime deadlocks.

No Python execution or external network request was moved inside an authoritative lock. Bounded query-error logging records code/connection/request ID without dumping SQL bindings or entire financial payloads.

## 14. CPU, RAM, disk and database observations

Host: Windows, Ryzen 7 5800H, eight physical/sixteen logical cores, about 16GiB RAM. This is a developer laptop, not the requested 2–4 vCPU VPS. Owned-process CPU time and per-process working set were sampled; whole-host CPU percentage, disk IOPS and Redis live memory were not measured.

Across the sampled 53.8s concurrency window, owned processes consumed 286.75 CPU seconds. This is cumulative process CPU, not system utilization; per-PID memory peaks and raw timing samples are preserved.

Owned PHP workers peak around 68–80MB each; the MariaDB process is around 0.5GB working set with a 512MiB buffer pool. Max observed connections is eight against max_connections 64. These are sampled observations, not maximum solver memory or a guaranteed deployment budget.

The fixture is about 161MiB SQLite and about 332–340MiB MariaDB allocated data/index pages, with exact current allocation in the JSON. Stock and debt indexes are major contributors. The approximately 23MB, 101k-row CSV stays at about 48MB PHP peak allocation: 22.7s SQLite and the uninterrupted repeat 84.2s MariaDB. A previous run spanning the interrupted session is excluded from comparable timing. Full exports are long operations; use product/date filters, the direct file stream, and low-traffic scheduling. The browser fallback caps at 16MiB and asks for narrower filters. No background export-job system was added.

For annual planning, scale the cohort by comparable event volumes and measure table growth monthly; fixed reference/snapshot overhead makes naive bytes/order inaccurate. At the same proportions, another 50k orders plus 50k sales is on the order of 0.16–0.34GiB database allocation before real attachments, retained analytics and backup copies. One additional uncompressed 9.7MB FI/CSI snapshot pair daily would add about 3.5GB/year if no deduplication/archival occurred. This is an estimate, not a measured year.

Cache review: no new authoritative-balance cache. Existing derived dashboard stats are company-scoped, TTL 300s and invalidated on stock events; low-stock alerts expire after 3,600s and activity feed caps at 100/company. Redis-disabled fallback is tested; measure Redis MEMORY USAGE on a real deployment before assigning a quantitative multi-company memory budget. Assistant context expires after 60 minutes, with at most six tools and a 12s chain budget.

Technical retention recommendation: rotate diagnostic logs and transient job/webhook diagnostics at 14–30 days after preserving incident evidence; review successful execution details for archival after 90 days. These are proposed policy values, not a newly enabled destructive cleaner. Never automatically delete posted financial/inventory history, documents on legal hold, immutable decision/model evidence still referenced by evaluation, or required audit records. Archive/deduplicate redundant derived snapshots only after reference and restore verification.

SQLite currently uses DELETE journal and 60s busy timeout. Do not silently switch existing installations to WAL or run VACUUM during writes. For a maintenance window, verified backup first, integrity/foreign-key checks, then optional offline VACUUM; WAL adoption needs a tested backup/checkpoint plan. [SQLite WAL](https://www.sqlite.org/wal.html) permits readers alongside a writer but still has one writer and requires same-host coordination; [checkpoint guidance](https://www.sqlite.org/c3ref/wal_checkpoint_v2.html) matters for long readers. Existing installation backup handles database/documents together.

MariaDB maintenance: existing verified backup/restore procedure, monthly table/index growth review and disk alerts; inspect slow queries before changing indexes, and perform optional ANALYZE during low traffic. Bound web/queue connections together. [MariaDB memory allocation](https://mariadb.com/docs/server/ha-and-performance/mariadb-memory-allocation) distinguishes global buffers and connection costs; [InnoDB buffer-pool guidance](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-buffer-pool) informs measured sizing rather than a blanket percentage on a shared host.

## 15. Remaining bottlenecks and qualifications

P0/P1 observed failures fixed: deadlocks surfacing as 500, financial inventory tab missing its frozen landed-cost field, empty-search 422, offscreen widgets stuck loading, supplier selector enabled before options, and release-baseline dependency/schema comparison errors.

Remaining P2: growth Dashboard 414/533ms versus 300ms target; worst MariaDB search 836ms; Action Center around 1.2s; full frozen JSON still decoded server-side; full-layout shipment summary payload; long CSV export; cold route/startup latency and short-soak heap growth. A cache invalidation contract or materialized aggregate would be needed before caching any expensive read.

Pagination review confirms bounded product/customer/PO/receipt/task/audit/document/decision pages and capped intelligence/solver lists, with stock/shipment pages added. Strict pagination is still incomplete for legacy customer detail ledger and unfiltered daily-sales/service/credit-report paths; these are listed as remaining growth risks, not claimed fixed by browser slicing. Existing UI day filters and exact global summaries are retained. A large single-customer ledger and full 250k/max-solver envelope were not tested.

VPS-constrained testing, live Redis memory, long-duration memory soak and actual Electron startup with the 50k database remain unmeasured. CI validates real bundled Windows startup on its own SQLite installation; the large desktop-path evidence here is SQLite open/query/Chromium timing, not an Electron performance certificate. PR1 public TLS, real-host reboot/signing/production deployment gates are unchanged.

## 16. Recommended production server size

Provisional starting point, inferred from these measurements: four vCPU, 8GB RAM and expandable fast storage, initially at least 50GB with database/documents/backups capacity monitoring. Use four PHP-FPM children, two normal workers, one optimizer and one simulation worker, BLAS threads one; start MariaDB buffer pool at 512MiB–1GiB and max_connections 32–64, then size from actual working set and concurrent memory. [PHP-FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php) makes max_children the request concurrency bound; account for workers separately.

The synchronized 25-session probe averaged about 5.3 owned CPU-seconds per second on this laptop. For sustained 25-user bursts with overlapping heavy work, an 8-vCPU/16GB starting point offers more headroom. Neither size is certified by this laptop test; retest on the chosen host with real attachments and workload. A 2-vCPU/4GB pilot should serialize heavy work and lower web/worker counts rather than claim the 25-user envelope. Expensive training/maintenance and full exports belong outside peak business hours.

## Explicit completion gates

| Gate | Result |
|---|---|
| Large dataset generated | YES |
| MariaDB scale tested | YES |
| SQLite scale tested | YES |
| 25 concurrent sessions tested | YES |
| Duplicate stock effects under concurrency | NONE in final tested races |
| Duplicate financial effects under concurrency | NONE in final tested races |
| Deadlocks unresolved | NONE in final tested races; prior failures corrected |
| Dashboard normal load target met | NO across the growth envelope; YES on unchanged PM3 baseline |
| Global Search target met | NO across all MariaDB cases |
| Ask AIMS target met | YES for the measured ordinary question |
| Financial Intelligence payload reduced | YES |
| Customer Intelligence payload reduced | YES |
| Frontend production build | PASS |
| Release integrity preserved | YES |

Validation: 590 backend tests passed on SQLite (5,205 assertions), 590 on MariaDB (5,206), one conditional Redis test skipped on each; 88 frontend tests; all 98 browser release tests; Python unit tests; Windows bundled startup; Linux Nginx/supervised workers; independent populated upgrade/restore all PASS. The baseline remains the user-selected 5db86866. Performance budgets and refusal probes PASS. The draft PR remains reviewable; no customer deployment or merge occurred.
