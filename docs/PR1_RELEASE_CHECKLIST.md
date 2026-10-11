# Authoritative AIMS PR1 release gate

Version: `RELEASE.json`. Upgrade baseline: `5db86866` (explicitly selected by the owner). Every required box must have dated evidence for the **same Git commit**. An absent, skipped or failed required check blocks certification. Keep `RELEASE.json.status=uncertified` until completion.

Assessment: **11 October 2026**, certification/artifact commit `f84e2fb9517ef2adc952c5289797dae69b17fad3`; [all eight manual certification jobs and both review builds passed](https://github.com/AnisNeziri/Lab2/actions/runs/38137917071). Application source remains unchanged from `13635712`. See [the full report](PR1_CERTIFICATION_REPORT.md) and [sanitized evidence](evidence/PR1_CERTIFICATION.json). Later documentation commits do not alter this runtime. Backend suites each discover 577 tests: 576 pass and one conditional live-Redis round-trip is skipped; actual required Redis outage/recovery is exercised separately in Linux CI. No required CI job was skipped.

- [x] Full SQLite backend suite passes (5,015 assertions; conditional skip disclosed above).
- [x] Full populated MariaDB migration/backend suite passes (5,016 assertions; conditional skip disclosed above).
- [x] Frontend unit tests and normal minified production build pass.
- [x] Python forecasting and optimizer suites pass with pinned packages.
- [x] Browser smoke and complete Browser Release Certification pass remotely; manual-dispatch jobs run (98 release tests).
- [x] Windows Desktop Startup passes remotely with bundled PHP/Python, clean SQLite, unique first-login recovery, workers, restart and crash recovery.
- [x] Normal and forced desktop exit leave no children; reopening cannot duplicate workers.
- [x] Interrupted authoritative SQLite writes recover without partial inventory/payment/journal/receipt state.
- [x] Populated `5db86866` → verified backup → forward migration → login/business workflows passes for SQLite and MariaDB.
- [x] Wrong-key/corruption/future-version backup rejection passes.
- [x] Installation A → separate empty B restores the DB, actual attachments, settings/preferences and intelligence history.
- [x] Inventory, six financial control balances, tenant checks and document checksums/associations reconcile exactly after restore.
- [x] Scheduled backup overlap/retention/verified-success and failure visibility are reviewed; separate local/CI restore is recorded.
- [ ] Actual off-server backup retrieval and independently escrowed backup passphrase/original APP_KEY recovery are recorded.
- [x] Disposable Linux staging passes Nginx routing/HTTPS/private-storage/headers, readiness, scheduled backup and worker crash/service restart checks.
- [ ] Complete staged Linux release switch using `deploy/upgrade.sh` and actual HTTP upload-size boundary are verified.
- [ ] Real public-domain certificate issuance/renewal is verified (CI uses self-signed TLS).
- [ ] The Linux test server is rebooted and workers/timers return automatically.
- [ ] Actual clean Windows VM installer/first launch/offline mode/licence/update path is verified, and customer installer signing is valid.
- [x] Auth/reset/revocation/recovery, role permissions, cross-company direct objects, uploads, webhook authentication/retries, CORS/rate limits and redaction have no unresolved critical findings in the focused review; build-only dependency advisories remain documented.
- [x] Required DB/Redis/Python/storage outages fail clearly; optional services show optional status.
- [x] Midnight/month/year/DST and financial precision/idempotency regressions pass.
- [x] Nine production-build performance timings are recorded against representative PM3 data.
- [x] Safe diagnostics, customer export gaps, upgrade/rollback/DR runbook and release notes are reviewed.
- [x] Local web/unsigned Windows review artifact SHA-256 and contents checks pass.
- [x] The explicitly authorized RC artifact workflow executes all eight required jobs and uploads both review artifacts; independent downloaded ZIP/payload checksums and contents pass on 11 October 2026 (run 38137917071).
- [x] Final `PR1_CERTIFICATION_REPORT.md` provides all 18 requested findings and explicit PASS/FAIL, YES/NO and remaining blockers.

Run the existing **AIMS CI** using workflow dispatch to execute Browser Release Certification and Desktop bundled startup. Set its `build_review_artifacts` boolean only when the repository owner has authorized review uploads; it defaults to false. Both artifact jobs depend directly on all eight certification jobs. Before the new workflow is merged into the default branch, dispatch this existing CI entry point on the reviewed branch. **AIMS release candidate certification** delegates to the same implementation with certification and artifact building enabled. The separate signed desktop publishing workflow is not triggered by PR1 automatically. Do not certify from red CI, an untested restore, reconciliation mismatch, tenant breach or broken desktop startup.
