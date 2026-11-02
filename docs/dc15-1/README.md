# DC15.1 · Signature visual identity

HEAD remains `fed921ad319d5f360281f11df67fbb5b96e1f656` on `main`. DC15 work and earlier uncommitted changes remain in place. No commit, push, deployment, company-data repair or new business module was performed.

1. **Direction:** AIMS Glyphs, “Open Rail”: precise structural lines, clipped corners, recessed rails and distinct business silhouettes. The AIMS logo is preserved. AI uses an evidence window, without repeated sparkles, robots, glows or neon gradients.
2. **Specification:** 24-unit grid; 1.7-unit stroke, 1.6 for small use; deliberate optical simplifications at 16 px; consistent round caps/joins and structural chamfers. Current-color inheritance and existing tokens preserve both themes. See the [source design specification](../../frontend/src/components/icons/README.md).
3. **Collection:** 36 original business/operations/platform glyphs, with editable path source and an SVG symbol export. No imported third-party icon paths were renamed as original artwork. Legal uniqueness or trademark protection is not claimed.
4. **Architecture:** one semantic React component/registry, named exports, accessible labels, decorative hiding, size/accent/disabled states and an explicit unknown-name fallback. Paths remain centralized; the SVG export is regenerated from the same definitions.
5. **Integration:** 49 of 53 tenant navigation entries, favorites, shared module headers/tabs, Command Center, Action Center, Customer 360 quotation/payment actions, accounting, warehouse/mobile operations, Ask AIMS, search, notifications, settings, security and integrations.
6. **Interactions:** existing selected-navigation and workflow behavior retained; short icon color/opacity feedback and optional working-state animation respect reduced motion. Text labels remain visible. No icon substitutes for an approval or business-state check.
7. **Fallbacks:** Categories, Warehouse 3D, Warehouse layout and Global map retain Lucide topology/box/grid/globe symbols. Universal actions and some secondary features retain the installed ISC-licensed Lucide family. No new icon dependency was added.
8. **Visual verification:** rendered desktop 1366×768, tablet 768 and mobile 390 screens in EN/light and SQ/dark, with targeted EN/dark and SQ/light. Glyphs were separately reviewed at 16/20/24 px in both themes. Labels and familiar silhouettes support recognition; first-time-user research remains for later validation.
9. **Performance:** bounded local SVG components, no icon-font/service/image requests. Production glyph JS/CSS are approximately **4 KB gzip combined**. No expensive animation dependency. Existing unrelated bundle-size warnings remain.
10. **Validation:** production build passed; 30 relevant frontend checks passed, including one semantic-registry regression check. One browser navigation journey covered 20 screen/theme/size combinations, search, assistant opening and reduced motion. Representative business/print journey also passed against the final frontend. No backend certification or new test dataset was generated for this icon phase.

| Required result | Status |
| --- | --- |
| Original AIMS Glyphs implemented | YES — 36 |
| Reusable icon architecture | YES |
| Major navigation icons replaced | YES — 49/53 tenant entries |
| Icons understandable without training | YES with retained text labels; no formal user-study claim |
| Existing logo preserved | YES |
| Light/dark reviewed | YES |
| EN/SQ reviewed | YES |
| Mobile/tablet reviewed | YES |
| No unnecessary icon dependency introduced | YES |
| Existing business functionality preserved | YES — presentation-only changes, reviewed journeys pass |
| Ready for DC16 validation | YES — controlled isolated validation |

No real-company or commercial readiness claim is made. Previous integrity, opening/recovery, commissioning and security blockers remain. DC16 has not started. `VALIDATION.json` indexes local evidence; the source SVG and design specification are in `frontend/src/components/icons/`.
