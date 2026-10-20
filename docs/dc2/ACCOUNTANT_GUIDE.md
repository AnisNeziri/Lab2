# DC2 accountant guide

Use **Finance Control Center** (`/accounting`) as the starting workspace. Summary balances are posted General Ledger values through the selected end date; revenue and results use the selected date range. The attention and current reconciliation panels explicitly inspect current operational controls. Forecasts are advisory and separate from booked balances.

## Start a company safely

1. Verify an installation backup and the existing opening reconciliation. Use DC1 onboarding for an eligible company; do not run migrations against a fresh database to replace existing data.
2. Review the company base currency, timezone and fiscal-year start. Existing expense and supplier carrying-value services use EUR. Foreign invoices/payments require the authorized historical rate, date and source. Foreign customer invoicing, foreign cash transfers and period-end FX revaluation are not enabled by this cycle.
3. Initialize/review the chart, posting mappings and financial accounts. Account codes are configurable. A referenced or controlled account cannot be casually removed or deactivated. Keep AR, AP, advances and inventory corrections in their source workflows.
4. Assign the accountant role and review existing customized permissions. New generic managers receive read access rather than all financial powers; existing configured grants survive upgrade and need administrator review.

## Daily finance review

Open the Control Center and inspect critical differences first. Cards link to balances, journal lines or operational records. Unknown due dates remain unknown. Customer and supplier advances are separate assets/liabilities and are never netted into receivables/payables merely to make a display look tidy.

**General Ledger:** filter dates, period, account, source, reference, status and user. Open a journal for account lines, totals, source, posting actor, audit and reversals. Source-owned journals must be corrected in the original module. Manual journals are drafts first; posting requires balanced lines, the current revision and an open period. Direct control-account postings require elevated authority and a reason. A soft-closed adjustment requires its separate permission and explicit reason.

**Receivables:** select customer/currency and open the statement. Opening/closing AR and advances are separate. Payment allocations identify which existing debts a receipt settles; they do not create another receipt or change total AR. The modal supports paginated debts and a split amount across debts. Release/reallocate retains history. Existing oldest-known-due attribution remains available for older receipts. A receipt affected by settled-debt cancellation requires funding-lineage review before another allocation or reversal; do not work around this through a manual control-account journal.

**Payables and bills:** save the supplier invoice as a draft, review PO/receipt/invoice quantities and prices, then review approval and posting. Legitimate non-PO services are supported with an explicit review reason. Posting may apply available PO advances, as disclosed in the review. Record a partial/full payment against a selected cash/bank account; the remaining payable stays visible. The supplier workbench separates invoices, available advances and allocation history, with independent pagination. Supplier statement/export shows AP and advances at carrying value. Reverse/release existing settlements before a bill correction when required.

**Tax:** zero-rated, exempt and out-of-scope treatment are distinct from legacy non-VAT. Zero-tax treatments reject nonzero VAT. Exemption requires the accountant's basis. Existing configured 8%/18% behavior is retained, not independently certified here. Supply the vendor VAT number and company registration when claiming eligible input VAT. The tax panel shows posted control balances and is not a statutory filing.

## Bank statements

Export a UTF-8 CSV with Date, Amount and optional Reference, Description and running Balance. Select the account and exact column/date/decimal conventions. For example, `2026-09-15,75.00,DEP-104,75.00` under `Date,Amount,Reference,Balance`.

Import stages evidence only. It does not create cash transactions. Invalid rows reject the entire import. Reimporting the same bytes with the same mapping reuses the statement; overlapping source rows require duplicate review. Dates and numbers are never guessed.

Review deterministic amount/date/reference suggestions and explicitly select existing posted transactions. One statement row may match several complete financial transactions whose signed total equals the bank row. Every financial transaction has one active match owner. Sharing part of one cash transaction across several bank rows is not supported; use the correct original split payment records and retain evidence.

Supply original opening/closing balances and statement dates where the CSV lacks them. Bank-provided balances cannot be overwritten. Completion requires zero unexplained difference, all rows matched, all in-period system activity covered, valid posted sources and GL agreement. Ignoring a nonzero row does not explain it. Completed statements require a reasoned authorized reopen before amendments; bank matches must be released before reversing a matched payment. Matching and reopening do not post money.

## Month close

1. Select/create a non-overlapping period; core mandatory checks cannot be disabled.
2. Resolve drafts, unmatched/review-pending bills, unmatched bank activity, pending landed costs, unfinished openings and failed postings. Advances can legitimately remain open and are disclosed for review.
3. Investigate all dated and current AR/AP/advance/inventory/cash differences. Missing historical valuation evidence blocks closing. Do not edit a balance or change an original bank balance to conceal a difference.
4. Inspect Trial Balance, Balance Sheet and retained-earnings presentation. Review unclassified cash-flow items rather than assuming investing/financing labels.
5. Optionally soft close; then explicitly review final close. The system recomputes controls while holding the same company/period locking authority as posting, stores its proof and audits the action.
6. Reopen only with privileged permission and a reason. Earlier close proof remains in audit history. Backdated posting to a closed period fails; dates are not silently moved.

## Corrections, documents and Ask AIMS

Posted journals/lines are immutable through the application. A manual correction reverses the original and prepares a linked draft; review and post it separately. Operational corrections use their own source, periods, allocation and inventory guards. Original and reversal remain visible.

Attach supporting documents through Document Center. Records show linked journals and documents; journal/source links are permission checked. Selective company restore remaps company-local IDs and marks earlier close/reconciliation proofs historical for fresh local review. Full installation disaster recovery preserves IDs and verifies its restored controls; follow the recovery runbook before resuming work.

Ask AIMS can summarize AR, AP, cash, close blockers, reconciliation, payment attribution and posted profit comparisons in English, Albanian or mixed wording. It is read-only and refuses posting, paying, allocating, reversing, reconciling or closing commands. Check its source cards and date qualifications. Export CSV for spreadsheet work; the ledger remains authoritative. Exports have bounded size and spreadsheet-formula escaping.

This guide describes the implemented controls, not external accountant/legal approval. Resolve legacy discrepancies and validate the selected company's opening, policies, tax and FX use before any real pilot. Do not start a subsequent phase automatically.
