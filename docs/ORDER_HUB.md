# AIMS Order Hub

## One authoritative order

`order_intakes` is channel staging and review, not a second sale. Each accepted
intake has one unique `sales_order_id`. Existing Sales Orders, ATP, reservations,
pick tasks, packages, dispatches, delivery records, returns, customer credit and
Financial Core remain the authorities. Creating an intake does not issue stock
or post revenue. Existing manual sales orders are exposed through a Manual
channel by a non-destructive migration.

Open **Order Hub** (`/order-hub`) and **Channels** to configure a source. Create
an order, resolve any matching/price issues, then **Confirm & reserve**. Follow
**Open pick, pack, dispatch & returns** to the existing fulfillment workflow.

## Channel controls

- Named channel, enabled state, type, company currency and default warehouse.
- Automatic acceptance after validation or explicit internal review.
- No-backorder, accept-backorder, or explicit stock-review policies.
- Optional named cash guests and required delivery address.
- Exact external product/customer mappings. No fuzzy automatic merge or silent
  creation of customers/products.
- Ordered, non-stacking channel price rules: fixed/percentage discount,
  customer/product targeting, minimum base-unit quantity and date range.
- Historical accepted-price snapshots; incoming agreed-price discrepancies are
  reviewed rather than silently replaced.
- Optional confirmation deadline and company order-acceptance approval rule.
  Approvals use the existing shared engine, including separation of duties.

## API and webhook contract

Issue credentials in Channels → API credentials. Tokens are displayed once and
stored hashed; webhook secrets use the existing encrypted model cast. Credentials
expire, can be revoked and are scoped to one channel. For a customer portal, bind
the credential to a customer before issuing it. Never embed an unbound integration
credential in a public web page. Never place secrets in URL query parameters.

All external endpoints are under `/api/order-api/v1`:

| Endpoint | Scope | Purpose |
| --- | --- | --- |
| POST `/orders` | `orders:create` | Validated, idempotent intake |
| GET `/orders/{intakeId}` | `orders:read` | Sanitized order status |
| POST `/orders/{intakeId}/cancel` | `orders:cancel` | Existing cancellation rules |
| GET `/catalog?search=…&page=1` | `catalog:read` | Products, fixed units, authoritative stock |
| GET `/availability?search=…&page=1` | `availability:read` | Authoritative stock, without prices |
| GET `/portal?page=1` | `orders:read` | Customer-bound history and safe balance summary |
| POST `/webhook` | `orders:create` | Signed normalized order intake |
| GET `/tracking/{unguessableToken}` | private tracking token | Safe public tracking |

Required intake properties: `idempotency_key`, `order_date`, `payment_type`
(`cash`, `credit`, `prepaid`) and `items`. Each item needs a positive quantity and
an existing product ID, exact SKU/barcode, or mapped external product identifier.
Omitted prices use current validated prices. A customer-bound credential always
overrides a supplied customer ID. Guest orders require a contact name, a channel
that permits guests, and cash terms.

Webhook headers:

```
Authorization: Bearer <credential>
X-AIMS-Timestamp: <current Unix seconds>
X-AIMS-Signature: <hex HMAC-SHA256(timestamp + "." + exact raw JSON, webhook secret)>
```

Signatures expire after five minutes. Requests are capped at 256 KB; credentials
and routes are rate limited. Retrying an identical external order returns the
same intake. Changed payloads under an existing identity return a conflict and
do not overwrite confirmed history. The originally received payload is retained.

## Review and security

Product/customer/warehouse IDs are company-scoped. Route-bound channel/intake
models are explicitly rechecked against the authenticated company. Confirmation
checks the validated order signature, so editing an external draft through the
legacy sales endpoint cannot bypass review. Stock reviews and order approvals
are bound to the exact order. Changing protected values requires another review.
Credit overrides continue to use the existing authoritative credit service.

