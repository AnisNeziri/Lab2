# PX1 — Product experience and workflow simplification

Continues from DC4 `ea883f5918aad2ae6095df87327d2f54b2a5c939`, on `codex/px1-product-experience`. The original checkout remains on `main` at `07c66520187db1486f994e9527670b1e7dcbcd24`.

This is an experience pass over existing workflows. It adds no business module, accounting engine, database migration, AI model, or permission grant. All financial mutations still use their existing authorized services, source validation, period checks, idempotency, and audit history. No real company database was migrated or seeded.

Validated implementation: `fd1e1f782938a5418867fcd1213cf8003b41e242`.

Final checks: **38 frontend tests PASS; 27 SQLite backend tests / 352 assertions PASS; 2 Chromium journeys PASS (44.3s and 45.5s); production build PASS (24.2s).** Counts are distinct within each suite, not totals across reruns.

## Completion report

| # | Area | Result |
|---|---|---|
| 1 | Highest-impact workflows | Visible next steps; stock shortage review before order confirmation; immediate customer balances; selected payroll period ahead of history; safer unsaved and in-flight forms. |
| 2 | Page/layout system | Shared `BusinessFacts`, `NextStep`, and `WorkflowSteps`, using existing theme tokens. Booked values remain distinct from forecasts and commitments. |
| 3 | Orders | Total, paid, remaining, fulfillment and delivery remain separate. A presentation hint reuses intake issues, approval, stock availability and the server workflow projection. Existing services revalidate every transition. Returning to the inbox preserves customer/product filters and saved search/page. |
| 4 | Customers/suppliers | Customer debt, overdue and advances appear above policy detail. Secondary actions use the shared keyboard menu. Supplier overview reads the existing posted AP workbench, shows recent PO commitments separately, and links to bills, advance allocation, statements and payments. |
| 5 | Procurement/shipments | PO amount/paid/remaining/ETA and a next step precede detail; secondary actions are grouped. A shipment deep link collapses the creation form. Cargo retains units, allocation and PO receipt distinctions. Logistics editing uses the shared protected dialog and sticky actions. |
| 6 | Accounting | Prioritized review action at the top, respecting the available tabs. Dates persist per company/user session; month-close period selection is URL-addressable. Supplier workbench links open the correct supplier directly. Existing source-owned corrections remain intact. |
| 7 | Payroll/HR | Calculate → Review → Approve → Post → Pay → Close remains explicit. Selected run and notices take priority over history. Recalculation explains approval withdrawal; reversal is secondary. Payroll outcomes and obligations use EN/SQ labels. HR search/page and table density persist. Separate compensation/payroll permissions remain enforced. |
| 8 | Tax/fiscalization | Operator states and counters have clearer EN/SQ copy. Certificate/protocol details are disclosed separately. ATK TEST and the lack of official certification remain explicit. No live fiscal submission was enabled. |
| 9 | Action Center/notifications | Critical issues precede overdue work and approvals. Existing grouping retains occurrence counts and source links. Resolution lineage and notification delivery are unchanged; no historical tasks or alerts were bulk deleted. |
| 10 | Ask AIMS | Conclusion precedes local-language-provider diagnostics. Evidence and limitations remain accessible. Added EN/SQ guides for payroll, employment, VAT and fiscalization; existing authoritative facts and financial action boundaries are unchanged. |
| 11 | Global Search | Employee result labels/icons; payroll, employee and month-close commands; corrected Albanian tax/fiscalization command labels; direct reconciliation destination. Search and destinations remain permission-filtered. |
| 12 | Mobile/tablet | Representative 390px and 768px workflows reviewed. Responsive facts and action bars; controlled, keyboard-focusable payroll table scrolling with a visible hint; fitted dialogs. Detailed payroll/accounting work still benefits from desktop width. |
| 13 | Shared components | Workforce and shipment logistics reuse `WorkspaceModal` / `useDialog` for focus, Escape, backdrop, busy protection and discard review. PO/supplier editing reuses `useUnsavedNavigation`. Row actions, canonical statuses and API validation-error redaction are shared. |
| 14 | Remaining inconsistencies | Some historical/source-provided diagnoses and technical evidence retain their source language. Wide specialist tables scroll horizontally. Existing saved dashboard arrangements remain intact; Reset to Default applies the smaller new default. Existing large PDF/3D build chunks remain. |
| 15 | Validation | See `VALIDATION.json` for actual commands/results and local captures. Two representative browser journeys cover owner/operational/accounting navigation and payroll/HR interactions, including discarded edits and restricted roles. |

## Explicit review flags

These flags describe the reviewed representative core workflows, not an exhaustive certification of every legacy screen. The five-second assessment is a visual business-comprehension review, not a timed external user study.

| Required flag | Result |
|---|---|
| Major core screens pass 5-second comprehension review | **YES** — dashboard, customer, supplier, order, PO, shipment, accounting and selected payroll run |
| Orders understandable without internal architecture knowledge | **YES** |
| Shipment cargo understandable quickly | **YES** |
| Accounting next actions clear | **YES** |
| Payroll next actions clear | **YES** |
| Customer financial position visible immediately | **YES** |
| Supplier position visible immediately | **YES** — subject to the existing finance/purchasing permissions; commitments are separate from posted AP |
| Outside-click/Escape behavior consistent | **YES** — reviewed shared menus/dialogs; dirty forms request discard and busy dialogs stay open |
| Long forms keep primary actions accessible | **YES** — reviewed PO, workforce/payroll and shipment editing |
| EN/SQ reviewed on changed screens | **YES** — representative combinations; source-language limitations above remain |
| Light/Dark reviewed on changed screens | **YES** — representative shared patterns and core captures |
| 390px core workflows usable | **YES** — payroll review/own portal and shared dialogs; wide tables explicitly scroll |
| 768px core workflows usable | **YES** — shipment/cargo and navigation |
| Known dead UI in reviewed workflows | **NONE** — unavailable finance drill-downs are disabled or lead to permitted attention review |

## Evidence and limits

- Seven work-profile presets are checked without granting permissions. Browser journeys use real synthetic admin/owner, manager, HR and staff accounts; sales/warehouse/purchasing/finance layouts use the existing permission-filtered profiles, not newly invented backend roles.
- Synthetic data lives only in `backend/database/px1-preview.sqlite`; the fixture checks both its filename and `APP_ENV=e2e`. Reruns create another synthetic company while preserving earlier data. No `migrate:fresh` is used.
- The owner summary uses the dashboard's existing bounded request queue, refresh events, visibility handling, retained data and refresh health. It does not load the full Financial Intelligence payload.
- No accountant/legal certification, live payroll approval, Kosovo fiscal certification, or live ATK/bank integration is claimed. Those DC3/DC4 limitations are unchanged.
- MariaDB/concurrency certification was not rerun: PX1 changes no authoritative accounting, tenancy, locking, allocation or database logic. Relevant existing SQLite order/payroll tests were reused.
- No next development phase was started. No merge or customer deployment was performed.
