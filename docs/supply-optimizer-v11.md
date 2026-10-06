# Supply Optimizer V11

Location: **Intelligence → Supply Optimizer**. Requires the existing analytics,
inventory, procurement and financial-view permissions together.

## Runtime

Install the local, pinned solver once (no API key or recurring charge):

```
python -m pip install --target backend/ml/optimizer_vendor -r backend/ml/supply_optimizer_requirements.txt
```

Set the existing `AIMS_ML_PYTHON` executable and apply the new migration. Run
`php artisan supply-optimizer:work` and the normal Laravel scheduler as managed
background services in web deployments. Windows development can use
`scripts/start-supply-optimizer.ps1`. Electron starts the queue and reconciliation
silently; its packaging scripts bundle NumPy/SciPy/HiGHS into local Python.
No new installer was built as part of this feature implementation.

## Formulation and evidence

V4 math and canonical inventory positions feed V5 evidence, including V6 dated
shipment timing. V7 provides separate recorded commitments and dated cash context.
V8 demand commitments are already in canonical ATP; they are not added twice.
Approved V10 policy weights are frozen; service-first/lower-commitment are named
comparison profiles, not production promotions.

A binary multiple-choice MILP chooses one valid purchase/transfer/timing bundle
per product/warehouse scope. Global integer currency-cent and milliunit donor
constraints coordinate all selected scopes. Donors require reconciled local
history and retain their own horizon demand and safety floor. Unknown transfer
arrival excludes the route unless the user explicitly declares transit days.

The bounded candidate set includes zero actions, MOQ/pack-rounded quarter,
half, three-quarter and full horizon requirements, bridge quantities, now/later
timing, up to six eligible catalogue suppliers, valid two-supplier splits and
up to two protected donor sources per bundle. Large sets retain up to 90
candidates per scope across cost/service/approved-policy orderings.
Limits: 40 products, three selected warehouses, 120 scopes, 12,000 binaries,
12 seconds total solver allowance. OPTIMAL means only optimal over this frozen
candidate set—not the unrestricted continuous network. FEASIBLE is a verified
integer incumbent without a proof of optimality. Infeasible/time-limit/failure
are never silently converted into a valid solution.

Service, stockout-day/deficit exposure, risk, excess, commitment and transfer
complexity are normalized penalties, not invented euro losses or probabilities.
Prices/FX use existing fixed-precision PR calculations; the limit covers NEW
purchasing commitments, not cash. Recorded landed-allocation allowances remain
explicit historical estimates, not fabricated freight quotes.

## Safe workflow

Select scope and constraints → generate asynchronously → compare → inspect
supplier/warehouse/quantity/timing details → explicitly confirm draft preparation.
The company lock, immutable plan, fresh fingerprints and existing open-PR checks
guard preparation. Suppliers, price, stock, forecast, incoming, conversions and
donor resources must still match. A retry returns the same prepared drafts.
Existing PR approval/sourcing and transfer dispatch controls stay unchanged.
No final PO, supplier transmission, payment, stock movement or journal is created.
PR destination/supplier instructions are advisory because the existing PR does
not own warehouse/supplier assignment; review that assignment during sourcing.

Dirty markers are cheap after-commit events. A bounded worker marks material
changes stale (10% ATP/demand or meaningful supplier/incoming/sourcing/policy
changes); confirmation always uses exact evidence even below that threshold.
One aggregate Action Center task per plan avoids product-level task spam.
Historical/restored plans cannot authorize drafts without re-optimization.

Optimized, human-reviewed, prepared and actual execution evidence are separate.
V10 journals immutable source references; authoritative confirmed POs and
dispatched transfers—not drafts—produce execution observations. Full future
closed-day stock evidence is required before recording a sampled outcome.
No promotion or causal effectiveness claim follows automatically.

## Scenarios, assistant and limitations

Named tests: demand +20%, supplier receipts +10 days, dated incoming POs +7 days.
Users can select a proposed supplier or an actual linked shipment for targeted
delay tests, and delay frozen V7 dated collections; undocumented
cash consequences are unavailable. Resilience labels use additional shortage-day
exposure in these named tests; missing demand gives UNKNOWN, never a fake score.
Changing the commitment limit creates a new plan and preserves the previous one.
The eight optimizer tools expose evidence/derived simulations only. The assistant
queues the real optimizer and links structured results; it never invents a plan
or creates workflow drafts.

Quantity drill-down compares nearby valid bundles with the same suppliers and
timing, using frozen quantities, fixed-precision commitments and shortage/excess
effects. These local comparisons are not a claim that the whole company can buy
more under its shared limit. Actual execution and completed sampled outcomes
update quietly when their source plan changes; no full-page reload is performed.

Supplier capacity, contractual supplier order minima, CBM/weight/container limits,
shipping savings and authoritative holding/lost-sales monetary costs are not
recorded in the present catalogue. Those constraints are explicitly unsupported,
not invented. Catalogue prices are estimates, not valid accepted RFQ quotes.
Warehouse demand can be sparse, so transfers are deliberately conservative.
There is no paid/cloud solver fallback and no automatic V12 development.

## Final verification — 6 October 2026

The follow-up review repaired confirmed remaining gaps without rebuilding the
optimizer or changing stock, purchasing approvals or accounting:

- Exact draft revalidation now fingerprints all stable planning inputs, including
  safety/reorder thresholds and company-versus-warehouse scope. Material threshold
  changes also invalidate a plan before confirmation.
- Conflicting hard stock ceilings yield INFEASIBLE, not an internal failure.
  Hard critical coverage cannot claim feasibility without qualified demand data.
- Bounded maintenance rotates through older plans instead of repeatedly visiting
  the newest ten. Dirty generations survive unfinished sweeps and newer events.
- V9 resolves product names/SKUs against the frozen plan, including products beyond
  the first eight cards. Unpurchased and marginal-quantity questions use structured
  decisions. Alternatives, reasons and solver failures have readable UI labels.

Verification completed:

- Backend optimizer and optimizer-assistant regression tests: **32 passed,
  268 assertions**, 53.07 seconds.
- Existing intelligence-assistant backend tests: **16 passed**, 114 assertions.
- Local Python solver tests: **8 passed**.
- Frontend tests: **54 passed**.
- Final browser checks: **4 passed**, including real solving, alternatives,
  explicit confirmation, idempotent draft creation, assistant tools and mobile/
  bilingual rendering. All browser fixtures use the isolated e2e database.
- Frontend production build: **passed**, 34.36 seconds. Existing large PDF/main/
  3D chunk warnings remain; no new animation dependency was added.

A synthetic 40-scope, three-warehouse-index, six-supplier test solved 1,020 binary
candidates in 0.063 seconds; its balanced and service-first alternatives each
used EUR 79,537.50 within the EUR 80,000 ceiling. This is test evidence, not an
actual company purchase recommendation or a full unrestricted-network benchmark.
Recorded-data limitations and the bounded-candidate optimality qualification
above remain intentional. No new roadmap phase or signed desktop installer was
produced by this verification.
