# PR3 real-company pilot report — preparation status

Prepared 2026-10-16. Real operational evidence has not been supplied. Automated fixture tests validate the preparation tools and do not certify any real-company outcome. No live company import, migration, password reset, deployment or failure drill has been performed. See [pilot operating guide](pilot/README.md) and [user release notes](pilot/RELEASE_NOTES.md).

| Required section | Current result |
|---|---|
| 1. Pilot duration | Not started; 0 observed real-company days. Target 8–12 weeks after agreed cutover. |
| 2. Real users/roles | Company/installation, actual owner, sales, warehouse, purchasing and accountant roster pending. Existing demo account names are not adopted as real pilot identities. |
| 3. Workflows activated | None. Draft planning supports A inventory → B sales/debt → C purchasing → D receiving/shipments → E finance → F intelligence. Existing permission and approval boundaries remain authoritative. |
| 4. Imported master data | None. CSV mapping templates and bounded non-mutating preflight prepared; accepted/rejected/warning/duplicate/missing-field accounting is available. Real source files and approved bulk workflow selection pending. |
| 5. Inventory reconciliation | NOT VERIFIED. Opening stock, authoritative units/costs/warehouse/bin and a real count/source comparison pending. Automated ledger tests are not a physical count. |
| 6. Finance reconciliation | NOT VERIFIED. Source receivables/advances/AP/cash/bank/valuation and independent accountant/owner approval pending. No balances invented or posted. |
| 7. Adoption | NOT MEASURED. Collection defaults off and requires one selected active, attested company and opt-in. Visible-navigation DAU, role/day and bounded endpoint aggregates are prepared. |
| 8. Data-readiness scorecard | Prepared for sales, inventory, procurement, supplier, shipment, customer, finance and forecasting. Missing days remain unknown, denominators are explicit, externally unreconciled coverage remains null. Data gaps include remediation responsibility and existing workflow links. |
| 9. Forecast readiness | NO real-company qualification evidence. Existing promotion gates/artifact integrity are reused without training or lowering thresholds. Live overlapping windows are not pooled into invented MAE/WAPE. |
| 10. Supplier intelligence readiness | NO real-company qualification evidence. Existing supplier eligible-history/holdout gates remain unchanged; completed POs are not automatically eligible training rows. |
| 11. Shipment intelligence readiness | NOT VERIFIED. Actual PO/cargo/route/milestone/arrival/receipt links and provider freshness need actual shipment trials. |
| 12. Assistant quality | NOT MEASURED. Actual intent/entity/clarification/tool/unsupported-answer and correction trials must be collected using existing feedback and new reviewed trial records. |
| 13. Automation/Action Center usefulness | NOT MEASURED. Actual useful/ignored/false/duplicate outcomes pending. Only low-risk notifications/review tasks are recommended initially; no new financial/stock automation enabled. |
| 14. Performance | PR3 automated validation recorded below. Real p50/p95, user task times, payloads and queue delay remain NOT MEASURED. PR2's unmet performance targets remain visible in its report. |
| 15. Server resources | NOT MEASURED for a real pilot. Actual CPU/RAM/disk/DB/Redis/queue and multi-day database growth observations pending. Synthetic fixture growth is not substituted. |
| 16. Backup/restore drill | NOT TESTED with actual company data. Portable pilot evidence restore is guarded and historical; separate real-company restore/integrity comparison and timing still required. |
| 17. Feedback themes | No real employee feedback yet. Bilingual page feedback, safe context/reference, owner priority/status/resolution and append-only reviewed trial records prepared. |
| 18. P0/P1/P2 issues | No real pilot issue assessment is possible before enrollment. P0/P1 corruption/security/daily-work blockers need immediate owner response; P2 friction is batched. Legacy CSV/XLSX and unsupported bulk workflow limits are documented. |
| 19. Measurable business outcomes | NONE measured. Baseline source metrics, comparable observation period and accountant/owner review are required before any savings, accuracy, collection or time improvement claim. |
| 20. Next development area | UNDECIDED pending actual 30/60/90-day reviews. No new intelligence version or next phase started. |

## Explicit decisions

“NO” below means the required real operational evidence has not been established; it does not assert observed corruption or a failed real trial.

| Decision | Result |
|---|---|
| Real company pilot completed | **NO** |
| Inventory integrity maintained | **NO — not verified in real operation** |
| Financial integrity maintained | **NO — not verified in real operation** |
| Real backup restore tested | **NO** |
| Routine workflows usable without developer | **NO — real employee trials pending** |
| Ask AIMS useful with real company data | **NO — not measured** |
| Forecast models production-qualified | **NO — real-source history, completed independent windows, existing gate/promotion evidence pending** |
| Supplier models production-qualified | **NO — real eligible supplier outcomes, validation and promotion evidence pending** |
| Action Center trusted/useful | **NO — real user outcomes pending** |
| Performance within PR2 envelope | **NO — actual installation not measured; PR2 already documents unmet targets** |
| Critical pilot blockers remaining | **LIST:** company/installation; actual roles/permissions; source files; cutover/calendar; reconciled opening stock/finance; independent reviewers; verified backup and separate restore; actual operational observation period. |
| Ready for primary operational use | **NO** |

