# PM4 — Interaction and workflow coherence

Scope: usability and lifecycle safety only. No V13 work, new models, schema changes, or production-readiness certification.

## Delivered

- Orders begin with customer, reference, dates, payment amounts, fulfillment and actual allocation warehouses. Backend money values are reused; shared advances are not misrepresented as order-specific payments. Valid next actions remain governed by existing services. Completed orders link to history.
- Shared dialogs expose `closeOnBackdrop`, `closeOnEscape` and `warnOnUnsaved`. Nested confirmations preserve focus and body-scroll restoration. Lightweight menus close outside, on Escape, when another menu opens and on route changes.
- Dashboard/navigation editing has sticky actions, normalized dirty-state detection, disabled unchanged Save, discard protection and browser-history protection. The widget chooser is a centered dialog, not an off-screen section.
- Refresh health is measured from existing background polling and dashboard resource failures. Green means successful active refresh; hidden/inactive is muted; repeated failures, offline state or stale success are warning states. Existing data survives failures.
- Count creators can cancel their own unsubmitted requests. Approvers retain existing cancellation authority. Unused, unsubmitted count drafts can be deleted with explicit confirmation. Counted/submitted/approved records and downstream references prevent deletion. Cancellation/deletion is audited; no stock adjustment occurs.
- Action Center identifies manual, automation and system origins. Existing Edit/Cancel/Complete and decision Dismiss/Resolve semantics are reused; executed history and learning evidence are not deleted.
- Settings explain immediate persistence. Assistant dismissal preserves typed questions. Existing intelligence conclusions and evidence limits are retained; optimizer/simulation builders and solver metadata use progressive disclosure.
- Invoice and expense form dialogs protect unsaved edits and pending operations. Shipment logistics and transfer receiving forms no longer close accidentally on backdrop clicks. No finance, receiving or shipment business service was changed.

## Lifecycle policy reviewed

| Entity | Appropriate removal/closure | Protection |
| --- | --- | --- |
| Unused physical count draft | Delete Draft | No recorded count, submission, approval, movement or linked operational task |
| Submitted/recorded count | Cancel when authorized | Reason, actor/time and count history retained; approved counts protected |
| Manual/automation operational task | Edit, Cancel or Complete under existing permissions | Terminal actions remain historical; outcome events retained |
| Intelligence recommendation/decision | Dismiss or Resolve | Frozen evidence and Decision Learning history retained |
| Procurement, receiving, returns and approvals | Existing domain-specific draft/withdraw/cancel/reversal paths | No new shortcut around authoritative domain rules |

## Verification

Backend command (from `backend`):

```powershell
php artisan test --filter='ProductMaturityLifecycleTest|InventoryFoundationTest|OrderWorkflowTest|AutomationStudioTest|EnterpriseDecisionTest'
```

Result: 42 passed, 488 assertions. Includes tenant/downstream deletion protection, no stock movement on cancellation/deletion, immutable decision outcomes and real order cash/advance/partial-payment amounts.

Frontend focused command (from `frontend`):

```powershell
node --test tests/refresh-health.test.mjs tests/dashboard-presentation.test.mjs tests/order-presentation.test.mjs tests/navigation.test.mjs tests/decision-presentation.test.mjs
node node_modules/@playwright/test/cli.js test --config=playwright.synthetic.config.js pm4.spec.js
node node_modules/vite/bin/vite.js build
```

13 presentation/health tests pass. Seven Chromium PM4 checks cover the populated owner workflow, protected financial drafts, protected browser Back, and manager/sales/warehouse/purchasing workspaces. Production build passes; existing large-chunk advisory remains.

Browser checks use the already-generated isolated PM3 SQLite company, not the normal web database. Temporary dashboard/navigation arrangements are restored. Synthetic cancellation/deletion audit records remain intentionally. Sales, warehouse and purchasing profiles use the existing staff role; these are representative job workflows, not a new permission model.

Owner coverage: 18+ widgets; add/move/resize/save/discard; menu focus/outside/Escape; nested unsaved-form protection; count create/cancel/delete; Order summary and next action; both sticky toolbars at 1920/1366/1024/768/390; English/Albanian and light/dark; nine intelligence surfaces. Screenshots and owner verification record: `output/pm4/`.

## Boundaries

