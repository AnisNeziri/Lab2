# AIMS Intelligence V12 — Strategic Simulation

## Scope and workflow

Open **Intelligence → Strategic Simulation** (`/strategic-simulation`). Select products and, optionally, warehouses and suppliers; choose a horizon; add business assumptions; review and explicitly confirm the run. Results and definitions are saved automatically as versioned simulation records.

The workspace provides baseline/scenario comparison, inventory and financial timelines, optimized response alternatives, bottlenecks, sensitivity, saved-run comparison, frozen-baseline derivation and a new run against current data. English and Albanian labels, existing light/dark tokens and responsive layouts are supported. Background status updates do not reset an edited form or reload the page.

## Architecture

`StrategicSimulationService` persists and authorizes runs, freezes evidence, dispatches the dedicated queue and controls the explicit response-plan boundary. `StrategicSimulationEngine` calculates with copied arrays; it has no model/database/event writes.

Calculation order:

1. Freeze qualified current evidence and approved policies.
2. Apply demand/customer changes to one demand stream.
3. Apply supplier/logistics changes to existing dated supply.
4. Project inventory and identify requirements.
5. Optionally call V11's existing candidate generator and local MILP solver.
6. Pass recorded obligations and hypothetical response commitments through V7.
7. Return comparisons, limitations and observed bottlenecks.

Existing authorities are reused rather than replaced:

- V4 `InventoryPlanningMath` and qualified planning evidence.
- V7 `FinancialIntelligenceEvidence` and `FinancialForecastMath`.
- V8 customer evidence and canonical fulfilled-sales history.
- V10 approved current/champion planning policies.
- V11 `SupplyOptimizationCandidates` and `LocalSupplyOptimizer`.
- V9's existing registry, tool runner, assistant and safe source links.

The two shared calculation adjustments are optional frozen `as_of` dates and an optional purchasing-delay input. Their existing defaults remain unchanged. Focused compatibility tests cover the original planning, optimizer and assistant behavior.

## Supported assumptions

| Family | Changes |
| --- | --- |
| Demand | Growth/downturn, scoped product/category changes, optional start/end period |
| Supplier | Temporary unavailability, removal, lead time, catalogue price, MOQ, recorded reliability deterioration |
| Logistics | Dated incoming delay, selected shipment delay, recorded origin/destination route delay |
| Inventory | Safety quantity, existing service target, inventory reduction, additional cover days |
| Warehouse | Temporary inability to supply, quantity-conserving hypothetical rebalancing |
| Procurement | Commitment ceiling, purchase delay, supplier restriction, replenishment multiplier, split purchasing |
| Financial | Collection delay, earlier obligation payments, recorded expense/payable amount change |
| Customer | Demand growth, temporary inactivity, selected-product additional quantity, payment delay |
| Combined | Multiple reviewed assumptions in one dependent world state |

Temporary warehouse closure delays actual receipts, hypothetical rebalancing and optimized response availability until reopening. V11 re-scores the same bundles on these delayed timelines, so the solver cannot claim an early response resolves a closed warehouse's exposure.

Eight templates are editable starting points, not forecasts or silently applied scenarios. Record-specific templates require real selections and confirmation.

## Evidence, precision and horizons

Runs preserve `baseline_at`, source references, engine version, model/policy versions, creator, parent lineage, assumptions and results. Frozen input/result fields are immutable after capture. Deriving keeps the original scope, horizon and baseline; changing scope/horizon requires a current-data run. Comparing different baselines is explicitly qualified as a historical comparison, not solely an assumption effect.

Demand uses approved forecasts or qualified historical observations. Customer percentages use canonical sales reconciled to warehouse movements where available. A company-share fallback is explicitly labeled as a distribution assumption. Additional customer quantity across multiple warehouses requires one destination, preventing duplication.

Horizon options are 30, 60, 90, 180 and 365 days. The normal qualified supply-response horizon is at most 90 days. Longer inventory timelines require explicit acknowledgment to repeat the frozen demand pattern; otherwise unsupported coverage is reported. The first 90 days are the only period claimed covered by V11 optimization. V7 can project recorded obligations over the full chosen horizon.

Money uses existing fixed-precision helpers. Financial currencies remain separate. Unknown opening cash, costs, dates and evidence remain unknown, not zero. Scoped known-price estimates are qualified; a missing cost on either side suppresses a misleading monetary delta. Inventory valuation here is scoped available-stock valuation, not the company balance sheet.

Hypothetical response purchases assume full payment on order date at frozen recorded FX in company currency. This is a comparison assumption, not an authoritative future payable. Fixed contractual due dates are not silently moved with delayed goods; only explicitly arrival-dependent obligations move. No future sales revenue, transfer cost or monetary stockout loss is invented.

## Deterministic analysis and limitations

The engine is deterministic and local. Sensitivity evaluates 2–15 validated discrete values without repeatedly running optimization. Reported breakpoint points bracket tested vulnerability only; they are not precise continuous thresholds. Bottlenecks rank observed stockout consequences and show supplier/data gaps; they do not claim causal attribution or an unexplained resilience score.

Monte Carlo is deliberately unavailable: calibrated joint demand, lead-time and payment distributions are not established. No arbitrary random distributions, paid APIs, cloud solver or recurring AI dependency were introduced.

Other unsupported dimensions are identified in results: unrecorded supplier capacity, alternative transport routes, transfer/freight costs, container savings, lost-sales costs, guaranteed service fill rates and unqualified warehouse history. Natural-language requests cover common typed scenarios and follow-ups; complex selectors/periods belong in the builder rather than being guessed.

