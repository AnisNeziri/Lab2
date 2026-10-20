# Reproduce PR2 performance checks

Run these commands from the isolated PR2 checkout. Install its backend Composer and frontend npm dependencies first. Preserve another chat's checkout and configuration. This tooling is for disposable fixtures; it is deliberately separate from PM3 chronological certification.

The authoritative application code at b720d1db passed the full release workflow. docs/PR2_PERFORMANCE_REPORT.md and pr2-results.json record its measured envelope, qualifications and remaining targets. Do not interpret a tolerant CI performance budget as a customer SLA.

## Guarded generation

The generator ignores the app's .env and builds only a PERFORMANCE / TEST DATA fixture under this checkout's physical output directory. --yes is required. --reset is accepted only for an existing matching ownership marker. Production, links/redirected directories, invalid volumes/ports and unmarked databases are refused.

Example SQLite cohort:

    php scripts/performance/generate.php --engine=sqlite --seed=20261011 --products=1000 --customers=2000 --suppliers=100 --warehouses=10 --orders=50000 --yes
    php scripts/performance/profile.php output/performance/20261011/aims-pr2.sqlite output/pr2-profile.json
    php scripts/performance/budgets.php output/pr2-profile.json
    python3 scripts/performance/guard_checks.py

The negative guard check creates a fresh unmarked scratch database inside output and confirms its bytes survive a rejected reset. It never chooses the application's database.

A separate MariaDB instance is preferable to sharing a business server. The recorded instance used a fresh datadir inside output/performance/mariadb-owned, loopback port 3312, max_allowed_packet 64M, innodb_buffer_pool_size 512M, max_connections 64. Configure the instance independently; do not apply these settings to a running installation merely to reproduce a test. Verify process, datadir and port ownership before starting/stopping it.

Linux/macOS environment example, with an already running disposable engine:

    export AIMS_PERF_MYSQL_PORT=3312
    php scripts/performance/generate.php --engine=mysql --seed=20261011 --products=1000 --customers=2000 --suppliers=100 --warehouses=10 --orders=50000 --yes
    php scripts/performance/profile.php mysql:20261011 output/pr2-profile-mysql.json

PowerShell environment equivalent:

    $env:AIMS_PERF_MYSQL_PORT='3312'

The internally derived database is aims_pr2_perf_20261011. There is no arbitrary host or database-name override. Optional AIMS_PERF_MYSQL_USER/PASSWORD apply only to the marked loopback fixture; keep credentials out of logs. The default port is 3306, so set 3312 explicitly when reproducing this report.

The main fixture has bulk draft histories and qualified frozen FI/CSI read shapes. It does not run PM3's lifecycle or establish accuracy, cash/accounting reconciliation or posted-history certification. Scope-specific authoritative race records are created through the real APIs, then verified independently.

## HTTP, browser and concurrency probes

Select PHP explicitly when it is not on PATH:

    export AIMS_PERFORMANCE_PHP=/absolute/path/to/php
    node scripts/performance/load.cjs mysql:20261011

load.cjs launches up to eight owned loopback HTTP workers on ports 8100–8107, runs 25 distinct sessions and fresh idempotency/revision/count/transfer/payment races, then stops its own child processes. It records expected 409/422/404 separately from failures. The exact base stock check occurs before the additional warehouse races. A matching SQLite fixture is also accepted. It is a functional integrity load, not PHP-FPM, a saturated mixed web/queue test or a VPS benchmark.

To sample Windows process memory/CPU, set AIMS_PERF_DB_PID to the verified disposable MariaDB PID and AIMS_PERF_MYSQL_CLI to its mysql client. Do not use an unrelated PID. Process CPU is cumulative; per-process memory peaks are not necessarily simultaneous.

Build the normal frontend and install the Playwright Chromium browser before the large browser probe:

    npm --prefix frontend run build
    php scripts/performance/documents.php output/performance/20261011/aims-pr2.sqlite
    node scripts/performance/browser.cjs output/performance/20261011/aims-pr2.sqlite

