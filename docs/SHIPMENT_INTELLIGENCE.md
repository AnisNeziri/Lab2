# Shipment Intelligence V6

Open **Shipments → Shipment Intelligence**. The existing shipment, container,
milestone, PO, receiving and stock records remain authoritative. This layer
explains arrival uncertainty and business consequences; it performs no stock
movement, purchasing or financial posting.

## Estimates and evidence

Carrier/AIS ETA, operational ETA, AIMS estimate and actual arrivals remain
separate. Estimates explicitly target either the port or the warehouse.
Recorded warehouse schedules take precedence. With at least five genuine
completed observations for the same route and mode, local historical medians
and descriptive p10–p90 ranges are available. Known port arrival and qualified
port-to-warehouse history may support the remaining inland estimate.

Without sufficient history, AIMS uses an available recorded schedule and shows
**Limited historical evidence**. It does not invent customs durations,
consumption, warehouse arrival, model accuracy or a replacement for an overdue
ETA. AIS freshness is displayed separately; vessel coordinates do not imply a
precise arrival prediction. Road, rail, air and multimodal shipments do not
require AIS. No paid API or new Python/ML runtime is introduced.
An early port arrival does not guarantee on-time usable stock when customs,
inland or receiving duration remains unknown. Exposure remains explicit and
the shortage duration is not fabricated.

## Inventory and decisions

Explicit shipment allocations are capped at the remaining PO base quantity.
Ambiguous split shipments and incompatible units are identified rather than
counted twice. The stock timeline uses the existing inventory-planning demand
qualification and stock/reservation services. V5 changes the timing of existing
incoming quantities; it does not add a second incoming quantity.
Multiple POs containing the same product retain separate allocated quantities
and arrival timing. Warehouse arrival alone does not hide a delayed receiving
stage; completed receipt milestones or fully received authoritative PO lines
remain distinct from physical arrival.

Critical risk requires business exposure, not lateness alone. Alternative
incoming stock, donor warehouse surplus, purchasing and customer-order reviews
are advisory links to existing workflows. Unknown transfer time remains unknown.
Supplier feedback contains only actual attributable supplier-preparation stages;
international transit is not relabeled as supplier preparation.

## Refresh and validation

The existing Laravel scheduler runs `php artisan logistics:refresh` every minute.
The worker has a company lock, a five-shipment batch and a 20-second global work
budget. Relevant source events queue invalidation; a 30-minute reconciliation
recovers missed invalidations. Ordinary list/detail/tool reads return saved
evidence, without recalculating routes or inventory. The web/desktop deployment
must run its existing scheduler for automatic refresh.

One additive `shipment_intelligence` table stores versioned frozen evidence,
predictions, cutoff/generation times and later outcomes. Meaningful milestone
changes append observations in existing shipment history; raw AIS positions do
not generate these observations or prediction versions. Historical corrections
do not rewrite prior frozen predictions. Backup restores archived intelligence
as non-current evidence; fresh evaluation establishes current relationships.

Route reporting uses the first eligible pre-arrival frozen prediction per
shipment/target. It reports MAE, signed bias and observed range coverage against
actual arrivals and an aligned baseline. Test fixtures never populate company
performance. This version is an interpretable baseline, not a trained or promoted
ML challenger. Collect genuine completed routes before comparing regression
challengers using held-out pre-arrival predictions.

## Integration and permissions

Action Center deduplicates current meaningful shipment risks. Automation Studio
exposes risk change, material ETA change, inventory exposure, stale tracking and
arriving-soon events. Notifications link to the shipment intelligence detail.
Seven existing Tool Layer entries are read-only and company scoped.

Viewing requires `shipments.view`; manual evaluation additionally requires
`shipments.manage`, `analytics.view` and `inventory.view`. Inventory, customer
orders and purchasing links are redacted when their existing permissions are
absent. English/Albanian, existing themes and responsive layouts are preserved.
