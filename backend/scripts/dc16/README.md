# DC16 isolated quality investigation

This directory is a test-only operator harness. Nothing is registered as a route,
scheduler or production module. The current target is exclusively
`127.0.0.1:3320 / aims_dc16_20261102`, MariaDB 11.4.13. Every entry point checks the
private ownership marker before using business authorities. Existing installations
and the active `.env` are not inputs to data generation.

Private storage: `C:/Users/anisi/.codex/recovery/dc16-20261102`. Credentials,
synthetic sources, tokens, database checkpoints, reports and archives stay there.
Do not publish that directory. `output/dc16` contains ignored console logs.

## Resume the existing fixture

Use PowerShell from `C:/Users/anisi/Lab2`. The clean MariaDB instance must already
be running on 3320. Do not start/upgrade the damaged original server.

```powershell
$env:PHPRC = 'C:/Users/anisi/Lab2/output/dc8-runtime-php.ini'
$env:PHP_INI_SCAN_DIR = ''
$env:OPENSSL_CONF = 'C:/xampp/apache/conf/openssl.cnf'
& desktop/resources/php/php.exe backend/scripts/dc16/progress.php
& backend/scripts/dc16/run-history.ps1
```

Do not run two replay processes. A database advisory lock also rejects concurrent
replays. Each day and its agenda/checkpoint commit in the same MariaDB transaction;
on failure the day rolls back. Uncertain post-commit errors require checking the
database checkpoint before retry. The deterministic seed is 20261102. Historical
time is supplied through the existing Carbon test clock, never by rewriting issued
documents, audit timestamps or hashes. Registration/setup occurs at real operator
time; historical business dates are explicitly simulated.

The wrapper executes at most twelve batches of 31 days, stopping on an error. A
single bounded batch is `php backend/scripts/dc16/history.php 7`. Dates span
2025-11-01 to 2026-10-31. Read-only verification is
`php backend/scripts/dc16/verify.php`; it uses a consistent transaction snapshot.
Do not perform interactive writes during replay: the atomic historical day holds
company-level authority locks and can block otherwise normal HTTP operations.

## Initial stages (already executed; resume, do not recreate)

The exact order is `run.php migrate`, `run.php inventory`, `accounts.php`,
`roles.php`, `opening.php`, `checkpoint-schema.php`, `setup-history.php`,
`customer-profile.php`, then `history.php` / `verify.php` / `coverage.php`.
Migrations are normal additive migrations on an empty *new* schema. No fresh,
reset or seeding command is used. Registration, email verification (array-mail
capture), temporary-password change, owner capability configuration and delegated
accountant opening review use real application APIs.

Schema provisioning is `php backend/scripts/dc16/provision.php --provision-owned-dc16`
and requires the independently preserved clean-instance administrator credentials.
It creates only the named new schema, ownership marker, schema-scoped runtime and
migration identities. For another machine/date, copy this harness and deliberately
configure a new owned database/private directory; do not point it at an existing
business schema. Provisioning is not part of the replay runtime.

Opening source files contain 300 products, 120 customers, 25 suppliers, four
warehouses, 12 bins plus their zone/rack/shelf parents, supported separate control
balances and explicit synthetic statements. Normal onboarding validates sources,
requires an encrypted pre-backup, separate accountant review/owner approval,
bounded authoritative posting, reconciliation, independent portable restore and
locking. The independent portable SQLite restore is not certification of an entire
encrypted installation or native desktop recovery.

Replay uses existing procurement, receiving, quality, landed-cost, outbound,
customer debt, expense, payroll, count, automation and intelligence authorities.
Shipment metadata is explicitly synthetic and never calls a provider. No live
email, payment, payroll or fiscal submission is permitted. Laravel HTTP rejects
stray requests; mail uses the local array transport. Provider-specific/native
clients still require separate inspection before they are exercised.

## Evidence interpretation

