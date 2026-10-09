# Isolated PM3 synthetic company

This is a separate test workspace, not a replacement for the existing web-company database. All names, payments, documents and shipment histories are synthetic. The application displays a persistent **SYNTHETIC / TEST DATA** banner.

## Generate and start

From the Lab2 folder in PowerShell:

```powershell
& C:\xampp\php\php.exe backend\artisan aims:generate-demo-company --seed=20261006 --yes
.\scripts\start-synthetic.ps1
```

The generator refuses to overwrite an existing database. To regenerate, stop the two synthetic server processes first, then run:

```powershell
& C:\xampp\php\php.exe backend\artisan aims:generate-demo-company --seed=20261006 --reset --yes
```

Generation deliberately runs normal business services and chronological intelligence jobs. It is substantially slower than inserting random fixtures. It creates 90 days by default; progress is printed every five days.

The test workspace opens at `http://127.0.0.1:5174/login`, with its own API on port 8013. It does not replace or stop the normal servers on 5173/8000.

- Owner: `owner@aims-demo.test`
- Manager: `manager@aims-demo.test`
- Test-only password for both: `AimsDemo.Test.2026!`

The launcher prints the process IDs it starts. Stop only those processes; do not stop all PHP/Node processes. PHP's development server may also have a child process listening on port 8013. The launcher refuses occupied ports, incomplete datasets and failing integrity reports.

Pass `-PhpPath`, `-NodePath`, and `-PythonPath` to the launcher if the local runtimes are installed elsewhere. Python is needed for the existing local intelligence/optimizer tools; Docker, Redis, SMTP and live tracking workers are not required for this synthetic workspace.

## Configuration and artifacts

Central configuration: `backend/config/synthetic.php`.

Supported generation options: `--seed`, `--days`, `--products`, `--customers`, `--suppliers`, `--warehouses`, and `--intensity`. Default history ends on 6 October 2026. Demand patterns, customer cohorts and event schedules depend on the seed; UUIDs and runtime durations are not promised to be byte-identical.

Owned runtime artifacts:

- `backend/storage/app/synthetic/aims-pm3-20261006.sqlite`
- `backend/storage/app/synthetic/aims-pm3-20261006.sqlite.report.json`
- Separate `documents-20261006` and `cache-20261006` directories
- `output/pm3` browser screenshots and performance evidence

The database contains an ownership manifest with an exact seed. Reset cannot accept arbitrary database paths or connections, and rejects unrecognized databases, mismatched manifests and symbolic links. Generated files remain local; do not distribute the SQLite database as production business data.

## Validate without reseeding

```powershell
& C:\xampp\php\php.exe backend\artisan aims:check-demo-company --seed=20261006
& C:\xampp\php\php.exe backend\artisan aims:validate-demo-company --seed=20261006
```

`check-demo-company` only checks ownership and the saved completion report. `validate-demo-company` recalculates integrity, records read-only assistant/search checks and refreshes the report; it never reseeds historical transactions. Use `--refresh-advisory` only when you want new **current-day** planning/optimizer/simulation analysis records. This can take a few minutes and does not backdate outcomes.

The optional `--repair-rounding` flag is deliberately restricted to the owned synthetic database: it can append a separately audited valuation-rounding journal of at most €1 only when stock quantities and all other financial controls reconcile. It does not rewrite historical journals and is not a repair workflow for real-company data.

Do not regenerate the completed 90-day dataset simply to start the application. Generation took approximately 4 hours 41 minutes on this machine during the initial run; ordinary startup and validation reuse the existing dataset.

With the synthetic servers running, from the frontend folder:

```powershell
node node_modules\@playwright\test\cli.js test --config playwright.synthetic.config.js
```

This browser configuration has no database-reset/global-seed setup. Do not use the ordinary release-E2E seed workflow against the demo database. The browser checks intentionally save a 25-widget stress layout, then restore the owner's original/default dashboard.

## Evidence rules

Daily dispatches drive the sales ledger and credit obligations. An optional invoice documenting an existing dispatch is not another stock sale or another receivable. Receipts, transfers, counts, supplier payments and journals use the existing authoritative services.

Predictions and decisions are frozen before their later outcomes. Production promotion gates are unchanged. Sparse supplier histories, stockouts and cold-start products can legitimately remain ineligible. No-sale Sundays are **unknown demand**, not invented zero labels. A current-day bulk putaway may be added after the history to exercise reserve-versus-pick warehouse planning; its date and resulting recommendations are recorded explicitly.

Synthetic shipment milestones are not live AIS telemetry. The assistant's advisory plans and simulations may create analysis/audit records, but must not alter operational stock, orders, debt or journals. Tax/legal compliance, live tracking accuracy, code signing and external commerce connectors require separate deployment validation.

## Review the populated company

The history runs from **9 July through 6 October 2026**. Today's dashboard sales may correctly be zero until you record today's sales; choose 6 October in Daily Sales or use the monthly/quarterly views to inspect the populated history.

In Inventory Planning, open **Door Handles 03** for **Prishtina Dispatch** to inspect the reserve-warehouse transfer opportunity. In Supply Optimizer, compare the qualified €500 and €60,000 runs; the broader mixed-history scope intentionally keeps its infeasible/insufficient-evidence state. In Strategic Simulation, open **PM3 qualified dispatch network stress comparison**.

The complete findings, counts, limitations and coverage flags are in `docs/PRODUCT_MATURITY_PM3.md`. Machine-readable integrity/readiness results are in the synthetic database's `.report.json`; browser evidence is in `output/pm3/browser-validation.json`.
