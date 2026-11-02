# AIMS Glyphs · Open Rail

DC15.1 introduces 36 authored business glyphs. The existing AIMS logo is unchanged.

The visual direction uses structural lines, clipped document/frame corners, recessed rails and short registration marks. Silhouettes follow the business object: a warehouse bay, shipment hull, purchasing document, customer pair, ledger, payment wallet or integration connector. AI uses an evidence window rather than a sparkle or robot. No third-party SVG paths were used to author this family. This is a design-origin statement, not an IP clearance or trademark claim.

## Vector specification

| Property | Rule |
| --- | --- |
| Grid | 24 × 24; normal keylines 3–21, optically extended elements 1.5–22 |
| Stroke | 1.7 units at 20/24 px; 1.6 at 16 px and below |
| Corners | Structural chamfers normally 1.5–3 units; round joins/caps soften rasterization |
| Space | At least 2 units between independent details; no background tiles inside the vector |
| Scale | 16 px actions/tabs, 18 px navigation, 20–24 px headings; larger only for meaningful empty states |
| Small variants | Explicit simplified paths for dense glyphs; remove fine detail before changing silhouette |
| Color | `currentColor`; existing text/primary tokens; no fixed neon, gradients or theme-specific SVG fills |
| Accent/active | Explicit `accent`/`active` props use the existing primary token; selection also retains text and existing navigation treatment |
| Disabled | Explicit `disabled` sets `aria-disabled` and .45 opacity; the surrounding control owns actual interaction restrictions |
| Motion | Short color/opacity transitions. Optional `motion="working"` only for an actual running operation; reduced motion disables it |

## Source and use

`glyphPaths.js` is the editable path authority. `AimsGlyph.jsx` renders these paths and exports named components plus the semantic `aimsGlyphs` registry. The exported `aims-glyphs.svg` contains normal and small `<symbol>` sources for design tools. Regenerate it with `node scripts/export-aims-glyphs.mjs` from the repository root. The SVG export is not fetched by the application.

```jsx
import { OrdersGlyph, AimsGlyph } from './icons/AimsGlyph'

<button><OrdersGlyph size={16}/>Create order</button>
<AimsGlyph name="receiving" size={20} label="Receiving"/>
```

Icons are decorative (`aria-hidden`, non-focusable) by default. An isolated meaningful icon needs a translated `label`, `aria-label` or `title`; preferably label its containing button. Visible business/action labels remain present. SVG paths come from a finite local registry, never arbitrary HTML. Unknown semantic names receive the existing Lucide CircleHelp fallback.

`workspaceIcons.js` bridges prior component keys and the semantic registry. `navigation.js` assigns semantic module names without changing paths, permissions, entitlements, profiles or personalization. Do not scatter new SVG copies into pages.

## Collection and integration

Dashboard, Products, Inventory, Warehouse, Orders, Sales, Customers, Suppliers, Purchasing, Shipments, Accounting, Payments, Documents, Reports, Receiving, Picking, Dispatch, Transfers, Stock Count, Returns, Automation, Tasks, Approvals, Ask AIMS, Intelligence, Search, Security, Employees, Payroll, Integrations, Settings, System Health, Notifications, Quotations, Locations and Scan.

49 of 53 tenant navigation entries use these semantic glyphs. The four deliberate navigation fallbacks are Categories (FolderTree), Warehouse 3D (Box), Warehouse layout (LayoutGrid), and Global map (Globe). Related secondary screens still use the existing Lucide family where an authored replacement is not yet justified. Platform-admin/CMS symbols and universal Print, Download, Save, Edit, Delete, Filter, Back, Close, Warning and Success actions remain familiar. Lucide's installed ISC license remains in its package; its artwork is not renamed as AIMS artwork.

Integrated surfaces include navigation/favorites, shared page headers/tabs, Command Center, Action Center, customer quotations/payments, accounting, warehouse/mobile operations, Ask AIMS, search, notifications, settings, security and integrations. No new UI or icon dependency, backend authority or business module was introduced. The glyph JS/CSS are local bounded assets; no icon service, font or remote request is used.

Recognition has been visually reviewed with existing EN/SQ text labels at desktop/tablet/mobile sizes and in both themes. This is not a first-time-user research study. DC16 can gather that feedback without requiring users to memorize the new symbols.
