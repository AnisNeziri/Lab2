# AIMS platform audit and repair verification

Scope: repair existing features, without new roadmap modules or paid dependencies. Existing company data and unrelated working-tree changes were preserved.

## Repairs

- Procurement: purchase-request editing, supplier quote entry, grouped award review, server-confirmed success and links to every created PO. Invalid/stale/duplicate awards are rejected; partial conversion and retries preserve existing POs.
- Quality: checklist create/edit/copy/archive/restore; already-used checklist checks remain immutable, including tolerances and instructions.
- Navigation: permission-aware routes, sidebar and command search, meaningful forbidden/not-found pages, working operation deep links, keyboard focus handling and stale-search protection.
- Presentation: English/Albanian workspace copy, light/dark surfaces, mobile controls and contained table scrolling. Populated stock history was checked at 1024px in both themes.
- Accounting forms: failed submissions preserve input; tabs and initial requests respect existing permissions.
- Warehouses: full product loading, warehouse-scoped shelf details, correct location balances and aggregate stock refresh. Health indicators no longer label health percentages as physical quantities. Saved preferences load before feature-route checks, including the standalone 3D route.
- Operation selectors: customer, purchase-order and receipt options follow API pagination instead of truncating the first page.
- Desktop packaging: development environment files, company databases and linked public storage are excluded; startup checks verify local assets and initialize an isolated SQLite database.

## Verification evidence

| Check | Result |
| --- | --- |
| Full SQLite backend suite | 244 passed, 1 skipped; 2234 assertions |
| Full MySQL backend suite | 244 passed, 1 skipped; 2240 assertions |
| Final targeted SQLite regressions | 11 passed; 136 assertions |
| Final targeted MySQL regressions | 11 passed; 136 assertions |
| Frontend unit tests | 8 passed |
| Browser verification | 52 distinct checks passed across the full run and targeted reruns |
| Final production build | Passed; 3149 modules, 27.43 seconds |
| Final desktop startup | Passed; local assets, isolated SQLite initialization and healthy API |
| Backup/restore | Encrypted backup/restore browser workflow passed |
| Migration validation | Fresh SQLite/MySQL migrations passed; live migration status inspected read-only |

The final production assets were copied to the desktop resources. Obsolete generated asset files were removed only from that generated frontend directory. No new installer was built or published.

The full browser run initially had two 1024px stock-table overflow failures. Both passed after repair. Two additional checks cover populated stock history and enabled 3D rendering/layout navigation. The 3D check exposed and verified the saved-preference startup race fix. Navigation/access/search checks were also rerun after that fix.

Browser coverage includes 27 principal workspace routes at 390, 768, 1024, 1366 and 1920px, light/English and dark/Albanian; additional cross-language/theme checks; procurement, quality checklist CRUD, products/detail scrolling, daily-sale stock deduction, fulfillment, documents, permissions, search and encrypted backup/restore workflows.

Fresh test migrations succeeded on both engines. Live migration status was inspected read-only. The MySQL checks used the isolated `aims_outbound_documents_cert_20260914_test` database, not the company database. One final MySQL attempt encountered a stopped local service; after restarting it, all 11 targeted tests passed.

## Certification limits

- The Redis round-trip check is skipped when Redis is unavailable; this is not a passed live-Redis certification.
- Browser checks cover the listed routes and workflows, not every possible record combination or every button manually.
- Some dynamic backend validation/status text remains English; static translation-key checks do not certify every possible server message.
- Live AIS/provider availability, third-party map tiles, physical scanners/printers and a signed installer/update release were not certified here.
- Large lazy-loaded 3D/PDF build chunks remain a performance warning, not a build failure.
- Desktop startup verification covers bundled runtime, local API, database initialization and assets; it is not an interactive installer or code-signing certification.

The passing regression baseline supports continued feature development; it is not an unconditional production-deployment guarantee.
