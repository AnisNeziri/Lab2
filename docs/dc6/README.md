# DC6 — Business automation and operational coordination

Implemented on `main` in the existing checkout. HEAD: `fed921ad319d5f360281f11df67fbb5b96e1f656`. Changes remain uncommitted for normal review; no branch, worktree, push or DC7 was created.

## Completion report

| Area | Result |
| --- | --- |
| 1. Architecture | Extended the existing AutomationService, AutomationRegistry, AutomationScheduler, BusinessEvent consumer, OperationalTask and Action Center. No second engine. Existing business authorities remain unchanged. |
| 2. Events | The existing server registry remains the canonical catalog, exposed by `/automation-catalog`. Existing inventory, order, shipment, procurement and intelligence event names are reused. Added bounded current-state observations for payroll review/unpaid settlement, employment-term expiry, fiscal-result review and an optional operating brief. Receipt/payment events also recheck related open issues. Company/source identifiers, correlation and original event references remain in the durable ledger. |
| 3. Rules | Company-scoped draft/active/paused/archived lifecycle; immutable definition versions; explicit activation; existing bounded AND/OR conditions and read-only recent-event preview retained. Templates expose functional queues, due periods and escalation policy. Editing returns a rule to draft. |
| 4. Action safety | Four existing allowlisted actions: in-app notification (level 1), task creation, document approval request and draft PR preparation (level 2). Registry metadata identifies permission, authority, input fields, idempotency and audit. Levels 3/4 are not executable rule actions. No configured class, SQL or arbitrary function execution. |
| 5. Action Center | Named owner, functional team queues, Assigned to Me, exclusive claim, due/priority explanations, source links, original/current evidence, readable task audit history and mobile review. The on-demand Today in AIMS brief reuses the permitted inbox; managers see company work, staff see their work, selected teams include eligible queue work. |
| 6. Orchestration/approvals | A product task can prepare a qualified V11 recommendation, show a structured confirmation and create one **draft** through InventoryPlanningService and ProcurementService. The persisted task outcome records preparation, waiting confirmation, stale review, draft handoff and source recovery. Existing submit/independent approval/RFQ/PO/receipt workflows continue normally. |
| 7. Deduplication/recovery | Stable company/rule/action/source issue keys survive rule revisions and acknowledgement. Current source recovery completes the original issue and releases its key; later recurrence creates a distinct occurrence. Original trigger evidence, human notes, promise and draft links remain. Repeated delivery and repeated confirmation reuse existing results. The older configured draft action also retains its original PR after conversion/cancellation until the source condition recovers. |
| 8. Retry/unknown result | Transactional results, durable execution keys, resumable queued work and bounded infrastructure retries. Deadlock/lock timeout can retry; other failures need review. An uncertain DB result blocks replay, including later events for the same rule/source, until an authorized reasoned verification. Verification never changes a payment/fiscal record. |
| 9. Customer/Sales | Overdue and credit review tasks reuse authoritative customer exposure. A payment promise changes follow-up work only. Missed promises escalate the same issue; actual payment/AR recovery resolves it. Recorded non-reversed payments satisfy a partial promise even if other AR remains. Existing order/fulfillment and customer-intelligence triggers remain available. |
| 10. Inventory/Purchasing/Shipment | DC5 inventory conditions, canonical availability, PO/shipment overdue and existing V11 qualified recommendations are reused. Trigger/current evidence remain distinct. A changed plan, changed rule or expired confirmation requires a fresh review. No automatic supplier commitment, transfer or stock adjustment. |
| 11. Finance/Tax | Existing accounting exception and financial-intelligence triggers route review work. Fiscal failed/unknown/review states can create one source-linked review task. No corrective journals, payment, declaration or fiscal submission action is registered. |
| 12. Workforce | Optional calculated/blocked payroll review, posted-unpaid settlement review and employment-term expiry templates read existing workforce states. Payroll goes to Finance and term review to HR. No employment, compensation or payroll posting authority changed. |
| 13. Ask AIMS | Added typed read-only `get_operating_brief` and `get_task_review` tools, including a server-filtered overdue Sales follow-up view. Contextual task explanation includes current evidence, historical trigger and qualified recommendation. English/Albanian presentation and existing finance/inventory/workforce tools remain. Draft creation requires structured source review and explicit confirmation. |
| 14. Health/pause | Authorized Automation Health shows rule states, recent failures, retries, unknown outcomes, pending approvals and task metrics. Reasoned company-wide/category pause and per-rule pause serialize against execution. Manual source modules continue to operate. Company execution-ledger queue counts are distinguished from unavailable raw worker telemetry. |
| 15. Performance | Existing after-commit event delivery plus minute scheduler fallback. Per rule: at most 100 source candidates with a durable cursor, 100 recovery tasks, 200 event deliveries and 20 retries per tick. Stock scans reuse batch snapshots. History/tasks are paginated; reviews/audit reads are bounded. UI reuses existing coalesced refresh and 15-second fallback, with no new polling timer. |
| 16. Validation | 50 focused backend tests / 728 assertions; final DC6/Ask AIMS controls 25 / 286; 12 frontend tests; production build; one Chromium Action Center journey; real two-process MariaDB event/claim races; populated additive upgrade. Details below. |
| 17. Limits | Controlled pilot scope and operational prerequisites below. No claim of certified autonomous financial, fiscal, payroll or external execution. |

## Verified journeys

**Replenishment:** authoritative inventory change → one Purchasing task → claim → original/current evidence → qualified V11 plan → preview → changed-stock stale confirmation rejected without PR → new review → explicitly confirmed single draft → submit → self-approval rejected → independent approval → RFQ/quote/award → PO → receipt → original task resolves. Repeated delivery/confirmation creates no extra effect; a later shortage creates a new issue. Browser covers claim, contextual Ask explanation, explicit draft creation, mobile review and pause/manual-workspace access.

