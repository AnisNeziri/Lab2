# Enterprise Decision Intelligence V5

Open **Inventory Intelligence → Decision Center**. V5 reads V1–V4 evidence and canonical stock, supplier, procurement, shipment, quality and financial services. It does not train models during requests or require a paid runtime API.

## Workflow

1. The background worker evaluates changed products. **Evaluate a product** performs the same bounded advisory calculation immediately, optionally for one warehouse.
2. Review a card, choose a ranked alternative, inspect observed facts, predictions, constraints and unknowns.
3. Compare quantities, supplier, demand multiplier, delay or a separately authorized transfer using V4's read-only scenario service.
4. **Review PR draft** obtains current authoritative evidence. Explicit confirmation creates only an existing Procurement Purchase Request draft. Existing approvals, RFQs and PO conversion remain unchanged.
5. Stock/price/MOQ/ETA/forecast/commitment changes invalidate the confirmation token. A duplicate confirmation returns the original PR. Another open PR for the product blocks a duplicate draft.
6. Accept, modify or dismiss a decision with a note. An actual transfer can be linked only after dispatch and only for the same product/destination after the decision's generation.

## Decision types

Replenishment; transfer vs purchase; supplier selection; purchasing timing; incoming-stock risk; excess-stock action. Each type is emitted only when its evidence indicates a relevant condition. Excess actions require review of redistribution or future purchasing, never automatic cancellation. Logistics delays do not rewrite supplier training labels. Undated, overdue or delayed incoming stock is not considered a firm on-time arrival.

## Ranking and uncertainty

`config/enterprise_decisions.php` owns versioned, bounded weights: stockout 40, availability 20, delay 12, quality 8, relative cost 10, excess 6, recorded cash context 4. Lower weighted risk is preferred; tie-breaking is stable. Stockout risk balances unmet planning target and projected horizon shortages. Arrival lateness is measured against the stockout window. Scores are not probabilities or guaranteed service levels. Unknown supplier delay/quality and unavailable cash receive neutral uncertainty penalties, never fabricated zero risk. Cost is compared in company currency, with existing fixed-precision Money arithmetic.

No valid forecast: use configured safety/reorder thresholds and exact unit/MOQ/order-multiple rules, label **Limited Intelligence**, and do not invent coverage or stockout dates. A sparse-data donor warehouse is protected by the company configured floor and explicitly requires local-demand review. Unknown transfer arrival is exposed and must be separately confirmed; a PR cannot execute a transfer.

Finance totals are advisory, with linked PO obligations and unlinked supplier documents kept separate. Recorded cash is not assumed to be complete or available for spending. No journal, payment or cash reservation is created.

## Persistence and refresh

One additive `enterprise_decisions` table stores compact immutable evidence/alternatives, stable company/type/product/warehouse identity, versions, lifecycle history, PR/transfer references and observational outcomes. Operational ledgers are unchanged. Ordinary list/detail requests read stored decisions. Finance and restricted-domain evidence is redacted using existing permissions.

Business events mark products dirty after commit. `php artisan decisions:refresh` is scheduled every minute, guarded by company locks, a global time budget, product batch limits and bounded warehouse scopes. Periodic cursor reconciliation recovers missed events. Reads flag changed or stale evidence and lower displayed confidence. Material quantity, risk, supplier, forecast, policy or commitment changes supersede old versions. Unchanged conditions do not generate duplicate decision events or Action Center items.

Seven `intelligence.decision.*` events are registered in Automation Studio. Only human-safe existing actions are exposed. Action Center shows one meaningful decision per product and suppresses duplicated V4/legacy replenishment items. Eight read-only Tool Layer capabilities expose decisions and simulations.

Portable backups include decision history. Restored decisions are archived/superseded, with regenerated identities and archived source references; they cannot authorize new actions. Reconciliation must build fresh decisions from restored operational records.

## Outcomes and current limitations

PR awards link to existing POs and posted receipts. Actual transfer status, receipt quantities/dates, inspection defects and complete post-decision stock observations are tracked. Incomplete observation windows remain unknown. These are observations, not proof AIMS caused savings or prevented shortages. Sparse live data remains limited until genuine closed sales, reconciled warehouse demand and qualified receipt history accumulate.

No customer payment-risk model, cash-flow forecast, purchasing-budget optimizer, manufacturing, multi-company optimization or autonomous action is part of V5. The next sensible phase is shipment-stage delay intelligence and improved genuine outcome collection, not a runtime chatbot.
