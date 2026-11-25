# Proposed DC40 business intelligence reliability plan

**Planning only.** This specification follows the [DC39.2 evidence](DC39_2_COMPREHENSIVE_PRODUCT_AUDIT.md) at `d9117ae18ee2f5dbaf3ead573df8b95c928754db`. DC40 has not begun. Real-company launch and production fiscalization remain gated.

## Entry criteria and scope

The [DC39.3 corrective report](DC39_3_ACCOUNTING_INTEGRITY.md) records the proposed accounting changes. Review and publish the exact corrective source before DC40 engineering, then require both hosted backend jobs to pass on that commit. Establish explicit source status for reported profit, tax, inventory, liquidity, FX and assets. Set accountant-approved scope for any unsupported accounting cases. Synthetic independent acceptance may exercise missing evidence and exclusions.

Prioritize common questions an owner, accountant, buyer, salesperson or warehouse user can act on. Target trustworthy figures, time period, data completeness, relevance and one authorized source action. Reuse the optional local Qwen/Ollama deployment, existing calculation services, tool registry and answer components. Work is limited to the quality of read-only interpretation and source explanations. Keep buying, closing, payment, posting and adjustment decisions with existing workflows. Broader native automation, new models, paid inference, simulated statutory filings, duplicate analytics and unrelated product areas fall outside scope.

## Deliverables and technical sequence

### 1. Canonical question and validated plan

Improve the existing `AssistantBusinessIntent`, `AssistantLanguage` and `AssistantPlanner`. A plan should carry concept, measure, date boundaries, entity, currency, comparison, evidence requirements, confidence and relevant permissions. Keep exact names and fuzzy lookup separate from classification. Give clear questions the current fast path. Invoke the existing optional local model only for useful ambiguity or multi-intent reasoning, within the current timeout and tool limits. Validate its proposal against scoped typed tools and actual source dates. Selecting unrelated intent, guessing IDs or falsely labeling future demand as fact must fail closed.

Use explicit distinction between sales revenue, booked sales, gross contribution, operating profit, pre-tax profit, income tax, net profit and bank cash. Bind “this month” to business date/timezone, compare like-for-like periods and display effective dates. Keep a bounded same-user, same-tenant conversation reference and carry the measure/entity into relative-time follow-ups. Verify permission again per turn. Ask a single concrete question if dates, currency, product or operation are genuinely ambiguous.

### 2. Source adapters over existing services

| Priority | User tasks and route expectations | Authoritative reuse and required boundary |
|---|---|---|
| A | Explain monthly movement, profitability, posted tax and what changed | `AccountingService`, reporting queries and `FinanceAssistantFacts`. Admit separate pre-tax/current-tax/net fields. Display posting basis, source dates, cost coverage and approval/readiness. Withhold difference explanations when evidence is incomplete. |
| A | Cash versus profit, overdue AR and purchase capacity | Dated reconciliation, receivable/payable reports, cash-flow and existing purchase finance. Compare known balances across dates and distinguish cash from accrual. Keep unknown liabilities, restricted cash, opening and planning amount separate. |
| A | Product gross contribution, 90-day inactivity, inventory capital | Economic sales ledger, contribution, costing and inventory planner. Distinguish sales sort, profit and no-sale windows. Deduplicate sales and returns. Show incomplete historical costs and unmatched source rows. |
| A | Fixed asset value and realized versus unrealized FX | Existing dated asset register, GL source query and revaluation evidence. Tie value and expenses to authorised evidence. Separate ledger zero from unknown exposure or approval. Historical cost stays outside foreign revaluation. |
| B | Supplier changes, fabrics to reorder and import-related timing | Supplier catalogue, dated quotes, comparison, inventory planning and shipment evidence. Normalize goods/units/terms before comparison. Require credible origin, stock and demand. Show missing evidence and actual request next step without sending a purchase. |
| B | Manager priorities and customer/customer-product patterns | Executive brief and Action Center plus current evidence-driven customer views. Avoid counts duplicated across tasks. Present stated urgency, owner and verified target. |
| B | Payroll, VAT and other role-restricted queries | Reuse existing typed summaries and authorization. Provide source links only when the role has access. Distinguish internal review, ready export and external acceptance. |

