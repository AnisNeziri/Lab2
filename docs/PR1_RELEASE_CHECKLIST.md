# Authoritative AIMS PR1 release gate

Version: `RELEASE.json`. Upgrade baseline: `5db86866` (explicitly selected by the owner). Every required box must have dated evidence for the **same Git commit**. An absent, skipped or failed required check blocks certification. Keep `RELEASE.json.status=uncertified` until completion.

- [ ] Full SQLite backend suite passes.
- [ ] Full populated MariaDB migration/backend suite passes.
- [ ] Frontend unit tests and normal minified production build pass.
- [ ] Python forecasting and optimizer suites pass with pinned packages.
- [ ] Browser smoke and complete Browser Release Certification pass remotely; manual-dispatch jobs run.
- [ ] Windows Desktop Startup passes remotely with bundled PHP/Python, clean SQLite, unique first-login recovery, workers, restart and crash recovery.
- [ ] Normal and forced desktop exit leave no children; reopening cannot duplicate workers.
- [ ] Interrupted authoritative SQLite writes recover without partial inventory/payment/journal/receipt state.
- [ ] Populated `5db86866` → verified backup → forward migration → login/business workflows passes for SQLite and MariaDB.
- [ ] Wrong-key/corruption/future-version backup rejection passes.
- [ ] Installation A → separate empty B restores the DB, actual attachments, settings/preferences and intelligence history.
- [ ] Inventory, six financial control balances, tenant checks and document checksums/associations reconcile exactly after restore.
- [ ] Scheduled backup overlap/retention/verified-success and failure visibility are reviewed; off-server retrieval and separate restore are recorded.
- [ ] Server staging passes Nginx routing/HTTPS/private-storage/headers/upload-limit tests, readiness, graceful deployment and worker restart.
- [ ] The Linux test server is rebooted and workers/timers return automatically.
- [ ] Actual clean Windows VM installer/first launch/offline mode/licence/update path is verified, and customer installer signing is valid.
- [ ] Auth/reset/revocation/recovery, role permissions, cross-company direct objects, uploads, webhook authentication/retries, CORS/rate limits and redaction have no unresolved critical findings.
- [ ] Required DB/Redis/Python/storage outages fail clearly; optional services show optional status.
- [ ] Midnight/month/year/DST and financial precision/idempotency regressions pass.
- [ ] Nine production-build performance timings are recorded against representative PM3 data.
- [ ] Safe diagnostics, customer export gaps, upgrade/rollback/DR runbook and release notes are reviewed.
- [ ] Artifact SHA-256 checks pass; the RC workflow depends on every automated certification job and publishes no customer deployment.
- [ ] Final `PR1_CERTIFICATION_REPORT.md` provides all 18 requested findings and explicit PASS/FAIL, YES/NO and remaining blockers.

Run the existing **AIMS CI** using workflow dispatch to execute Browser Release Certification and Desktop bundled startup. **AIMS release candidate certification** repeats the mandatory gate and creates review artifacts only after success. The separate signed desktop publishing workflow is not triggered by PR1 automatically. Do not certify from red CI, an untested restore, reconciliation mismatch, tenant breach or broken desktop startup.