## Preparation validation

Preparation is isolated on `codex/pr3-real-company-pilot`, based on PR2 `e3388807`. [Draft PR #3](https://github.com/AnisNeziri/Lab2/pull/3) targets the PR2 branch. The original checkout remains clean on `codex/pr1-release-certification` at `e9581ea6`, with no PR3 source, live migration, import or credential changes. Its database and web services were restarted and health-checked. The disposable MariaDB test server was stopped separately.

| Preparation check | Observed result and scope |
|---|---|
| Local complete backend suites | SQLite: 607 passed, 1 conditional Redis skip, 5,323 assertions; MariaDB: 607 passed, 1 conditional Redis skip, 5,324 assertions. These complete runs preceded the final collection/date/unknown-period guards and security dependency patch. |
| Final pilot guards | 18 focused tests / 124 assertions passed on both SQLite and MariaDB after the final guard changes. |
| Hosted backend and Python | [AIMS CI #31](https://github.com/AnisNeziri/Lab2/actions/runs/38933923677), source `c1d377d1`: SQLite 608 passed / 5,329 assertions and MariaDB 608 passed / 5,330 assertions, each with 1 conditional Redis skip. The 16 forecasting/supplier tests and 8 optimizer tests passed. |
| Frontend | 89 local Node tests and production build passed. Hosted frontend unit tests, dependency audit and build passed. Existing large-bundle advisory remains. |
| Local browser release suite | Initial run: 98 passed, 4 failed. A document workflow reached its 30-second whole-test limit during concurrent backend suites; the late route sweep hit `net::ERR_NO_BUFFER_SPACE`, followed by a retained Albanian preference and a 240-second visual-sweep timeout. Fresh reruns on `c1d377d1`: 12 passed, including all 4 pilot cases, the document case (21.4 seconds) and the entire route/theme/hover group; the single long visual review again reached its 240-second whole-test limit. This remaining local test reliability limitation is retained for DR0. No assertion or timeout was weakened. The separate 108-view workspace audit completed with zero page errors. All 102 browser cases passed in hosted CI. |
| Hosted browser | Smoke and all 102 release tests passed on `c1d377d1`, with no reported failures or flaky tests. The earlier run exposed an exact wrapping-label selector race in the new staff trial test. A minimal reproduction showed that options change label text while the control's accessible name stays stable; `c1d377d1` uses that name and also verifies the page heading. |
| Linux deployment | Hosted Nginx, supervised worker and scheduler checks passed on `c1d377d1`. |
| Windows bundled startup | Hosted certification passed on `c1d377d1`: bundled production dependencies, local assets/Python, fresh SQLite, recovery login, three workers/scheduler, backend crash/restart and idempotent upgrade. All desktop tests passed with the bundled resources. |
| Upgrade/recovery | Hosted independent populated upgrade and recovery passed on `c1d377d1`, using the user-approved `5db86866` baseline. This fixture drill does not establish a real-company restore. |
| Performance regression guards | [PR2 bounded-read CI](https://github.com/AnisNeziri/Lab2/actions/runs/38933732277) passed on `c1d377d1`. Eight local destructive-generator negative probes also passed against disposable fixtures. |
| Dependency security | The first hosted run found [CVE-2026-108082](https://symfony.com/cve-2026-108082). Only `symfony/http-foundation` was updated, from 7.4.16 to 7.4.21, including the vendor's whitespace-separator follow-up. The locked production Composer audit passed locally and in CI. The superseded run was canceled; the audit gate remains enabled. |

The verified source is `c1d377d104de3bddd224bf9c27b4aeb0758da7ab`: application changes through `675ac709`, single-package security lockfile update `3b58a626` and browser selector correction `c1d377d1`. All eight mandatory hosted jobs passed. Optional review packaging/candidate jobs were intentionally skipped because artifact packaging was disabled. All results above use automated fixtures; none establishes real-company adoption, reconciled business outcomes or primary operational approval. Nothing is deployed or merged.

Material limits: the CSV preflight is a review, not a bulk posting engine; XLSX requires CSV export. Several completeness measures remain explicitly unknown until the company supplies a denominator or authoritative source review. Histograms provide percentile upper bounds and suppress small samples; they do not replace host monitoring or employee timing. A company or model is never automatically certified from a dashboard. Real rollout remains blocked on the human inputs above.
