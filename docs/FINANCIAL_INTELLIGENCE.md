# Financial & Working Capital Intelligence (V7)

Open **Intelligence → Financial Intelligence**. Access requires `analytics.finance`, `finance.view` and `financial_accounts.view`. V7 is advisory; it cannot post journals, pay, approve, change debts/valuation, or create orders.

## Authorities and evidence

- Opening cash: dated opening balances plus posted cash-account transactions known by the evidence cutoff. Future-dated posted cash transactions are scheduled movements, not opening cash. Accounts remain separate by currency. Complete coverage must be explicitly confirmed; otherwise “Complete cash position unavailable” remains visible.
- Receivables: existing customer-credit oldest-due-first allocations, remaining amounts only. Invoice-linked debt and invoice documents representing the same Daily Sale are counted once. Issued invoices without ledger representation are separate sources. Already-received advances are shown separately, never added as future inflows.
- Supplier documents: existing Expense remaining balances (direct payments, allocated PO deposits and supplier credits).
- PO remainder: only the uninvoiced value less unallocated deposits. Linked supplier documents take precedence. Currency mismatch excludes the unknown PO remainder rather than counting both.
- PRs and V4 recommendations are potential stages, excluded from actual cash requirements. Scenarios represent **additional** purchasing, not automatic replacement of existing commitments.
- Inventory: existing product `inventory_value`; warehouse allocation uses existing weighted-average cost × quantity and is qualified accordingly. Unvalued items are unknown. Slow/excess stock remains an asset.
- Allocated landed cost is not another cash payment. A posted supplier/expense document supplies the obligation; draft landed costs remain recorded estimates.

## Forecasts and scenarios

7/30/60/90-day forecasts are immutable versions. Unknown future collection dates are excluded and flagged. Overdue supplier payments appear as immediate requirements, not guaranteed payment dates. Pressure is an advisory comparison to a configured cash threshold, not an insolvency assessment. No unsupported probability ranges are generated.

Documented arrival-dependent terms require an existing PO/document, a linked shipment and a contract reference. Only fresh V6 **warehouse** ETA shifts such payments. Ordinary fixed-date obligations do not move with shipment delays. These settings never edit contracts.

Scenarios can change collection/supplier timing, arrival-dependent timing and incremental purchasing. Product scenarios reuse V4 prices, MOQ, fixed packs and coverage. Split purchasing requires documented supplier permission, validates each portion and combines both hypothetical receipts in V4's daily projection. Freight economics and actual commercial agreement still need human review. Demand scenarios change inventory coverage; they do not invent future cash sales.

V5 reads saved financial context under the same permission boundary. Its decision details compare additional purchase commitments and hypothetical cash after full payment within the horizon alongside existing stockout exposure. This is not a contractual payment schedule. Cash context does not change the operational rank or conceal stockout trade-offs; first-time and materially changed financial evidence is refreshed without small-change decision spam.

## Observations and model governance

One immutable point-in-time observation per company/date is captured by scheduled refresh. It is not a reconstructed end-of-day balance. Recorded debt amounts/dates, outstanding partial balances, cash-payment timestamps, supplier documents/payments/credits and deposit allocations are preserved as evidence, never counted as additional future cash receipts. Predictions retain their original cutoff and evidence. Completed, matching-account cash windows can be evaluated; missing or mismatched windows are not scored.

Customer timing uses non-reversed, completed cash-payment allocations. Adjustments/cancellations never become payment-behaviour labels. Due date is the initial champion. The local historical-median challenger needs at least five completed customer samples. A local Python diagnostic independently checks cutoff-safe samples; runtime failure falls back to deterministic/PHP statistics. No cloud API or new recurring cost.

Promotion requires at least 20 independent completed obligation outcomes and at least 5% lower timing MAE than due-date baseline. Repeated forecasts of one payment do not create independent outcomes. Authorized users supply a reason; immutable policy/audit history records promotion and rollback. No production accuracy is claimed before genuine completed windows exist.

DSO = average compatible trade receivables / credit sales × period days. DIO = average inventory / COGS × days. DPO = average compatible trade payables / COGS × days. CCC = DSO + DIO − DPO. They remain unavailable here because compatible average balances and classifications are not yet proven. Current facts still form a clearly partial working-capital view.

## Runtime and recovery

Run `php artisan finance-intelligence:refresh` for an immediate advisory refresh, and `php artisan finance-intelligence:status` for read-only evidence health. The Laravel scheduler invokes refresh hourly. Production web installations must run Laravel's scheduler. The desktop source now chains this bounded refresh after its existing hourly analytics/intelligence maintenance, with hidden child processes. A previously built installer must be rebuilt to include these changes. Page GETs read saved summaries; scenarios reuse frozen evidence and only compute the selected V4 product.

Automation Studio exposes five `finance.intelligence.*` events. Material thresholds and active-task deduplication prevent small-change spam. Financial-source tasks contain no monetary values in generic titles or event metadata. Source permissions protect drill-down.

Read-only tools: `get_cash_forecast`, `get_financial_pressure_periods`, `get_receivable_intelligence`, `get_upcoming_supplier_commitments`, `get_inventory_capital_summary`, `get_financial_data_health`, `simulate_purchase_cash_impact`.

Three additive intelligence tables contain versions, observations and policies; no shared accounting schema changes. Finance backups include them. Restored evidence is archived/non-actionable, source-sensitive scheduling permissions reset, and a fresh tenant-local forecast is required.

## Validation

Focused Laravel tests cover remaining balances, deposits, direct payments, currency separation, cash timing, immutability, sparse history, tenant/finance guards, deduplication, arrival terms, completed windows and non-mutation. Python tests cover cutoff-safe median statistics and sparse/duplicate outcomes. Frontend presentation tests and one isolated wholesale-company browser flow cover bilingual mobile/dark mode and scenarios. Test fixture financial observations are never production accuracy evidence.