Only a backend-confirmed dispatch is a sale. External `paid` claims are not
trusted. Customer cash/advance/credit rules remain in the existing ledgers. Guest
returns require an actual refund account and money-account posting permission;
they never create a fake customer or customer-credit balance.

## UI and operations

The inbox has search, channels, attention/backorder/partial/preorder/credit/late
views, saved views, repeat-order templates, internal notes, timeline and linked
documents. CSV imports have a preview and idempotent commit. Batch retry,
confirmation and cancellation report each result separately. CSV, XLSX and PDF
exports are filtered and bounded to 2,000 orders. Packing slips remain in WMS.

The customer portal is `/order-portal`. Its credential is held only in memory,
never in localStorage or an employee session. It can browse, submit orders and
see its own history. Private tracking links expire after 90 days and expose no
internal notes, margins, warehouse details or other customer balances.

Background refresh updates data without resetting open forms. Browser checks
cover real workflows and five widths, light/dark themes, English and Albanian.
Existing read-only AIMS tools, search, command navigation, Business Events and
System Integrity checks include Order Hub.

## Backup and desktop

`order_hub` is a selectable portable-backup module. Core relationships remap on
restore; original source payloads remain historical evidence. API credentials
and live tracking tokens are deliberately not portable. Reissue credentials and
tracking links on the destination installation. Changed draft identifiers may
require revalidation after restore; old approval signatures never silently grant
authority to a different transaction.

The desktop bundle uses the same Laravel services with local SQLite. Local order
entry/fulfillment does not need Docker or an external backend. Receiving remote
webhooks while the computer is offline is impossible; no sync success is faked.

## Deliberate boundaries / remaining scope

- Marketplace-specific and payment-gateway adapters are interfaces, not enabled
  integrations. No paid third-party service was added or subscribed to.
- Foreign-currency, separately supplied shipping and tax amounts are retained
  but held for financial review. The existing fulfillment engine is company-
  currency based; this implementation does not invent FX/tax postings.
- Outbound webhook delivery reuses existing Business Events infrastructure.
  There is no background pull connector, general conflict-resolution console,
  or provider inventory/price push adapter yet.
- Portal access currently uses revocable customer credentials, not a separate
  customer email/password enrollment or password-recovery system.
- Reporting distinguishes order value from dispatched gross sales. It does not
  present unimplemented channel margin/attribution metrics as real data.

These boundaries mean the full original 90-section roadmap should not be
described as completely implemented or production-certified for every provider.

## Verification, 18 September 2026

- Full backend suite: 264 passed, one environment-dependent skip on SQLite;
  the same result on the isolated MySQL test database (2,436 assertions each).
- Focused Order Hub suite: 20 passed on both SQLite and MySQL; the final
  failed-confirmation attention-state check also passed on SQLite. Includes scoped API access,
  customer-bound portal isolation, signed webhook replay, exact approvals,
  direct-confirm bypass rejection, stock review, price snapshots, CSV replay,
  CSV/XLSX/PDF exports, guest refunds and encrypted backup ID remapping.
- Frontend unit checks: eight passed. Production build passed; existing large
  PDF/3D chunks still produce Vite's non-fatal size warning.
- Playwright: 18 passed across Order Hub and existing fulfillment workflows,
  including five viewport widths, both themes and an Albanian configuration /
  export workflow.
- Desktop resources regenerated; isolated bundled-PHP / SQLite startup passed
  with local assets and a healthy API. This is not a newly signed installer.
- Live migrations applied after a private SQL backup in the ignored `backups/`
  directory. Existing company data was not reset or reseeded.

## Connected Orders workflow — 24 September 2026

The normal entry point is now **Orders / Porositë**. `OrderEntry` searches the
existing customer and product catalog, supports barcode/SKU searches and fixed
units, previews stock and prices, and reviews a draft before saving. Manual
orders reuse a company-scoped manual channel; external channels retain their
existing matching and review rules. Editing retains the saved line prices.

`OrderWorkflowService` coordinates the existing authorities rather than creating
another sales or stock engine:

