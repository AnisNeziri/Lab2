# AIMS PR1 certification report

Assessment date: **11 October 2026, Europe/Budapest**. Release: **1.1.1-rc.1, uncertified**. Application runtime: `13635712a1d7d475408e4073f3b58bd0863c2093`. Final certification and uploaded artifact source: `f84e2fb9517ef2adc952c5289797dae69b17fad3`; frontend/backend/database application source is unchanged between them. The owner selected **`5db86866`** as the supported upgrade baseline; there are no release tags. Documentation-only commits after this assessment do not change the tested runtime.

The earlier PM1–PM5 work was retained. This continuation changes certification fixtures/interactions, compatible dependency locks, build configuration and portable Python alias handling; it does not reset or revert the earlier application source. Customer deployment, release publishing, PR2 and V13 were not performed.

## Explicit release gates

| Required statement | Result |
| --- | --- |
| SQLite full suite | **PASS** — 577 tests discovered; 576 passed, one conditional live-Redis test skipped; 5,015 assertions |
| MariaDB full suite | **PASS** — 577 discovered; 576 passed, one conditional Redis skip; 5,016 assertions |
| Browser Release Certification | **PASS** — all 98 tests, no retries or skips in the final run |
| Windows Desktop Startup | **PASS** — final Windows runner, bundled PHP/Python and SQLite |
| Fresh installation verified | **YES** — empty DB migration and isolated bundled Windows startup; installer/licence qualification remains separate |
| Upgrade from previous supported version verified | **YES** — populated `5db86866` snapshot, backup before migration, preserved records and login |
| Encrypted backup verified | **YES** |
| Independent restore verified | **YES** — separate empty Installation B, SQLite and MariaDB |
| Inventory reconciled after restore | **YES** |
| Finance reconciled after restore | **YES** |
| Attachments restored | **YES** — 31 files, checksums and record associations |
| Tenant isolation verified | **YES** — two-company authenticated route matrix plus regression suites |
| Critical security issues remaining | **NONE identified in the focused application review**; remaining build-only advisories are listed below |
| Production Release Candidate | **NO** — remaining operational gates are incomplete |

## 1. Supported deployment modes

Server target: Ubuntu 24.04, Nginx, PHP-FPM, MariaDB/InnoDB, Redis, local Python, database queue workers and systemd. Windows target: bundled PHP/Python, local compiled React assets, SQLite, file cache/sessions and database queues; offline operation needs no XAMPP or system Node/Python. MariaDB was exercised on local 10.4.32 and isolated CI 10.11/11.x; MySQL 8 requires separate populated certification before claiming support. Other desktop platforms, ARM and network-share SQLite are outside scope.

## 2. Environment requirements

Pinned toolchain: PHP 8.3.35, Composer 2.9.8, Node 22.14.0, npm 10.9.2, Python 3.12.2, NumPy 2.3.5, SciPy 1.16.2 and Electron 41.10.7. Composer/npm lockfiles and official runtime checksums are used. Readiness validates required PHP extensions, DB/version, required Redis, Python/packages, directories and permissions, APP_KEY, backup key, explicit origins/timezone/locale, queues and enabled SMTP. Minimum disk reserve is 512 MB; the full archive has a 512 MiB raw-data ceiling and uses a 2 GiB CLI memory limit.

## 3. CI status