`history-checkpoint.json` is progress, not completion. `integrity-day-N.json` is
the result at N committed days. `coverage.json` starts every unverified pathway as
NOT TESTED; a 2xx response alone cannot promote it to PASS. Existing SQLite contract
tests and frontend tests do not certify the 12-month MariaDB fixture or browser UI.
Snapshot capture was added prospectively after the first 34 days; missing past
availability is never reconstructed. A fixed 30-product observation cohort spans
meters, pieces and kilograms. Temporal model trials are scheduled before future
data exists; insufficient evidence and unsupported historical evaluation remain
explicit limitations. No current-state output may be relabeled as a past forecast.

Halt on failed integrity checks, preserve exact records/logs, and record defects for
DC17. Do not repair business balances or weaken gates to make the generator pass.
No commit, push, deployment, active cutover or DC17 remediation is authorized here.

## Completed year and incremental validation

The durable history checkpoint is day 364 (365 days completed). Do not regenerate
the company or year. `storage/evidence/checkpoint.json` in the private root is the
compact continuation index; `REPORT.md`, `feature-coverage.json`/CSV and
`defects.json` contain the bounded results and explicit unverified capabilities.
The registered-pathway inventory remains separate from business-capability coverage.

Use the configured PHP environment above. Completed verification commands are
`concurrency.php`, `role-matrix.php`, `export-checks.php`, `accounting-exports.php`,
`runtime-checks.php`, `feature-probes.php`, `intelligence-runs.php`, `ask-evaluate.php`
and `backtest.php`. They operate only on the owned fixture. Retain existing results;
do not rerun to improve a score. Some commands deliberately fail closed on an
incomplete fixture and require inspection before an explicit resume.

The full installation rehearsal was `installation-recovery.php backup`, then
`installation-recovery.php restore`. Source A is preserved; destination B is
`aims_dc16_restore_20261103`, with separate private `installation-b` storage. The
restore refuses an existing target. Do not erase/reuse B. Its proof certifies this
synthetic installation only. Never copy its health marker to the active installation.

`verify.php post-ui` performs a final consistent read after supervised UI changes
and writes `integrity-post-ui.json`, preserving the historical day-365 evidence.
`remaining-tests.py` discovers prior JUnit results and runs only previously
unexecuted PHPUnit identifiers against in-memory SQLite and private test storage.
It preserves all failures; a passing related contract does not erase them.

For an interrupted browser pass, use only the computer/browser tool, not external
Playwright scripts. The test frontend preview uses port 4186 and routes to API 8026
(`serve.php`). Keep the active application on 5173 and active `.env` untouched.
Browser print-preview/dialog behavior and native/hardware output have separate
statuses. The existing server-PDF rendering checks do not certify a native printer.

`checkpoint.py` refreshes the compact index, `review-results.py` records explicit
Ask dispositions, and `finalize-report.py` aggregates existing evidence without
creating transactions. Run with the host Python executable documented in the
private checkpoint. No model, backup, company or history regeneration is required.

Exact suite-discovery command, from `backend`, if test inventory changes:
`../desktop/resources/php/php.exe vendor/phpunit/phpunit/phpunit --list-tests-xml C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence/all-test-inventory.xml`.
Then run `remaining-tests.py` with the checkpoint's Python executable. Completed
identifiers are excluded, including preserved failures; explicitly select a failed
case from its JUnit record only after a relevant correction.

Browser-test servers, in separate foreground consoles: from `backend`, run
`../desktop/resources/php/php.exe -S 127.0.0.1:8026 scripts/dc16/serve.php` with the
PHP environment above. From `frontend`, set
`VITE_API_PROXY_TARGET=http://127.0.0.1:8026` and run the checkpoint's Node executable
with `node_modules/vite/bin/vite.js preview --host 127.0.0.1 --port 4186 --strictPort`.
These use the already verified production build; no frontend rebuild is needed.