This is a focused rendered-product review, not an exhaustive formal novice-user study or production certification. Some existing domain screens retain native discard confirmations rather than the shared visual confirmation. Real company data, existing user credentials, role rules, accounting and inventory posting behavior were not reset or redesigned.

## PM4 additional product maturity requirements

### Assistant conversation

- `AssistantLanguage` normalizes a controlled English/Albanian vocabulary, common misspellings and mixed-language questions. Unknown entity names are preserved instead of rewritten as business facts.
- `AssistantEntityResolver` reuses tenant-scoped, permission-checked records. Exact references, SKU/barcode, compact names and strong unique fuzzy matches are supported. Competing matches require a choice; a disclosed fuzzy match is never an undisclosed guess.
- Product, customer, supplier, warehouse, shipment, PO and sales-order context can be retained. Ordinal follow-ups use the returned records; a related shipment choice does not discard the selected product. Clearing context preserves the visible conversation.
- Answers reuse authoritative tools and values. Three evidence cards appear initially, with more records and technical evidence behind disclosure controls. Source links point to the existing record routes. New replies begin at their explanation rather than their feedback footer.
- No paid AI, external data transmission, new business write tool, or new intelligence version was added.

### Vessel and incoming-stock workspace

- `ShipmentCargoPresentation` provides a read-only presentation from existing linked orders, cargo allocations, PO receipts and posted landed costs. Both detail and existing logistics-update responses include it.
- The workspace puts vessel identity, route, recorded/evaluated ETA, destination warehouse, position freshness and searchable incoming stock first. PO products are grouped by supplier/order; each row distinguishes ordered, allocated-to-this-shipment, loaded-if-known and PO-received quantities.
- Compatible units are grouped separately. Multiple allocation units are not added together. Other shipment allocations and known unallocated PO amounts remain distinct. A whole PO total is labeled as the full PO value, not shipment cargo value.
- Existing receipt data is shown with an explicit PO-level attribution warning; the implementation does not invent receipt-to-shipment attribution.
- Existing V6 impact, risk, forecast stock and ETA evidence are reused. Port arrival is not presented as usable warehouse availability. Unknown or stale evidence stays visibly unknown/stale. Timeline completion requires confirmed milestones.
- Selecting a vessel or opening its direct link brings the workspace into view once. Background refreshes preserve detailed cargo and do not repeat this scroll. Selection updates the existing shipment URL, including split-allocation links.

### Visual verification and tests

The computer-use visual review used the existing isolated PM3 company and actual rendered data. No company was reset/reseeded. `backend/scripts/pm4-cargo-fixture.php` adds an idempotent, non-posting test cargo record linked to three existing PM3 POs. It does not change their financial or inventory postings.

```powershell
# backend
php artisan test --filter='ProductMaturityConversationTest|IntelligenceAssistantTest|ShipmentIntelligenceTest|SupplyOptimizerAssistantTest|SupplyChainControlTowerTest'

# frontend
node --test tests/shipment-cargo.test.mjs tests/intelligence-assistant.test.mjs tests/assistant-presentation.test.mjs tests/shipment-intelligence.test.mjs
node node_modules/@playwright/test/cli.js test --config=playwright.synthetic.config.js pm4-conversation-cargo.spec.js
node node_modules/vite/bin/vite.js build
```

Results: 49 backend tests / 430 assertions passed; 15 frontend tests passed; all three additional Chromium checks passed. The visible-page pass captures all 41 permitted routes in English/light and Albanian/dark (82 populated route captures), with no page errors or horizontal overflow. Vessel details and cargo are checked at 1366×768 and 390×768 in both themes/languages, including search, direct PO links, clipping and unknown-position messaging. Final production build passes. Existing large-chunk advisories remain for the main/PDF/3D bundles.

Screenshots, contact sheets and structured checks: `output/pm4-extra/`. This extends the earlier PM4 verification; it does not replace or invalidate it.

### Honest limits

- Deterministic language handling is not unrestricted human-language comprehension. Fuzzy lookups are bounded to 200 candidate records; broad large-catalog queries may require a more specific name, SKU or reference.
- Existing receiving records do not provide per-shipment receipt attribution. The view labels this accurately rather than changing the receiving domain.
- The isolated PM3 installation has no configured live AIS connection. Its test vessel displays no fabricated position; this review verifies cargo/risk presentation, not live provider connectivity.
- Some legacy technical evidence contains backend field names or English diagnostic text under expandable details. This is not a replacement for a formal novice-user study or release certification.
