# Outbound fulfillment

## Operator workflow

1. Open **Orders & Fulfillment → New sales order**. Select a customer, products,
   quantities, payment type, requested date and optional preferred warehouse.
   Drafts remain editable until confirmation. Prices use company currency.
2. Confirm the order. Credit orders reuse customer-credit approval; recorded
   advances reduce projected exposure. Cash orders do not require a credit override.
3. Reserve eligible stock, then create/assign pick tasks. Reservations do not issue
   stock. Location suggestions use FEFO for dated lots and oldest location balances
   otherwise. Inactive warehouses/locations, expired lots and nonavailable states
   are excluded. A reason is required for manual allocations; release an unpicked
   allocation before selecting a replacement.
4. Scan or explicitly verify the product/location/lot, and enter cumulative picked
   quantity. Short-pick reasons remain auditable. Optional camera scanning reuses
   the existing local scanner; hardware/permission support depends on the device.
5. Create one or more packages from picked quantities. Optional type, dimensions,
   weight and notes are supported. Unpack to correct an undispatched package.
6. Select packages and dispatch. **This is the only stock-issue boundary**; the
   existing daily-sale, inventory, customer ledger and accounting services are reused.
7. Enter cumulative delivered quantities, recipient and optional proof/reference.
   Partial deliveries keep the remaining demand outstanding. Add photos/files through
   the central related-documents panel. An already delivered dispatch uses returns,
   not a destructive delivery correction.
8. Request a return against its original delivered package, authorize, receive, and
   inspect/resolve it. Different deliveries have separate return requests, preserving
   original sale-line, lot/location and financial traceability. Quarantine is the default.

## Navigation and controls

Command shortcuts support new orders, allocated orders, dispatch queue, late orders
and pending returns. Wave, product, customer and warehouse filters are functional.
Pick queues are paginated. Wave status follows its actual tasks. Draft edits,
assignment and manual reservations retain business-event history.

Product/customer/warehouse context exposes outbound information without replacing
their original workflows. Counts avoid mixing metres and pieces. Performance metrics
use recorded operations only; an absent denominator displays a dash, not a fake rate.
The expected availability date uses the existing ATP schedule and excludes undated
or overdue/unreceived receipts from a firm future promise.

## Verification and recovery

Run `php artisan test --filter=OutboundFulfillmentTest` and the document/portable
backup suites. Run `npm run test:e2e -- fulfillment.spec.js` for real UI workflows,
permissions, credit approval, partial fulfillment and responsive light/dark layouts.
The desktop asset preparation includes both modules and their migrations.

For actual simultaneous reservation verification, run
`php tests/Support/outbound-concurrency.php` with `APP_ENV=testing` and a dedicated
MySQL connection/database whose name ends in `_test`. The script refuses other
environments, uses two separate processes and leaves only isolated test fixtures.

Full encrypted company backups restore operational and financial relationships.
Selective document backups are not a substitute for full installation recovery.
Document backup limits and the deliberate legacy-file adapter are documented in
`DOCUMENT_CENTER.md`. No paid routing, logistics, cloud, OCR or AI service is required.
