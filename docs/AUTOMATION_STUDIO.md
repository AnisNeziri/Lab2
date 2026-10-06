# Automation Studio and Action Center

Automation Studio is a deterministic orchestration layer, not another inventory,
finance, fulfillment, procurement, or approval implementation. It requires no
paid service, cloud account, external scheduler, or internet connection.

## Run locally / self-host

Apply migrations, then run `php artisan automations:setup` to add permissions
without resetting existing roles. Run Laravel's normal `schedule:work` process
(or `schedule:run` each minute on a managed server). `automations:tick` can also
be run explicitly. Existing queue workers process new committed Business Events;
the scheduler catches up if a queue delivery is missed. Rules are disabled by
default and enabling starts at the current event cursor, not the entire history.

The desktop runtime runs a hidden local automation sweep each minute, with no
external process the customer must start. Desktop setup also catches up once.
SQLite and MySQL use the same services.

## Safe authoring

Choose a template or create a rule using WHEN / IF / THEN. Conditions support
bounded nested AND/OR groups, deterministic decimal comparisons, text/list tests,
empty checks and changes when previous values exist. There are no scripts,
expressions, arbitrary SQL, arbitrary methods, or arbitrary entity loaders.

Supported actions are operational tasks, notifications, purchase-request drafts
through ProcurementService, and document-review requests through DocumentService
and the existing Approval Engine. Automation cannot approve the request it
creates, change stock, change credit limits, post journals, or delete business
history. Other suggestions in the original brief are not exposed as fake actions.

Editors save an immutable new version and disable the rule pending review.
Execution rechecks the creator's current role permissions and recipient tenancy.
Disabled/deleted creators cannot run automations. Confidential document access
uses the existing Document Center visibility policy.

## Execution and explainability

Events select rules by company, trigger and enabled state. Execution keys combine
rule, version and Business Event; open task keys also coalesce repeated observations
of the same source. All effects and action receipts for an attempt commit together.
An originating business transaction is already committed before reactions run.
No financial or stock side effects are duplicated by retrying automation.

Failures, skips, blocked runs, bounded retries, timings, condition values and result
links are recorded. Transient database errors have bounded backoff; manual retries
also stop after three attempts. Child events carry correlation, causation and depth;
depth four is blocked and surfaced in System Integrity. There are no delayed-action
jobs or arbitrary WAIT blocks in this release.

Simulation evaluates at most 100 compatible events from 30 days and performs no
business actions. Available immutable event fields take precedence; missing fields
may use the current authorized source. This limitation is shown in the interface.
Missing sources never match. Simulations themselves are audited.

Daily, weekly and month-end sweeps support stock thresholds, overdue customer
balances, credit warnings, late purchases, ETA changes/delays, document expiry,
integration health and overdue-task escalation. AIS absence is never interpreted
as a failed shipment. Escalation tasks do not escalate themselves. Source recovery
closes the corresponding overdue, document, integration or shipment task and
records a structured outcome.

## Action Center

The center combines authorized operational tasks with live links to approvals,
stock suggestions, overdue purchases/customers, expiring documents, order attention,
operational/accounting exceptions and failed automation runs. Preview lists are
bounded; open the authoritative module for its complete list. Customer previews
check up to 100 overdue candidates; most other areas show up to 25 issues.

Tasks can be assigned, started, completed or cancelled. Outcomes and edits are
audited. Product/customer/purchase context includes related task links. Dashboard
summary, global search and Ctrl+K reuse the same permission boundaries.

## Portable backup

The encrypted `automations` backup module includes definitions, immutable versions,
tasks, executions and relevant Business Event history. Restore disables rules,
disables pending/failed replay, remaps user assignments, and removes invalid local
result links. A source excluded from a selective backup remains historical context,
not a live link to an unrelated record. No queue jobs are exported or replayed.
Conflicting immutable versions are rejected instead of silently overwritten.

## Boundaries

Only registered triggers/actions are supported; no ML or paid integrations are
included. Future model-neutral signals can be added to the registry using typed,
reviewed metadata. The Tool Layer remains read-only; task/rule writes go through
the permission-checked application APIs. There is no unrestricted agent executor.

## Verification — 28 September 2026

- Full SQLite suite: 282 passed, 1 existing Redis-dependent check skipped
  (2,737 assertions).
- Full isolated MySQL suite: 282 passed, the same Redis-dependent check skipped
  (2,737 assertions). No live company database was reset by the test runs.
- Full Chromium browser suite: 65 passed, including the new automation journey
  and encrypted full-backup replacement. Responsive checks cover 390, 768,
  1024, 1366 and 1920 pixels; English/light and Albanian/dark are exercised.
- Frontend unit checks: 8 passed. Production build passed; the existing PDF and
  3D warehouse chunks still generate Vite's bundle-size advisory.
- Desktop resources rebuilt from current source. Portable PHP + SQLite setup
  and local API startup passed, without a cloud backend.
- The additive automation migration and permissions setup completed on the
  existing local web database; business data was not reset.

The backup regression discovered during this work is also covered: full restore
detaches the target company's self-referencing account hierarchy inside the
transaction before replacement, then restores the archived hierarchy. Foreign-key
checks stay enabled and other companies' accounts stay untouched.

The focused automation tests exercise real stock, order confirmation, customer
overdue, document review/expiry and delayed-shipment sources, task outcomes,
manual-task escalation, domain permission revocation, tenant isolation,
read-only simulation, immutable versions, duplicate prevention, rollback/retry,
approval self-decision protection, and encrypted restore. Not every registered
trigger/action combination has its own dedicated browser scenario.
