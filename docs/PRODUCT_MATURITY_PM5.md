# AIMS PM5 — Real-user workflow and consistency pass

## Scope and safety

PM5 improves the existing product; it adds no intelligence version, accounting expansion, business module, database schema, permission grant, or production certification. Existing uncommitted PM4 work was preserved. The populated, isolated PM3 company was used on frontend port 5174/API port 8013. Normal web accounts and business records were not reset or reseeded.

These are agent-operated browser acceptance walkthroughs with representative work profiles, not a recruited human novice-user study. Owner and manager authentication were used for permission-rich workflow coverage; an existing warehouse staff account was separately checked. Sales, purchasing and finance presets are presentation choices within existing permissions, not newly introduced roles. Test workspace preferences are restored afterward. No real sale, payment, receiving or stock posting was performed against a client's company.

## Workflows reviewed and repaired

1. **New-user flow:** Dashboard → global product search → product detail → stock location → customer debt/payment controls → order creation/detail → supplier → PO → tracked shipment/cargo → Action Center → Ask AIMS → recommendation → dashboard/navigation customization.
2. **Owner:** attention, sales trend, low stock, receivables, incoming orders/shipments, finance and cash access.
3. **Warehouse:** mobile scan/search, locator, warehouse/transfer controls, stock-count requests and assigned tasks.
4. **Sales:** product availability, customer sheets, order progress/reservations and Daily Sales.
5. **Purchasing:** planning, requests/quotes/award progression, POs and grouped incoming cargo.
6. **Finance:** customer receivables, finance sources, cash/bank and journals; actual accounting classifications remain intact.

## Implementation findings

- Session filters now survive useful list/detail/list work on products, customers, suppliers, POs, orders, tasks and stock locator. Memory expires after eight hours and is scoped to the current company/user. Form drafts, credentials and business records are not stored by this mechanism. Browser Back restores scroll by history entry and stops restoring when the user scrolls.
- Exact product search was being outranked by related tasks. Visible and keyboard search use the same ranked results; products show SKU, authoritative availability and unit. Search covers names, partial names, SKU, PO/order references, customers, suppliers, shipment/vessel records, documents and recommendations. Strong close matches are labelled suggestions, not silently opened.
- Internal decision types were not searchable with normal business terms. Read-only search now accepts English/Albanian recommendation labels. Displayed status vocabulary is business-readable and bilingual; unchanged technical evidence can remain under Details.
- Existing related records are directly accessible: product → storage/orders/planning/supplier; PO → supplier/shipment; customer and order connections; linked tasks/history; cargo → its actual PO. A stock-locator race used to discard the linked product while its list was loading; it now retains the requested record even outside the first twenty preview results.
- PO detail URLs, Back, and New purchase order are coordinated. Starting a new PO no longer leaves the previous PO selected in the URL. Detail request guards prevent a late response from replacing a newer selection.
- Six work-profile presets can be reviewed before explicitly saving dashboard/navigation choices. Permission filtering remains authoritative. Hidden authorized sidebar pages remain available through Ctrl+K; recent destinations are bounded and permission-filtered.
- Action Center starts with assigned active work, preserves working filters, orders overdue/urgent tasks usefully, prioritizes related approvals, and groups identical issue previews with occurrence counts. Full source records are not merged/deleted. Only eight related issues show initially, with an explicit Show all control.
- Dashboard labels and deep links distinguish recorded facts, estimates and scoped counts. Unknown monetary values display as unknown, not zero. Finance metrics no longer truncate totals with ellipses; their layout accommodates the complete amounts.
- Ask AIMS offers at most four useful permission-aware examples. Its own starter question “Which products may run out?” was incorrectly treated as an entity named “may”; all eight English/Albanian starter questions now route to their intended read-only tools. Existing source/freshness/limitation controls and helpful/not-helpful feedback remain available. Failure/uncertainty does not generate invented business facts.
- Forms use specific server validation, accessible field-level errors, focus on the affected input and correction feedback. Native required-field validation prevents an empty product submission. A simulated duplicate-SKU rejection verifies no success animation and no product write. Pending operations retain existing duplicate-submission protection.
- Date/money/quantity helpers provide consistent display, preserve ISO API/input values and distinguish unknown from true zero. Cargo ETA and recorded ETA share the same calendar format; receipt/history timestamps are localized. Fulfillment reservation/dispatch values display the product's base unit, rather than falsely labelling base quantities with a sales pack unit.
- Record history now uses readable bilingual movement/event labels, quantities with units, actual company currency and retained notes. Unknown internal event codes are disclosed under Details. A failed history read shows Retry rather than falsely reporting no activity. Read-only response metadata is backward compatible; no audit history is rewritten.
- Document panels identify the attached record, readable revision/date and actual missing/review/expiry requirements. Empty/error states and modal focus/scroll recovery were reviewed in the connected paths. Domain cancellation, reversal and draft-deletion protections were preserved, not replaced by destructive shortcuts.

