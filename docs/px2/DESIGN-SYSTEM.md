# AIMS shared interface

PX2 consolidates the existing PX1/PM design language. It adds no UI framework and changes no business authority.

## Sources of truth

- `frontend/src/index.css`: light/dark surface, text, border, focus and semantic fill colors.
- `frontend/src/styles/ProductMaturity.css`: spacing scale, radii, page gutters, control height, table rhythm and existing density preferences.
- `frontend/src/styles/EnterpriseUI.css`: workspace shell, forms, search and dialog foundations.
- `frontend/src/styles/ProductExperience.css`: shared business hierarchy, canonical tabs, status tones, primary/danger controls and responsive adaptations. Module CSS describes layout using those tokens.

Use `--card-bg`, `--surface-soft`, `--text-color`, `--text-muted`, `--border-color` and `--ui-primary-fill`. Do not introduce undefined aliases such as `--bg-primary`. Status foregrounds use `--ui-success`, `--ui-warning`, `--ui-danger` and `--ui-info`; their backgrounds are subtle mixes with the active surface.

## Existing components, consolidated

| Pattern | Component | Contract |
| --- | --- | --- |
| Page title / context / actions / status | `PageHeader` | One H1; contextual description; actions wrap together. |
| Administration tabs | `WorkspaceTabs` | One active panel, arrow/Home/End keys, roving focus, linked labels. |
| Business status | `StatusBadge` | Shared semantic tones, EN/SQ display labels; never changes stored status. |
| Confirmation | `ConfirmDialog` | Action, named entity and consequence; safe Cancel, explicit destructive action. |
| Dialog / focus / Escape | `WorkspaceModal` + `useDialog` | Portal, focus trap/restoration, busy guard and existing dirty-form support. |
| Search / active filters / row actions | `SearchField`, `ActiveFilters`, `RowActions` | Preserve existing accessible controls and source navigation. |
| Business totals / next step / progress | `business-facts`, `NextStep`, `WorkflowSteps` | Current business facts first; technical evidence behind disclosure. |
| Money / quantities / dates | Existing business formatters and finance `Amount` | Preserve transaction currency, right alignment and tabular figures. |

Positive completed states use green; in-progress states blue; attention amber; failure/blocked/revoked states red; historical, unconfigured and unprobed states neutral. An optional unconfigured service is not a failure. Unknown fiscalization outcomes explicitly require verification.

Use intentional table containers for dense accounting/operational data. A table may scroll; the entire page must fit its viewport. Components that depend on a stylesheet import it themselves, including direct URL entry. Do not depend on visiting another module first.

Preserve existing role/capability checks, account permissions, API validation, session confirmation and authoritative business calculations. Presentation labels do not replace source evidence. Internal identifiers remain available in advanced details.
