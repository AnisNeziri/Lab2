# Controlled company onboarding

This workflow is available from **Administration → Company Onboarding** after the DC1 release is installed. It prepares an opening position for a new company with no existing operational history. Existing zero-stock product/contact masters may be reviewed and reused. A company already operating in AIMS must use its normal inventory and finance workflows.

## Prepare the company position

Choose one company, its base currency, timezone and an explicit closing date. The opening represents the **end of that date in company time**. Posting becomes available after that cutoff; ordinary transactions belong to later dates. Stop other operational work for this company until verification and locking finish.

Obtain authorized product, customer, supplier, warehouse/location, inventory, AR, customer advance, AP, supplier advance and cash/bank source statements. Independently review inventory value, quantities by base unit, AR, AP, both advances, cash, bank and opening equity. Keep these source control totals separate from AIMS calculations. Blank means unknown; enter zero only after confirming it.

Preserve an installation/database/files backup using the existing installation runbook. The onboarding backup is a **company portable backup**, not a substitute for installation recovery. Verify access to PHP 8.3+ CLI with SQLite PDO for the separate-copy check. AIMS checks that runtime before the pre-cutover backup and first posting request.

## Stage and review

1. Create a staging session and save company settings/source control totals. Optionally require different review and approval users. Small companies may use one authorized owner; both decisions remain explicit and audited.
2. Download the current CSV template for each domain. Upload UTF-8 CSV or a flat JSON list (also accepted under a `data` property). Export XLSX to UTF-8 CSV first. Keep identifiers as text, particularly leading-zero codes and large numbers. Quote JSON decimal values when preserving exact source text; numeric values still undergo strict precision checks. Row editing keeps the file’s original decimal/date convention. Pick decimal, CSV-separator and date conventions explicitly.
3. Review column mappings. They auto-save when valid; use **Save mapping** to retry after a failure. Unknown columns do not become trusted business fields. Sources are private and restricted to this company.
4. Run **Validate without posting**. Resolve blocking rows using search/status/warning filters and paged previews. Exact/fuzzy candidates are suggestions: choose an existing record only after checking identity, explicitly create a separate record, or exclude a row with a reason. No destructive merge is offered.
5. Define exact alternative-unit conversions on the product. Cost is **per base inventory unit**. A roll has no assumed length. Unknown costs, unresolved references, foreign-currency positions and tracked stock without a traceable receipt workflow block posting.
6. Review reconciliation and drill into source rows for each difference. Quantities stay separate by base unit. Receivables and customer advances, and payables and supplier advances, stay separate. No tolerance or automatic balancing entry hides a difference. A rounding conflict requires source/cost review or consolidation before approval.

Uploads are limited to 10 MB / 10,000 rows per file. Identical file checksums are detected; intentional reprocessing creates another audited attempt. Interrupted source parsing can be resumed. Failed/unposted sources may be excluded with a reason; their original bytes and history remain. Staging, edits and dry validation create no inventory, debts, payments, journals, sales, POs or receipts.

## Approve, post, verify and lock

1. Create and download a fresh encrypted **pre-cutover company backup**. Keep its passphrase separately; AIMS does not save it. The backup must match the current revision and operational baseline.
2. Confirm the independent source totals, warnings and excluded rows, then record review. Explicitly approve the resulting fingerprint. Every source/mapping/settings/row change invalidates that approval.
3. Confirm posting and post the next bounded batch. The screen posts at most 25 rows per request. Reloading does not lose completed work. Repeating the same approved request continues with unposted rows rather than duplicating ledger effects.
4. Verify posted balances. Inventory Integrity, financial reconciliation and source-vs-AIMS controls must all pass.
5. Create/download the encrypted **post-cutover company backup**. Run **Restore into an independent database** with its passphrase. AIMS allocates a new SQLite database and private document directory, restores with different business IDs, checks source links, document checksums, inventory and accounting, and records actual subprocess evidence.
6. Confirm completion and lock the opening. Download the human-readable evidence summary and preserve it with both backups and original statements. Opening sources and reviewed values are now immutable.

## Interruptions and later corrections

Before the first operational row posts, an authorized owner may cancel with a reason. After posting begins, do not delete/reset a session or change source values. Resolve the reported service failure and resume the same approved batch. Other operations stay blocked until verification and lock. If the installation itself is damaged, follow the existing full-installation recovery runbook into a separate destination; preserve the original database/files for investigation.

An independently restored company copy intentionally loses its previous approval authority. Review and approve it locally, verify it, create a new backup and separate-copy proof, then lock it locally before normal operations. An immediate post-opening copy is covered by DC1 verification; a later backup containing subsequent business activity requires the existing broader recovery procedures.

After locking, open the posted business record from source rows and use existing dated stock/debt/account adjustments or reversals with a reason. Supplier opening advances have a contextual **Reverse supplier advance** action when unallocated and permitted; allocated advances must first be unallocated through their existing workflow. Cash/bank opening amount/date cannot be rewritten in account settings; use a money-account adjustment on a later date. Purchase-order and goods-receipt history exposes links to related payment, expense, landed-cost and return workflows; each service rechecks eligibility when acted upon. The original opening evidence remains unchanged by later corrections.

## Access and supported boundaries

Onboarding has separate view, manage, review, approve, post and lock permissions. An action also requires view permission. Default owner/admin roles receive them; inventory/product editing alone does not grant cutover powers. Company backup/document permissions still apply.

Current supported boundaries: company base currency; known base units plus explicit fixed alternate units; quantity 3 decimals, costs 6 and money/master prices 2; supported single numeric value under 1 billion. Positive lot/serial-controlled opening inventory requires the established trace workflow. Position rows require positive amounts/quantities; use a confirmed zero control total and no row for a zero domain. Negative cash/bank positions are unsupported. Company portable document backups retain the existing 32 MB document-byte cap. No external paid AI API is needed. Ask AIMS may explain facts/blockers; it cannot approve or post an opening.

DC1 development verified synthetic data only. It has not imported or cut over the real family company.