## Performance measurements

Measurements are local Vite development/isolated SQLite observations, not production SLAs. Raw artifacts are in `output/pm5/new-user.json`, `roles.json` and `performance.json`.

| Representative read | Payload |
| --- | ---: |
| Product detail | 35,487 bytes |
| Order Hub, twenty rows | 14,752 bytes |
| Shipment/cargo detail | 18,188 bytes |
| Action Center | 44,841 bytes |
| Full financial intelligence | 282,137 bytes |
| Dashboard financial summary | 194 bytes |
| Full customer/sales intelligence | 459,953 bytes |
| Dashboard opportunity summary | 914 bytes |

Normal search reads were approximately 150–470 ms across the measurement runs. The final manager navigation run was approximately 1.1–2.3 seconds per full development-page navigation, including network-idle settling and the one-time shipment introduction; Ask AIMS replied in approximately 710 ms with 34,145 bytes. These are not SPA-only rendering benchmarks.

The two optional `summary_only=1` responses project existing authoritative saved values. Default responses, scopes and calculations are unchanged. They substantially reduce wire/parsing payload, but still hydrate the existing snapshot internally. Warehouse Locator also loads products, receipts and transfers together. Larger snapshot hydration, tab-lazy loading, indexing and production bundle profiling belong to the upcoming performance work; no broad backend rewrite was attempted here.

## Verification

From `frontend`:

```text
node --test tests/*.test.mjs
node node_modules/@playwright/test/cli.js test -c playwright.synthetic.config.js pm5.spec.js
node node_modules/vite/bin/vite.js build
```

Results: **87 frontend tests passed**. **Five populated Chromium acceptance tests passed** (approximately four minutes): connected manager flow; all work profiles across desktop/tablet/mobile, themes and languages; search/filter/history/payload checks; staff permission-safe presets; field validation/PO creation continuity/financial and actual cargo readability. The final connected manager and inline-validation/cargo checks were rerun after final presentation adjustments. No page exceptions or unexpected root horizontal overflow were detected in the reviewed views.

From `backend`:

```text
php artisan test --filter='ProductMaturitySearchTest|WorkspaceSummaryPresentationTest|WorkspacePreferencesTest|PlatformFoundationTest|AutomationStudioTest|FinancialIntelligenceTest|CustomerSalesIntelligenceTest|IntelligenceAssistantTest|ProcurementApprovalTest|ProductMaturityConversationTest|ProductMaturityLifecycleTest|OrderWorkflowTest'
php artisan test --filter=PlatformFoundationTest
```

Results: **108 focused backend tests passed, 1,040 assertions**; the four platform/tenant/context tests also passed after the final read-only history metadata adjustment. Existing real order settlement, cancellation, stock-count lifecycle and procurement award coverage was preserved. No full backend/MariaDB certification or desktop installer suite was run for this presentation phase. Production frontend build passes; existing large-chunk advisories remain for the main, PDF and 3D bundles.

## Acceptance record

The following YES results refer to the described automated/agent-operated PM3 workflows, not an independent human usability study or every possible business posting combination.

| Acceptance check | Result |
| --- | --- |
| New-user walkthrough passed | YES |
| Owner walkthrough passed | YES |
| Warehouse walkthrough passed | YES |
| Sales walkthrough passed | YES |
| Purchasing walkthrough passed | YES |
| Finance walkthrough passed | YES |
| No known dead UI remains in reviewed workflows | YES |
| English/Albanian reviewed | YES |
| Light/Dark reviewed | YES |
| 1366×768 reviewed | YES |
| 768px reviewed | YES |
| 390px reviewed | YES |

## Known limits and recommended Production Readiness priorities

No blocking usability defect is known in the reviewed flows. This does not certify unreviewed routes, live integrations, every employee permission configuration or every irreversible business action. Synthetic vessel data deliberately has no live AIS position; the readable pending state was checked, not live provider coverage. Browsers render native date-entry controls according to their own locale, while read-only business dates use AIMS's selected language. Existing detailed intelligence payload/bundle costs remain performance priorities.

Recommended next phase, not automatically started: independent employee UAT with timed tasks; staged permissions/security checks; SQLite/MariaDB CI and production-scale performance; backup/restore drills; verified live AIS configuration; accessibility assistive-technology testing; signed desktop delivery/update/licence and clean-machine recovery checks. PM5 does not claim legal/fiscal certification or start Production Readiness.