The final approved review dispatch is [AIMS CI run 38137917071](https://github.com/AnisNeziri/Lab2/actions/runs/38137917071), for `f84e2fb9517ef2adc952c5289797dae69b17fad3`. **PASS — all eight required certification jobs, both artifact jobs and the candidate summary succeeded.** All 98 browser tests passed in 16.1 minutes without retries or skips. The run independently regenerated populated SQLite/MariaDB recovery and ran Windows startup plus the new executable-symlink packaging regression. [Scheduled PR checks also passed](https://github.com/AnisNeziri/Lab2/actions/runs/38137909920). The conditional backend Redis round-trip skip is recorded explicitly; the separate Linux job exercises required Redis, its outage and restoration.

Diagnostic history remains visible: [run 38134281089](https://github.com/AnisNeziri/Lab2/actions/runs/38134281089) blocked builds on a warehouse interaction timeout. A bounded 30-second physical-click budget and loaded-editor assertion resolved it without changing application source. [Run 38136102823](https://github.com/AnisNeziri/Lab2/actions/runs/38136102823) passed all eight certification jobs and uploaded the web archive, but Windows packaging rejected a runner-specific Python executable symlink. The packaging script now copies its bytes; the final run verifies that behavior and builds both review artifacts from the same passing commit.

## 4. Upgrade result

PASS locally and in final CI for populated SQLite and MariaDB. The fixture checks the baseline's exact migration set, creates a verified backup before forward migrations, preserves critical record hashes/settings/preferences/intelligence associations, authenticates the original owner and exercises representative reads. Populated MariaDB additionally commits stock movement, payment, goods receipt, journal posting and an order through dispatch; retries post once. Final CI independently regenerated the representative 90-day fixture using archived baseline code and passed both engine-specific checks.

## 5. Migration findings

Fresh and populated forward migrations preserve data on both engines. PR1 adds token-version invalidation and removes MariaDB's implicit `ON UPDATE CURRENT_TIMESTAMP` behavior while retaining values; DB sessions use UTC. The historical administrator-reset migration no longer overwrites chosen passwords. Production upgrade/DR scripts use forward migrations and pre-upgrade backups; `migrate:fresh` appears only in guarded disposable tests. Schema rollback requires compatibility review or recovery into a separate empty target.

## 6. Desktop clean-start result

The fresh bundled startup covers database initialization, unique recovery credentials, first password change, owner login, local Python and all workers, backend termination/restart, an idempotent subsequent migration and child cleanup. Local tests also cover normal/forced parent exit and duplicate-worker prevention. Final remote result: **PASS**. The uploaded NSIS artifact is an unsigned review build; a real fresh-VM installer, Microsoft VC runtime prerequisite, licence activation, update entitlement and signed customer delivery are not certified.

## 7. Server deployment result

PASS in disposable Ubuntu CI: actual Nginx/PHP-FPM/MariaDB/Redis/systemd, config/route cache, HTTPS redirect, SPA/API routing, private storage and environment-file denial, security headers, readiness and a verified scheduled backup. Redis loss returns readiness 503 while authenticated core product reads remain available; DB loss returns an explicit 503. TLS uses an isolated self-signed fixture. Public-domain Let's Encrypt renewal and an actual host reboot were not exercised; service restart is reported separately. The staged Linux release-switch script and the HTTP upload-size boundary require an additional deployment drill; this CI job does not invoke the complete server upgrade script.

## 8. Backup result

PASS: complete database plus actual private/public/document bytes, manifest metadata and checksums, authenticated AES-256-GCM encryption, wrong-key/corruption rejection and atomic publication after verification. Backup passphrase and original APP_KEY must be independently escrowed. Locks prevent overlap. Retention keeps at least two verified scheduled copies and protects manual copies; focused tests verify pruning and preservation of the last success after failure. CI exercises the real systemd backup unit. Actual off-server transport/retrieval and escrow recovery remain unverified.

## 9. Independent restore result

PASS for A → distinct empty B with both SQLite and MariaDB. Restore checks compatible version/engine, authenticates before activation, runs forward migrations and verifies critical records and files. Populated targets and unsafe paths are rejected; incomplete restoration blocks startup. Old tokens/sessions/jobs are invalidated, external integrations and automations disabled, and channel credentials revoked pending explicit review.

## 10. Reconciliation after restore

For the preserved local PM3 company, inventory quantity is **16,058.020** on A and B. All operational/GL differences are **0.00**: receivables **54,792.59**, customer advances **2,613.54**, payables **0.00**, supplier advances **162,246.98**, inventory valuation **94,175.62**, cash/bank **186,381.28**. Five users, ten document records and 31 associated files survive. Post-write populated MariaDB again reconciles all six controls. System Integrity honestly retains attention for existing operational exceptions, stale observations and B's need for a new local backup; there are zero critical integrity findings.

## 11. Worker supervision and interruption recovery

Default, optimizer and simulation workers have explicit timeout/retry limits, version-specific heartbeats and supervised restart. Deployments stop writers before migrations and start workers against the new code afterward. Desktop process registries guard PID reuse and clean up children after normal/forced exit. Four tests force-kill a process after an authoritative stock/payment/journal/receipt SQL write inside a real transaction: hashes of twelve critical tables remain unchanged, SQLite integrity passes and foreign-key checks are empty. This simulates interruption, not a physical power cut. Real Linux host reboot remains a release gate.

## 12. Health/status improvements

`aims:production-check` supplies actionable failures; `/api/readiness` exposes safe status and 503 for missing required services. Administrator diagnostics include release/environment/DB/worker/integrity/backup summaries without keys or private files. Backup health distinguishes creation, verification, failure and independent restore. Optional mail/providers are distinguishable from broken required services. Daily log retention is fourteen days.

## 13. Security findings

Fixed: tenant authentication before route model binding, stale access/refresh/reset token acceptance, reset replay/expiry, insecure recovery defaults/password overwrites, sensitive provider exceptions and realtime payload logging, and MariaDB timestamp mutation affecting security metadata. Existing permissions, upload validation/private download, CORS, rate limits and signed/bounded/idempotent webhook paths remain covered by the suites. Restoration prevents automatic replay of external actions.

The build pin now uses patched Composer after its [credential-disclosure advisory](https://github.com/composer/composer/security/advisories/GHSA-f9f8-rm49-7jv2); Electron is patched for the [sandbox inheritance advisory](https://github.com/electron/electron/security/advisories/GHSA-gr2m-v5gq-v685). Targeted compatible locks update Laravel 12.69.3, CommonMark 2.10.3 and Flysystem 3.36.0. Final Composer production and frontend/desktop `npm audit --omit=dev` report **zero advisories**. These checks do not certify every operating-system dependency.

Remaining dependency findings: frontend all-dependency audit flags seven affected build packages (five high, two moderate), rooted in unpatched `braces` and older selector parsing; desktop all-dependency audit flags eight moderate build packages. These counts include transitive propagation of the same advisory. Builds consume committed, trusted source/configuration; these dependencies are not shipped as customer Node tooling. The separate Electron binary is explicitly patched. Do not accept untrusted build patterns, CSS or proxy configuration. A Tailwind 4 migration was not forced into PR1; these findings remain visible for review.

## 14. Tenant isolation findings

PASS: freshly authenticated Company B cannot fetch Company A products, customers, suppliers, orders, purchase orders, shipments, documents, financial records, decisions or simulations (404). Forged workspace widget settings fail validation (422). Owner access succeeds first, proving records exist. The focused test also attempts a cross-company product update and verifies unchanged data. The matrix runs after independent restoration on both engines.

## 15. Performance baseline

Final nine-workspace measurement: **PASS**. The harness uses the normal minified build, a verified PM3 restore, three warm actions, required HTTP completion and two animation frames. Dashboard covers saved layout/grid readiness; timings do not promise completion of every lazy widget or secondary request. Single-process loopback PHP, local machine load and synthetic E2E configuration differ from Nginx/FPM production. This is a baseline, not a load test or SLA.

| Workspace | Median milliseconds | Maximum milliseconds |
| --- | ---: | ---: |
| Dashboard | 3,882.43 | 4,896.95 |
| Order Hub | 2,116.35 | 4,398.86 |
| Product detail | 4,882.00 | 4,882.83 |
| Inventory planning | 2,382.61 | 4,417.33 |
| Shipment detail | 3,465.57 | 3,866.24 |
| Financial intelligence | 1,683.07 | 1,683.41 |
| Customer intelligence | 1,683.20 | 1,782.33 |
| Global search | 1,752.29 | 2,482.03 |
| Ask AIMS | 798.98 | 868.23 |

## 16. Known release limitations

Required operational evidence remains: actual Linux reboot; public-domain certificate renewal; staged Linux release switch and HTTP upload boundary; off-server archive retrieval with independent secret escrow; fresh Windows installer/licence/update/signing qualification. The explicitly authorized review-artifact workflow and independent downloaded-file verification passed on 11 October 2026 in run 38137917071; both artifacts are available for review. Other limits: same-engine restore, one operating timezone per installation, archive size ceiling, partial module-specific customer exports, insufficient predictive outcome history, unpromoted supplier ML, provider-dependent live carrier/AIS behavior and uncertified tax/fiscal integrations.

## 17. Exact tests and certification

| Invocation/evidence | Result |
| --- | --- |
| `php vendor/phpunit/phpunit/phpunit --log-junit ../output/pr1-sqlite-patched-final.xml` | PASS — 577 discovered, 576 passed, 5,015 assertions; conditional live Redis round-trip skipped |
| CI MariaDB `php artisan test`, with isolated MariaDB service | PASS — 577 discovered, 576 passed, one conditional Redis skip; 5,016 assertions |
| Frontend `npm test` | PASS — 87 tests |
| Frontend `npm run build` and desktop asset preparation | PASS — normal minified build; chunk-size warnings retained |
| `npm run test:e2e:smoke` in CI | PASS — 10 tests |
| `npm run test:e2e:release` in CI | PASS — all 98 tests in 16.1 minutes, no retries or skips |
| Patched local Playwright analytics/optimizer/simulation/workspace flows | PASS — 7 tests |
| Additional local physical `handle.dragTo` check against the adjacent dashboard widget | PASS — actual pointer gesture preserves the existing handle interaction; temporary diagnostic removed after completion |
| Windows `npm run test:startup` and `npm test` in CI | PASS — startup plus four process/publishing safeguards and the executable-symlink packaging regression |
| Python `unittest discover -s ml -p 'test_*.py'` | PASS — 16 forecasting tests in final CI |
| Python `unittest discover -s ml/tests -p 'test_*.py'` | PASS — 14 customer/optimizer/simulation tests locally |
| `php -d memory_limit=2048M scripts/certify-installation.php` | PASS — populated SQLite upgrade and independent restore |
| `php -d memory_limit=2048M scripts/certify-mariadb-installation.php` with guarded scratch DBs | PASS locally and in final populated CI |
| `node scripts/certify-interrupted-write.cjs` | PASS — four real transactional interruption cases |
| `bash scripts/certify-linux-server.sh` on guarded disposable CI | PASS — Nginx, required-service outages, supervised restart, scheduled verified backup |
| `node scripts/certify-browser-performance.cjs` | PASS — nine production-build timings |
| Composer production audit; frontend/desktop production npm audits | PASS — zero advisories |
| Local unsigned NSIS installer; web source/production-assets archive; SHA-256 inspection | PASS — production-only vendor, patched bundled runtimes, normal frontend without certification bridge; web archive has 1,070 inspected entries |

Uploaded review artifacts, built from `f84e2fb9517ef2adc952c5289797dae69b17fad3` after all eight certification gates passed:

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| [Unsigned Windows installer](https://github.com/AnisNeziri/Lab2/actions/runs/38137917071/artifacts/11702139108) | 181,012,583 | `523828681bc2b4db77d7d80038ad2bd0277daf616e6e32963b9e11b8a61c297e` |
| [Web source and compiled frontend](https://github.com/AnisNeziri/Lab2/actions/runs/38137917071/artifacts/11701964122) | 5,688,554 | `f408be2a72413d7a8a28a8d20a0331baca0f53e5f0073041ef3151abf8a0c5fe` |

Both downloaded ZIPs match GitHub's artifact digests, and both payloads match their uploaded `SHA256SUMS`. Contents inspection confirms `1.1.1-rc.1` remains `uncertified`, normal compiled frontend assets have no certification bridge, and runtime databases/environment secrets are excluded. The Windows installer has production-only Composer dependencies and bundled PHP/Python; Authenticode status is **NotSigned**. The web archive has 1,073 inspected entries. GitHub currently retains these artifacts until **2027-01-09T12:04:54Z**. Earlier local builds and their different checksums remain explicitly labelled as local reference evidence in the JSON summary.

The artifacts are uploaded for review and are not a published customer release. Both build jobs depend directly on all eight certification jobs. `build_review_artifacts=false` is the CI default, so ordinary PR checks do not upload review builds; the standalone RC entry point delegates to the same implementation.

Raw local logs and synthetic snapshots remain ignored under `output/`; they are not committed as customer data. Remote job logs/artifacts are attached to the exact workflow run above. The compact committed evidence summary accompanies this report. The application remains `uncertified` until every required release checklist item is complete.

## 18. Release-candidate recommendation

**Production Release Candidate: NO.** All eight final automated CI jobs passed, and the engineering changes are ready for review in [draft PR #1](https://github.com/AnisNeziri/Lab2/pull/1). PR1 is not fully complete under the requested completion standard: the remaining operational checks must be recorded before customer release. Verified uploaded review artifacts are available; the installer is unsigned and no customer release was published. Use [the operations runbook](PR1_OPERATIONS.md), [the authoritative checklist](PR1_RELEASE_CHECKLIST.md) and [release notes](RELEASE_NOTES_1.1.1_RC1.md) for the remaining qualification steps.
