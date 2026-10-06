# Adaptive Decision Learning V10

V10 is local, advisory and append-only. It adds no autonomous purchasing, stock movement, accounting entry, customer contact, reinforcement learning or paid service.

## Evidence architecture

`decision_learning_records` is a lightweight derived journal, not a replacement model registry. Frozen V5 recommendations, separate human responses, completed inventory/supplier observations, existing V1/V3/V6/V7/V8 prediction evaluations, policy configurations, prospective experiments and explicit policy decisions are distinct immutable records. Existing model versions and operational authority remain unchanged.

New V5 decisions freeze their cutoff, alternatives, original V4 input, ranking context, policy/model versions and assumptions. Older snapshots are imported as historical evidence only; historical shadow predictions are never reconstructed. Response snapshots preserve recommended versus selected quantity and the existing PR → award → PO → receipt chain. Acceptance is not a success label and dismissal is not a failure label.

Inventory decision windows use the original horizon, bounded to 7–30 completed days. Every day requires a genuine unit-matched stock observation, with the referenced source stock captured on that date; missing or unverifiable days remain pending. Fulfilled demand, sampled stockouts, recorded shortage intervals and ending stock stay separate. Ending excess follows V4's original safety-stock definition; mean quantity above the declared stock ceiling is an additional distinct dimension. Intraday lost demand/revenue and unobserved cash effects remain unknown. Supplier receipts, stages, quality and claims reuse `SupplierHistoryService`; total lead-time delay is not assigned to supplier fault. Existing ETA, matched-account cash/collection and customer reorder/activity evaluators are reused without changing definitions. Unacted product affinities are not failures.

## Policies and experiments

The approved company policy is the only V5 production policy. Bounded quantity multipliers (0.8–1.2) and the existing bounded seven-criterion ranking weights may be proposed. Existing MOQ, pack/unit conversion, stock ceilings, permissions and all transaction/approval/accounting guards still apply. Category/product specialization is deliberately disabled on sparse evidence.

An authorized company administrator can create a shadow experiment. Its original champion, challenger, configuration, start time, population and metrics are frozen. Only future replenishment decisions receive shadows. No future outcomes enter shadow inputs. Same-supplier, single complete receipt comparisons use genuine observed windows with clearly labeled paired quantity scenarios: receipt timing and other actual movements are held constant. This is not observed counterfactual truth or proof of causation. Different suppliers, unacted decisions, incomplete receipts or unsupported timing do not qualify.

The default gate requires 50 independent non-overlapping comparable windows across at least five products, a meaningful 5% dimension improvement, no sampled-service/excess/purchase-cost deterioration, and stability across both chronological halves. Evidence older than 180 days and changed unit/ceiling/planning/supplier regimes is excluded. Eligibility is only a review recommendation. No background job promotes a policy. Promotion requires administrator permissions, a reason, explicit confirmation and a matching expected champion version. Rollback is limited to the previous champion of the latest promotion. The immutable policy-change record preserves approver, date, reason and comparison evidence. Promotion and rollback never rewrite old recommendations or perform operational actions.

## Access, UI and operations

Intelligence → Decision Learning contains Overview, Recommendation Performance, Human Decisions, Outcomes, Champion vs Challenger, Policy Suggestions and Data Sufficiency. It supports domain/type/date/version filters, English/Albanian and light/dark responsive layouts. Finance evidence requires finance + cash-account permissions; supplier/customer/shipment evidence respects existing domain access.

Seven read-only tools expose summary, performance, outcome, comparison, challenger status, override patterns and suggestions. The V9 assistant routes learning questions through these tools. Promotion/rollback are not assistant tools. Six deduplicated learning events are available to Automation Studio. Action Center receives only review-worthy aggregate signals; no task is created per outcome.

`php artisan decision-learning:maintain` runs bounded incremental reconciliation, scheduled hourly and after the desktop's existing hourly intelligence maintenance. Per-company/source cursors progress through older sources and revisit updated evaluations, with a 15-second worker budget and bounded batches. `decision-learning:status` reads captured/completed counts. Read endpoints do not recalculate history, train or write business data. Portable analytics backups include the journal, but restored evidence is archived and cannot activate imported policies or authorize actions. Legacy V1/V4 advice and human choices are imported from their existing records; only already-completed operational windows with verified daily stock qualify as outcomes.

## Limits

Prediction outcomes are evaluable across inventory, suppliers, shipments, finance and customers. End-to-end decision scorecards and policy experiments currently have reliable inventory/linked-supplier action chains; other domains retain separate prediction evaluations rather than inventing action attribution. Missing operational evidence keeps windows pending. No-policy-with-enough-real-evidence is a valid production state, not a reason to promote a test challenger.
