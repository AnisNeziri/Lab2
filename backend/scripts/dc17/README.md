# DC17 continuation / evidence

Current branch and prior dirty work are preserved. No active-company migration,
cutover, commit, push, reseed or deployment is authorized.

The existing DC16 guarded bootstrap restricts these PHP scripts to the owned
MariaDB11.4.13 database `aims_dc16_20261102` on localhost3320. Private credentials,
documents and keys are read from the existing recovery installation, never printed.

Checkpoint/report: `C:/Users/anisi/.codex/recovery/dc17-20261103/`.
Full logs, JUnit, matrices, PDF and UI proofs are in its `evidence/` directory.
Preserved DC16 evidence remains in its original directory.

Use the existing PHP runtime/PHPRC described in `../dc16/README.md`.
Read-only rechecks: `verify.php`, `history-guard.php`, `ask-oracles.php`,
`ui-evidence.php`. `finalize.py` aggregates evidence without tests or ERP writes.
Do not rerun completed write journeys or the full historical replay.

`security.php`/`performance.php`/`ask-evaluate.php` exercise normal guarded APIs
using synthetic accounts. Assistant evaluation verifies the business digest.
`workflows.php`, `supplier-return.php` and `prospective-dispatch.php` retain
completion checkpoints/stable identities; partial failures require inspection.
Concurrency worker barriers and existing DC16 long-replay evidence are preserved.

The closure-provenance migration was applied only to the isolated test schema.
Older installations safely refuse explicit day closure pending reviewed upgrade;
missing migration evidence never qualifies zero demand. No historical timestamps
are backfilled. Missing genuine-company readiness evidence remains blocked.

Do not begin DC18 automatically.
