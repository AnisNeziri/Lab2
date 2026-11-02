# DC15 · Product quality completion

HEAD `fed921ad319d5f360281f11df67fbb5b96e1f656`, branch `main`. Existing uncommitted work was preserved before editing. No commit, push, deployment, active database migration/cutover or legacy-data repair was performed. Validation used the existing isolated MariaDB rehearsal and synthetic Company 8.

1. **Repairs:** corrected the invoice PDF URL, added the missing usable Print flow, exposed source-owned customer payment navigation, and fixed the administrator customer workspace's misleading default scope.
2. **Printing:** authenticated HTML preview and PDF share the existing issued invoice/tax snapshots. Print invokes the preview frame directly. A4 output remains white in dark mode; quantities/units, repeated table headers, totals, VAT and fiscal limitations remain visible. A 72-line issued fixture produces a 9-page server PDF and 7-page Chromium print PDF. All lines and fractional quantities survive, with unchanged issued hash. Browser table-header tracing avoids unreliable fixed footers; server PDFs retain page numbers. Native dialogs/hardware and Electron printing were not verified.
3. **Shared components:** refined headers, content widths, tabs, status badges, workflow stages, financial number alignment, forms/dialog actions and loading presentation using the existing design system.
4. **Icons/interactions:** consistent labelled module/action icons, focus/hover feedback, keyboard tab movement, bounded transitions and reduced-motion support. DC15.1 subsequently replaces the main business symbols with authored AIMS Glyphs.
5. **Navigation:** shared icon vocabulary, preserved role/entitlement filtering, working related-record links, Ctrl+K, personal widgets and customization cancellation.
6. **Workflows:** connected the reviewed stock → customer → delivered order → invoice → authoritative receivables payment entry path. CRM Save and unsaved-change protection were exercised. No financial or stock authority was replaced.
7. **Newer modules:** reviewed rendered accounting, payroll, automation, security, integrations, recovery/onboarding and intelligence screens; shared refinements apply without removing their controls. Shipment tracking now distinguishes verified vessel tracking from cargo/manual milestones. Artificial shipment intro delay was removed.
8. **Command Center / Ask:** three priorities appear first, financial limitations stay explicit, secondary evidence expands on demand. Ask uses a multiline keyboard-friendly composer, readable source/evidence cards and a useful conclusion before details. Existing read-only and human approval boundaries remain.
9. **Responsive/language:** owner journey reviewed 1920, 1366×768, 768 and 390 px in EN/light and SQ/dark. Additional module screens and opposite theme/language combinations were reviewed. Mobile print preview retains A4 width with internal scrolling. This was representative review, not exhaustive feature certification.
10. **Queries:** repeated permission resolution was the measured cause of amplification. Bounded request-local read scopes reuse authorization only during tool catalog, brief and Action Center reads; writes and execution checks remain fresh. Same-fixture DC14 kernel replay: brief **1,025 → 173**, executive Ask **2,042 → 176**. Live HTTP telemetry: **456 → 169**, **792 → 170**, confirmed again at the end. No cross-request/tenant cache or stale financial summary was introduced.
11. **Validation:** 67 existing/focused backend tests, 894 assertions, passed; final print regression rerun: 4 tests, 72 assertions, passed. DC15 frontend selection: 29 tests passed; DC15.1 adds one registry regression check (30 total). Production builds passed. Both business and owner/admin browser journeys passed; the business journey was repeated against the final glyph build. The broader rendered review covered 33 routes. Build retains existing large-chunk warnings. Two stale test fixtures were corrected: payroll now obtains normal recent-auth sessions; synthetic ES256 coordinates are padded to their required width. Production authentication/crypto checks remain unchanged.
12. **Limits:** no confirmed broken high-priority control remains in the reviewed scope. Native print/hardware/Electron remain untested. Empty payroll/PO tables were not populated merely for screenshots. Legacy referential integrity, unreconciled opening balances, unapproved database cutover, complete encrypted recovery, worker/scheduler commissioning and DC8 commercial/security blockers remain. Real-company/commercial readiness is **not established**. DC16 was not started.

| Required result | Status |
| --- | --- |
| Invoice printing works in verified environment | YES |
| Invoice PDF multi-page layout correct | YES |
| Browser printing verified | PARTIAL — invocation and Chromium PDF; native dialog/hardware unverified |
| Electron printing verified | NOT TESTED |
| Major business actions reviewed | YES — selected journeys above |
| Known broken high-priority controls | NONE in reviewed scope |
| Shared visual system consistent | YES — representative screens |
| Icons improve discoverability | YES — readable labels retained |
| Dynamic interaction remains performant | YES — bounded transitions, reduced motion |
| Major workflows understandable | YES — reviewed paths; no user-study claim |
| EN/SQ reviewed | YES |
| Light/Dark reviewed | YES |
| Desktop/tablet/mobile reviewed | YES |
| DC14 query amplification reduced | YES |
| Inventory/accounting authority preserved | YES |
| Ready for DC16 deep validation | YES — isolated validation only |

Local evidence index: `VALIDATION.json`. Detailed logs, PDFs, traces and screenshots remain under ignored `output/dc15*`; private credentials are excluded. The browser comparison preserves stock, customer finance, reconciliations, journal/transaction counts and the issued hash; the sole expected relationship change is one synthetic contact. It does not claim that every AIMS feature was tested.