Every source adapter returns structured value, units/currency, exact period, allowed source link and evidence status. Use clear states for no records, incomplete evidence, ambiguous reference, missing connection, permission denied and verified operational exception. Client presentation can format values but derives no new accounting totals. An unsupported action links to the appropriate authorized page where available. One numeric result must have an independent expected amount and a source reference. One compound explanation must document each required premise.

### 3. Relevance and user experience

Before composing, verify domain, measure, entity, dates, role and tool result match. Reject sales value for gross profit or inactivity, vendor receipt for supplier payables, and a write-safety lecture for an FX read. Support clearer Albanian including ordinary Kosovo phrasing and mixed business terms. Avoid adding known acceptance prompts as keyword cases. For an unavailable number, tell the user which evidence is missing and where to inspect it. Use concise answer-first controls, one next action and expandable sources. Keep normalized output, effective period and caveat visible to screen-reader and keyboard users.

### 4. Independent evaluation and regression

- Preserve original Ask40 and preserve historical scoring with its own dataset/run identity. Reproduce semantic scoring only when fixture, role and evaluator are comparable. Keep DC39.2 transport counts separate. Add a frozen development corpus for the 18 baseline cases and an independent held-out corpus never used in routing tuning. Include English, Kosovo Albanian, ambiguous references, multiple periods, mixed units, paraphrases, restricted roles, wrong-domain intent and explicit unsupported write requests.
- Use an independently computed SQL or small-fixture oracle with at least two comparable periods, a known increase/decrease, customer AR with one excluded advance, matched and unmatched receipts, partial pricing evidence, fractional fabric quantities, depreciation/reversal and different realized/unrealized FX values. Run against synthetic fixtures. Test qualified no-history, uncertainty and disabled local inference deliberately. Record before/after protected business digests.
- Score intent and tool routing, correct period/entity and values, evidence coverage, language clarity, next action and final PASS/PARTIAL/FAIL. Record paths as deterministic, actual native-assisted or fallback. A correct fallback is a product pass within that mode and supplies no native reasoning evidence. Hold-out first exposure is immutable. Include each mode's sample size.
- Measure same-machine cold/warm p50 and p95 separated into service wait, optional model, tool and response handling using a paired run. Measure the ERP during inference. Maintain deterministic routing responsiveness compared with the matched baseline, investigate meaningful regression and record hardware/model bounds. `434–607 ms` in DC39.2 is a warm local synthetic envelope for 18 requests and insufficient as a universal SLO.
- Exercise buyer on purchasing preview, accountant on financial navigation, warehouse denial on protected figures and ordinary employee guidance. Run focused service, permission and frontend tests, production build and required relevant released CI. Route all writes through existing human flows; evaluation does not create financial transactions.

### Exit gates

| Gate | Measurable acceptance |
|---|---|
| Accounting dependency | Both P0 tax probes turn into negative/positive regressions, and both posting domains initialize independently without chart misuse. Full SQLite and MariaDB pass on the same reviewed SHA. |
| Financial integrity | No unverified affordability, fictitious posted balances, invented tax approval or wrong reporting period. All fixture numeric answers exact to supported precision or explicitly withheld for the right reason. |
| Held-out quality | Proposed threshold at least 85% complete (PASS) on an independent balanced natural sample, reported per EN/SQ and role, with zero wrong-domain presented figures and zero unauthorized disclosures/actions. A release scope can be narrowed and requalified if broader coverage falls short. |
| Native support | Run paired native/disabled cases where the exact runtime is available. If unavailable, label native NOT PERFORMED and qualify deterministic-only behavior separately. |
| Operations and UX | Source links honor role and date context. Buyer, accountant, warehouse and sales paths usable in technical browser checks at laptop/mobile widths. Minority-language and accessibility states checked. Real participant acceptance is its own DC42 gate. |
| Evidence | Source version, model/runtime config, exact fixture/expected digests, first exposure outcomes, latency and CI links retained without credentials or prompt PII. |

## Next checkpoints

- **DC41** qualifies the exact release source and staged dependencies, licence and entitlement lifecycle, signed installer/updates, Windows setup/reboot/recovery/rollback, isolated runtime boundaries, populated migration and backup remapping, telemetry/retention and support. Resolve unqualified advisories and record independent build and recovery gates individually.
- **DC42** uses the DC28 participant guide for actual employee completion by role. Original-company commissioning requires the documented financial opening and source resolution, signed accountant decisions, applicable ATK/SEF evidence, encrypted recovery, target security and explicit authorization. A passing synthetic demo cannot authorize launch.