## Assistant tools

The existing permission-aware tool layer exposes:

- `create_simulation_scenario`
- `run_strategic_simulation`
- `get_simulation_result`
- `compare_simulations`
- `get_simulation_impact`
- `run_sensitivity_analysis`
- `get_simulation_bottlenecks`
- `optimize_simulation_response`
- `explain_simulation_result`

Example conversation: “What happens if demand grows 20%?” → “And China Factory A is 10 days late.” → “Keep purchases under €70,000.” Each follow-up creates a derived frozen-baseline run with preserved assumptions and lineage. The assistant invokes structured calculations; it does not calculate business values itself or prepare real drafts.

## Isolation and response-plan boundary

A simulation may write only its own run/audit state and queue bookkeeping. Existing diagnostic assistant audit entries and read caches are not operational business changes.

It does not create or change inventory, stock movements, PRs/POs, shipments, suppliers, customer balances, journals, cash, demand forecasts, normal analytics snapshots, production policies, Action Center tasks or Automation Studio events. `simulation.completed` is stored in isolated run audit data; no production operational event is published.

**Prepare Response Plan** is a separately confirmed, permission-protected action. It creates an idempotent fresh V11 review plan from current actual data and links to `/supply-optimizer?plan=…`. The user's declared commitment ceiling may remain a real planning constraint; hypothetical prices, demand, inventory, supplier availability and response quantities are not copied into operational instructions. Subsequent drafts and approvals remain V11's independent human-controlled workflow. This boundary creates no PO, PR, transfer, movement or journal itself.

Every route/tool requires the complete cross-domain permission set: `analytics.view`, `analytics.finance`, `inventory.view`, `procurement.view`, `finance.view`, `financial_accounts.view`, `customers.manage`, `daily_sales.manage`, `shipments.view`. Preparing a current-data response also requires `procurement.manage`. Company scopes apply to inputs, runs and results; queued jobs recheck the original requester's active account and permissions.

## Runtime

The dedicated database queue is `strategic-simulation`. Start its hidden local worker with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/start-simulation.ps1
```

For service deployment, supervise `php artisan simulation:work`. The worker also supports `--once`. Keep the existing `supply-optimizer:work` worker available for explicitly prepared V11 plans. Simulation jobs have one attempt and a 240-second timeout; queued/running work can be cancelled safely. Cancellation discards a result even if the bounded local calculation is already finishing.

Scope is bounded to 40 products, 3 selected warehouses, 6 selected suppliers and 24 assumptions. The existing solver receives a 12-second limit. The browser polls a compact state response, fetching full results only when the run changes. No fake progress percentage is shown.

Desktop source starts and stops a hidden simulation worker with the bundled local backend. This update includes desktop source integration and a successful frontend production build; it does not claim a newly rebuilt/signed installer.

The existing web database received only the additive `strategic_simulations` migration. No production reset, reseeding or business-data deletion was used.

## Verification

Focused checks include baseline freezing, same-input reproduction after advancing the clock, no double demand/incoming counting, supplier disruption/removal, warehouse conservation/closure, financial currency handling, customer changes, long-horizon limits, V11 commitment ceilings, V9 lineage, tenant/permission isolation, duplicate requests, cancellation and explicit response preparation.

Isolation checks compare hashes of business tables before/after scenarios, including analytics, Action Center and automation persistence. Only simulation/queue/cache/session bookkeeping and existing diagnostic audit tables are excluded. The browser fixture is guarded to `APP_ENV=e2e` and `e2e.sqlite`; it does not use the live company database.

Representative browser scenario: two warehouses, upholstery fabric, Chinese/local suppliers, an incoming PO/shipment, receivables, supplier obligations and recorded bank cash; demand +20%, Chinese supplier lead +12 days, shipment +10 days, major customer +30%, collections +7 days and a €90,000 commitment ceiling.

Observed fixture result: purchasing requirement estimate €760 baseline → €1,450 scenario. The balanced response committed €2,005, reduced two stockout scopes to zero and retained one safety-target gap. No qualified donor surplus existed under this stress, so no unsupported transfer was proposed. Calculation was approximately one second in this small fixture; this is not a large-catalogue performance guarantee.

The representative browser workflow also exercises explicit confirmation/back, double-click protection, quiet background updates, saved lineage, sensitivity, the fresh V11 review link, widths 1440/1024/768/390, Albanian/dark mode and zero JavaScript page errors.

Final checks executed on this implementation:

- Focused backend compatibility set (`StrategicSimulationTest`, `InventoryPlanningTest`, `SupplyOptimizerTest`, `IntelligenceAssistantTest`): **72 passed, 628 assertions**.
- Focused frontend presentation/navigation/assistant tests: **20 passed**.
- Local Python simulation/solver tests: **3 passed**.
- Representative Chromium workflow: **1 passed**.
- Frontend production build: **passed** (22.76 seconds); existing unrelated main/PDF/3D chunk-size warnings remain.
- Desktop main-process syntax check, new PHP formatting and whitespace checks: **passed**.

Simulation isolation verified: **YES**. Operational mutation from simulation: **NONE**. Production automation leakage tested: **YES**. Baseline reproducibility tested: **YES**. Combined cross-domain scenario tested: **YES**.

## Recommended next phase

Calibrate and validate simulated consequences against recorded supplier receipts, warehouse demand, payment timing and realized outcomes. Establish joint uncertainty evidence before adding stochastic claims. No V13 work is started by this implementation.