**Collections:** real overdue debt → one Sales task → promise → time passes → same task escalates → revised partial promise and actual partial payment → fulfilled promise does not escalate despite remaining AR → remaining payment → AR becomes zero and the original task resolves. History remains; no new collection task.

## Required safety results

| Check | Result |
| --- | --- |
| Existing authoritative business services preserved | YES |
| One automation engine used | YES |
| Business events canonicalized/reused | YES — existing catalog and names retained |
| Duplicate task/action prevention verified | YES |
| Resolved conditions close correctly | YES |
| Recurring future occurrences work | YES |
| High-risk actions require human approval | YES — unavailable as automated actions; existing source approval controls remain |
| Prepared actions revalidate stale state | YES |
| Worker retries idempotent | YES — durable replay tests and two-process MariaDB delivery |
| Automation cannot bypass accounting authority | YES |
| Automation cannot bypass inventory authority | YES |
| Automation cannot bypass payroll authority | YES |
| Automation cannot bypass fiscalization authority | YES |
| Ask AIMS cannot silently execute high-risk operations | YES |
| Two-company isolation verified | YES |
| Global/company automation pause works | YES — global within the company, category and rule scopes |
| Manual ERP operation remains available if automation is paused | YES |
| Ready for controlled automation pilot | YES — for the bounded review/draft workflows described here |

## Tests actually run

- SQLite: `artisan test --filter='DC6OrchestrationTest|AutomationStudioTest|ProcurementApprovalTest|ApprovalAtomicityTest|DC5InventoryControlTest|IntelligenceAssistantTest' --compact`: **50 passed, 728 assertions**. Final DC6/Ask AIMS rerun after formatting/control refinements (`DC6OrchestrationTest|IntelligenceAssistantTest`): **25 passed, 286 assertions**.
- Final engine/draft-guard regression (`AutomationStudioTest|DC6OrchestrationTest`): **19 passed, 297 assertions**, including PR conversion and rule revision while the original shortage remains unresolved.
- MariaDB 11.8.9/InnoDB, disposable port 3315: `tests/Support/dc6-concurrency.php`: **PASS**. Two processes delivered one event concurrently: one execution/task. Two people claimed concurrently: one owner, one conflict.
- Frontend: `node --test tests/px1-workflows.test.mjs tests/intelligence-assistant.test.mjs tests/assistant-presentation.test.mjs`: **12 passed**.
- `vite build`: **PASS**. Existing PDF/3D chunk-size warnings remain.
- `playwright test --config=playwright.dc6.config.js --project=chromium --retries=0`: **1 passed**, isolated synthetic company/database and ports 8016/4179.
- Populated pre-DC6 SQLite clone upgrade: **204 tables / 1,692 existing rows preserved**. No `migrate:fresh`.
- Local MariaDB additive migration: full SQL backup `backups/before-dc6-20261021-d3a7647a.sql` (8,792,667 bytes); original-column hashes and row counts unchanged in **17 business/automation tables**. No business seeds or rule activation. Backend `/up` and frontend `/login` return 200.
- Earlier DC5 work was snapshotted before DC6: **24 of 32 files remain byte-identical**, eight shared integration files were extended. Existing DC5 focused tests pass. Fourteen core/fiscal boundary files match HEAD. Evidence is in ignored `output/dc6-preservation.json`.

Raw local evidence: `output/dc6-focused-backend.log`, `dc6-final-controls.log`, `dc6-final-engine.log`, `dc6-concurrency.log`, `dc6-upgrade.json`, `dc6-local-upgrade.json`, `dc6-frontend-tests.log`, `dc6-build.log`, `dc6-browser.log`, `dc6-browser-report/`, `dc6-task-mobile.png` and `dc6-automation-health.png`. These local artifacts are intentionally not source-controlled. Portable result summary: [VALIDATION.json](VALIDATION.json).

## Pilot limits and operations

- Enable only reviewed templates after preview. Existing live enabled states are preserved; newly saved/template rules remain disabled until explicitly activated. Use the normal queue worker when queueing is configured and the existing scheduler (`automations:tick`/`schedule:run`) for scheduled conditions/recovery. Local development currently uses synchronous event delivery; no new background scheduler was silently installed.
- A PR remains a draft. Supplier commitments, money, stock, payroll and regulated submissions stay in their normal authorized modules. The task links to the PR; downstream commercial documents retain their existing source lineage rather than being copied into another workflow graph.
- Health reports company execution-ledger counts, not global raw worker job counts. Missing heartbeat is unknown health. Deadlock and interrupted-connection handling was tested with real concurrency and a simulated uncertain DB exception; no external payment/fiscal service was called or certified.
- Finance/tax/payroll/contract templates are source-state review adapters. Their full external regulatory/payroll journeys were not recertified in DC6. Some business examples (quote expiry, email bundles, arbitrary approval routing, outcome causality and a general graphical workflow designer) remain outside this bounded implementation. Existing configured approval infrastructure is reused.
- Dry-run examines up to 100 recorded events from 30 days; it is not a historical backtest of every company record. Scheduled scans rotate bounded candidates; they do not imply immediate evaluation of an entire large tenant. No large-scale load or PM3/PR recertification was run.
- The Windows development PHP server serializes page-bootstrap requests. Browser validation waits for the actual bounded inbox/stock response rather than assuming every initial page load completes within ten seconds; this is not a production latency benchmark.
- Notifications are in-app and deduplicated. The optional scheduled operating brief is a reminder linking to the current permitted inbox; no external messaging service or inferred causal learning was added.

DC7 has not been started.