- Confirmation reserves stock; verification and **Mark ready** use existing
  allocation, picking and packing. Tracked stock still requires warehouse scans.
- **Dispatch is the sale-recognition boundary.** Each dispatch creates one Daily
  Sale and one stock issue. A partial dispatch legitimately has its own sale.
  Payment alone never falsely reports physical delivery or issues stock.
- Cash is confirmed at dispatch. Credit/advance receipts use the existing
  customer ledger and financial account, oldest debt first; excess is advance.
  Receipts are not earmarked to an individual order. Non-cash dispatches do not
  create another Daily Sales cash receipt.
- An optional source-linked invoice is generated from the existing Daily Sale.
  Its agreed gross amounts are preserved; issuing it neither sells the stock
  again nor posts revenue again. VAT is reclassified from the gross sale once.
  Customer-ledger settlement is a read-only projection on invoice screens and
  exports, not a second invoice-payment entry.
- Completed monetary returns create documentary credit notes against the source
  invoice and reverse the corresponding VAT classification. They do not post
  stock or refunds a second time. Returns completed before invoice issue are
  documented when the invoice is issued. Replacement/nonfinancial returns do
  not create monetary credit notes.
- Linked Daily Sales cannot be edited/deleted outside the source workflow.
  Repeated ready, dispatch, payment, invoice and return operations remain guarded
  by the existing transactions, tenant scope and idempotency controls.

Navigation includes dashboard order counts, customer/product order links,
Daily Sale and invoice backlinks, return credit-note links, existing packing-slip
downloads, and a bilingual human-readable order timeline.

### Verification checkpoint

- Full SQLite and isolated MySQL suites: **270 passed, one environment-dependent
  skip** on each, 2,553 assertions. The subsequent non-cash receipt correction
  is additionally covered by the focused workflow/finance/fulfillment suite.
- Browser: **19 passed** across Orders and fulfillment, including manual entry,
  dispatch → Daily Sale → invoice, full/partial delivery, returns, approval,
  permissions, five viewport widths, both themes, and Albanian controls.
- Frontend unit checks: **8 passed**. Production build passed; pre-existing PDF
  and 3D chunk-size warnings remain.
- Desktop resources rebuilt; isolated bundled PHP/SQLite startup certification
  passed. No new installer signing or public release was performed.
- Existing encrypted backup/restore tests passed in the full suites, including
  outbound relationships and Order Hub tenant/ID remapping.

### Follow-up: 25 September 2026

Issued invoices and credit notes now automatically receive an immutable bilingual
PDF copy in private Document Center storage. The archive is restricted to document
administrators, protected by retention/legal hold, and linked to the invoice,
customer and source order when present. Repeated issue requests reuse the copy;
an issue-time snapshot is not rewritten when later payments change. Existing
historical issued documents can be archived by replaying their idempotent issue
action. Ordinary reads do not mutate or generate archival records.

Orders now has paid, unpaid and partially-paid views. Invoice payment filtering
uses the same customer-ledger settlement calculation as the invoice details,
including partial payments, advances and overdue dates. Matching is performed
before pagination; scans are chunked and reuse customer obligation calculations
within the request rather than storing a second financial balance.

Validation of this follow-up: full SQLite suite 270 passed / one skipped
(2,574 assertions); focused invoice/document/order regressions passed on SQLite
and MySQL (38 tests, 428 assertions on the final MySQL run);
the browser dispatch-to-invoice journey also passed with the new Paid filter.
Frontend unit checks (8) and production build passed. Generated desktop resources
include the new archive and filter services. Existing build chunk-size warnings
remain non-fatal.

### Boundaries

This checkpoint does **not** certify every item in the 81-section UX prompt.
Online payment adapters are still disabled until a real provider is configured.
Cash-on-dispatch retains the existing Daily Sales cash-account behavior, rather
than adding a second cash/bank receipt. The archive stores issue-time balances;
live settlement remains available from the invoice and customer ledger. No paid
payment-provider adapter or external subscription was enabled by this update.
