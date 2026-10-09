# PM2 — Personalized Workspace

## Completion report

1. **Grid architecture:** Ordered CSS Grid, 12 desktop columns, fixed 16px gutters. Cards cannot store coordinates, margins, arbitrary dimensions or overlapping positions. Dashboard orchestration is separate from the registry, widget frames, data resources and business previews.
2. **Widget registry:** `frontend/src/config/dashboardWidgets.js` defines IDs, bilingual labels, categories, permissions, source requirements, component type, supported sizes, column bounds, minimum height and default settings.
3. **Available widgets:** 25 types: today's sales, sales trend, orders overview, recent orders, inventory value, product count, low stock, stock movements, inventory by category, stock by warehouse section, customer receivables, customer reorder opportunities, supplier reliability, recent purchase orders, purchase requests, active shipments, shipment risk, cash outlook, Action Center, automation status, forecast health, Supply Optimizer summary, Strategic Simulation summary, inventory activity and common actions. Availability depends on the user's actual permissions. Missing analyses show an honest empty state; visiting the dashboard never starts training, forecasting, purchasing or financial posting.
4. **Orders:** Compact authoritative operational-count board and separate optional recent-orders table. Board values lead to the existing filtered Order Hub. The default composition places Orders below Sales.
5. **Drag/drop:** Only the dedicated handle is draggable. Drop targets are highlighted, cards snap into array order, and positions are canonicalized. Earlier/later buttons provide keyboard and touch alternatives. Business content is inert while arranging.
6. **Resize:** Only registry-defined small/medium/large spans are available. Charts do not support the small size. Minimum heights and shared card/header/padding styles remain controlled.
7. **Persistence:** Authenticated GET/PUT `/api/settings/workspace` store dashboard and navigation under the existing per-user preferences JSON, with user/company identity, layout versions, revision and update timestamp. Row locks and revision checks reject stale device writes with HTTP 409. Partial section updates preserve the other section. Theme/language writes merge against the fresh locked user row and do not erase the workspace. No database migration is necessary.
8. **Responsive:** Four KPI columns on wide layouts, narrower two-column behavior and one-column mobile collapse. Tested at 1920, 1366, 768 and 390 pixels, in light/English and dark/Albanian. Reduced motion is respected. Modal content scrolls within the viewport and dialog focus/scroll state is restored on close.
9. **Sidebar:** Hide/restore, up to six favorites, within-group earlier/later ordering, searchable available/hidden/favorite lists, Cancel and confirmed role-default reset. Dashboard and Action Center cannot be hidden. Settings, search and customization controls remain outside the hideable registry.
10. **Permissions:** The existing navigation/page permission definitions filter the catalog, saved layouts, sidebar and command palette. Revoked widgets disappear and their data subscriptions are removed. Preferences do not grant any API permission. Hidden permitted pages remain searchable through Ctrl+K.
11. **Tests:** 11 frontend unit/navigation checks passed; 11 backend workspace/existing preference tests passed (67 assertions); four representative Chromium workflows passed; production Vite build passed. Browser tests use the isolated E2E database, never the live company database. Screenshots are under `output/pm2/`. `git diff --check` passed. The project has no separate lint/type-check command in its frontend package scripts.
12. **Performance:** Shared endpoint reads, at most four concurrent requests, 30-second read cache, visibility-aware subscriptions and lazy loading. Background updates retain existing content. Local failure/retry does not replace the dashboard. Timers, observers, subscribers and active requests are cleaned up when leaving the page. More than 18 widgets produces a gentle complexity warning. No animation/drag library or new dependency was added.
13. **Known limitations:** One instance per widget type, a six-favorite cap, and no admin-managed presets or saved alternate layouts. Sales charts use the existing calendar week/month/year API, not invented rolling 7/30/90-day totals. Operational lists are compact previews with detail links. Data availability/forecast quality remains that of the existing authoritative services. Large pre-existing PDF, 3D and main chunks still cause Vite size warnings; the build succeeds. Desktop installer redistribution is not part of this PM2 task.

## Explicit confirmations

- Arbitrary free positioning prevented: **YES**
- Dashboard always remains aligned: **YES** (controlled spans, gaps and non-overlapping responsive grid)
- Widgets permission-aware: **YES**
- Hidden navigation recoverable: **YES**
- Hidden pages still searchable when permitted: **YES**
- Layout persistent: **YES** (server-backed, tested in a second independent browser session)

## Use

Dashboard → **Customize Dashboard** → add/search widgets, adjust settings/sizes, move using the handle or arrows → **Save**. Cancel discards the draft. Reset requires confirmation and Save.

Sidebar → **Customize Navigation** → toggle visibility, pin favorites and reorder within groups → **Save**. Restore from **Hidden items**. Ctrl+K continues to find permitted hidden pages.

Supplier Reliability requires selecting an existing supplier in Configure. Cash, customer, forecast, optimization and simulation widgets show recorded/saved evidence only, with an empty state when it does not exist.

## Focused verification commands

From `backend`: `php vendor/bin/phpunit --filter "WorkspacePreferencesTest|test_login_returns_default_preferences|test_user_can_update_preferences"`

From `frontend`: `node --test tests/workspace-personalization.test.mjs tests/navigation.test.mjs`

From `frontend`: `node node_modules/@playwright/test/cli.js test e2e/specs/workspace-personalization.spec.js --project=chromium`

From `frontend`: `node node_modules/vite/bin/vite.js build`

PM3 has not been started.