The browser probe uses owned ports 8036/4196. It visits Dashboard, Product, Documents, Order, Stock, Shipment, FI/CSI tabs, Search and Ask. It scrolls each 5/10/18/25 layout, checks skeleton completion/retry/visibility, and navigates thirty times in one SPA. Simulated document.hidden is not a real OS background-tab test. The short heap soak is not a long-term leak certificate.

documents.php creates 1,000 real tiny files plus valid metadata in fixture-owned document storage; it checks all 40 list pages and checksum-verified on-demand preview. Run it before browser.cjs so the document-card smoke has data. Bulk attachment setup skips audit/upload workflow and does not benchmark large PDFs. mysql:20261011 is accepted for the API-only document check.

## Search and heavy work

Start the resolver probe at exactly 500 products/customers in a separate fixture; it grows that owned fixture toward 2,000/10,000:

    php scripts/performance/generate.php --engine=sqlite --seed=20261014 --products=500 --customers=500 --suppliers=5 --warehouses=2 --orders=100 --yes
    php scripts/performance/search.php output/performance/20261014/aims-pr2.sqlite

A unique probe product adds one to each phase's product count. Exact lookup succeeds beyond the fuzzy cap; some typos safely decline when the bounded candidates cannot establish identity. Reusing an already-grown fixture is not a valid 500/2k/10k comparison.

For queues, generate a separate small fixture and use the pinned supported Python runtime:

    php scripts/performance/generate.php --engine=mysql --seed=20261013 --products=10 --customers=10 --suppliers=5 --warehouses=2 --orders=100 --yes
    export AIMS_ML_PYTHON=/absolute/path/to/supported/python
    node scripts/performance/queues.cjs mysql:20261013
    php scripts/performance/profile.php mysql:20261013 output/pr2-heavy-profile.json
    node scripts/performance/python.cjs

queues.cjs adds five synthetic 90-day histories, normal event jobs, optimizer/simulation work and cancellations. It tests 2 normal + 1 optimizer + 1 simulation worker, operational-count stability and health requests. It is not the full 120-scope solver, ML-accuracy or real outbound-webhook benchmark. The profiler selects OPTIMAL/COMPLETED results explicitly; cancelled rows are not evidence/detail measurements.

python.cjs measures three single starts and three pairs of the pinned Python/NumPy/SciPy import path, including milp, with child-reported peak memory and CPU. It is not maximum solve memory.

## Exports, allocation and maintenance

    php scripts/performance/resources.php output/performance/20261011/aims-pr2.sqlite
    php scripts/performance/resources.php mysql:20261011
    python3 scripts/performance/index_cost.py output/performance/20261011/aims-pr2.sqlite

resources.php consumes a streamed CSV while discarding chunks, recording bytes/lines/time/peak memory. Whole-history exports can take over a minute; narrower date/product filters are the practical default. Do not compare wall timings across machine suspend, interruptions, concurrent builds or unrelated heavy work.

index_cost.py opens the marked source read-only, makes a fresh owned compacted copy, drops only the seven named PR2 indexes on that copy and compares allocation after compaction. It deletes only its exact scratch copy. It never vacuums or drops an index on the source.

No script changes the business installation's journal mode, buffer pool or retention. Follow the report's verified-backup/maintenance-window guidance. Do not delete accounting, inventory, legal-hold documents or referenced immutable evaluation evidence for performance.

## Results and confidentiality

Reports are under ignored output. The prepare/load/queue bridge includes private JWT/environment state for owned server children; never publish those raw bridge files. The committed pr2-results.json retains timings, query plans without bindings, counts and aggregate checks, and excludes those tokens/credentials.

The lightweight performance workflow uses only 100 orders with 1,000 products/2,000 customers, guarded refusal probes and tolerant response/query/5s budgets. Full load runs are manual. Full release certification remains required after authoritative service changes; it is not replaced by these budgets.

