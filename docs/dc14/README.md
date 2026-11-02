# DC14 — Executive Intelligence & Business Command Center

HEAD: `fed921ad319d5f360281f11df67fbb5b96e1f656`, branch `main`. Changes remain uncommitted. No push, deployment, active migration, cutover or legacy repair.

1. **Reused architecture:** Dashboard resources/work profiles, Action Center, DC6 operating brief, Accounting/Financial Control, Customer 360, existing inventory planning, shipment intelligence, canonical business events, typed Ask AIMS tools and DC7 telemetry. `OperatingBriefSummary` is a read projection behind the existing `/operating-brief`; it creates no tasks or business actions.
2. **Executive workspace:** Command Center is a focused Dashboard view with three initial priorities, progressive evidence and source actions. Saved personal widgets remain available under My widgets, including the existing customization workflow.
3. **Brief and changes:** Permission-scoped current work, operational sales activity, financial qualifications and readiness links. Today/7/30-day selection uses actual recorded events and tenant-validated parents, with company-timezone boundaries. At most 80 candidate events/20 displayed events; this is not a reconstructed historical balance comparison.
4. **Attention:** Existing identities, explicit severity then due date, related accounting checks grouped into one source review. Recognized automated collections/low-stock/shipment tasks link into the corresponding issue instead of becoming a duplicate review card. No task creation or opaque AI score.
5. **Connected evidence:** Customer debt and advances remain authoritative. Replenishment links open the existing plan and show bounded saved quantities, history qualification and estimated cost with permission checks. Shipment warnings open the existing intelligence review. Posted Books, current Operational Activity and Forecast remain distinct. Cash/profit/working-capital conclusions require approved/verified opening evidence, balanced books and reconciled controls. Unverified nominal supplier advances are withheld from base-currency summaries. Forecasts additionally require complete cash coverage and fresh evidence. Daily-sale activity is document activity, excludes standalone invoices and later credits/returns, and is explicitly not booked revenue/profit.
6. **Ask AIMS:** Existing `get_operating_brief` typed read tool now supports the management view and bounded review period. Executive EN/SQ questions return source links and evidence; customer/stock/shipment questions filter relevant attention. Existing contextual assistant handlers and overdue collection filters remain intact. Affordability conclusions are blocked when openings/controls are unverified. No new chatbot, model version or write capability.
7. **Decision links:** Existing Customer 360, planning, shipment, accounting, payroll, onboarding and Action Center workflows. Preparing or committing actions still happens through their existing authorization, current-state validation and approval boundaries.
8. **Rendered UX:** Reviewed 1366px and 390px, English/light and Albanian/dark. No horizontal page overflow or browser exceptions. Mobile role controls are compact; evidence, financial details and history expand on demand. Unavailable sources display a warning and cannot produce a healthy/readiness claim. Retained data is explicitly qualified as potentially stale.
9. **Performance:** Real authenticated HTTP requests traced through DC7 PlatformTelemetry/PlatformTrace on isolated MariaDB 11.4.13: operating brief 792–993 ms / 16,305 bytes; executive Ask AIMS 1,592 ms / 2,299 bytes. Existing Action Center/permission work dominates (1,025 queries for the brief; 2,042 for Ask including authorization/tool execution). Reusing the authenticated permission set and checking each history source once reduced the new history projection from ~448 ms to ~76 ms locally. No full frozen Financial Intelligence JSON, new polling loop or broad performance certification. Inherited query volume remains a scaling limitation.
10. **Validation:** 17 affected backend tests / 294 assertions PASS (DC14, DC13, DC6, DC11); 16 affected frontend tests PASS; production build PASS (existing bundle-size warning remains); one representative owner browser journey PASS. It opened an overdue customer, a qualified history-based replenishment plan, a limited-evidence delayed shipment, accounting differences and bilingual executive Ask AIMS. Unavailable-source behavior and recorded-event period selection passed. Nine business-domain counts were identical before/after: tasks, PRs, POs, sales orders, customer debt transactions, journals, stock movements, recommendations and business events. Browser review produced no business mutation. Synthetic preparation used only the existing isolated Company 8; the original company was not modified.
11. **Remaining limits:** The original database is unavailable and was not restarted/repaired. Last DC12 evidence remains 13 violated source constraints/182 affected records; isolated rehearsal 12/180 after two verified role-link repairs. Preserved Company 1 copy still has zero journals and unresolved AR 868.86, inventory 2,226.82, cash/bank 3,190.94 and supplier advances 445,000 nominal with missing currency evidence. Active cutover, genuine opening documents/accountant approval, certified encrypted installation restoration, workers/scheduler commissioning and DC8 security/Electron/deployment prerequisites remain outstanding. Qualified synthetic demand is not proof of a calibrated production forecast or proven service level. No historical financial comparison, purchasing affordability or real-company readiness has been manufactured.

| Required result | Status |
|---|---|
| Executive Command Center usable | YES |
| Authoritative financial source bases preserved | YES |
| Cross-module insights evidence-backed | YES |
| Daily briefing useful and permission-scoped | YES |
| Historical changes never invented | YES |
| Recommendations link to existing workflows | YES |
| High-risk AI execution remains protected | YES |
| Ask AIMS uses existing authorized tools | YES |
| Role and tenant isolation verified | YES |
| EN/SQ and light/dark reviewed | YES |
| Mobile and desktop usability reviewed | YES |
| Known live-company readiness blockers preserved | YES |
| Ready for controlled intelligence pilot | YES — isolated, supervised evidence review |
| Ready for real-company pilot | NO |

Evidence index: [VALIDATION.json](VALIDATION.json). Local logs/screenshots are under `output/dc14/`; credentials remain outside the repository. All 348 baseline files still exist, unrelated baseline hashes match, and the active `.env` hash is unchanged. Temporary test services are stopped after validation. DC15 is not started.
