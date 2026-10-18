# DC2 — Accounting & Financial Control 2.0

Status: implementation, all eight hosted validation gates and both review artifact uploads passed in [AIMS CI](https://github.com/AnisNeziri/Lab2/actions/runs/39143674151). Draft [PR #6](https://github.com/AnisNeziri/Lab2/pull/6). No deployment, merge, real-company cutover or subsequent phase. No statutory/fiscalization certification is claimed.

## 1. Current HEAD

Branch `codex/dc2-accounting-financial-control`, stacked on DC1 `5be35c1646d91ad2018c867658ac71bbe560d6db` (draft PR #5). Application source commit: 50d75e6c612ffd1591a0f238fdf42f05c673277b. The final evidence follows in a documentation-only commit above that source; `git rev-parse HEAD` identifies the documentation head. Changes were made in the attached isolated worktree. The original checkout is clean on `main` at `07c66520187db1486f994e9527670b1e7dcbcd24`; its pre-existing generated certification artifacts were preserved. The branch is a reviewable stack; it was not checked out over the other chat's work or deployed to the business database.

## 2. Accounting Control Center

`/accounting` is the primary accountant workspace, with owner view, posted summary, prioritized exceptions, AR/AP, journals, chart/activity, cash/bank, landed costs, reconciliation, statements, mandatory month close and setup. Dense EN/SQ tables use aligned tabular numbers and light/dark layouts. Simple cards use bounded summary endpoints, not frozen Financial Intelligence JSON.

## 3. General Ledger improvements

Paginated/filterable journal list; readable source labels, periods, actors, detailed debit/credit lines, totals/difference, audit, source links and reversal/correction lineage. Manual journals use optimistic revisions, historical-rate snapshots and explicit control-account acknowledgement. Operational sources own their corrections.

## 4. Chart of Accounts improvements

Configurable codes, hierarchy and statement/cash-flow classifications; posting mappings identify controlled accounts. Used/system/mapped accounts have deactivation/deletion guards. Account activity has dated opening, debit, credit, closing and exact running balance across pages. No jurisdiction-specific numbering scheme is forced.

## 5. Period-close workflow

Existing open/soft-closed/closed semantics retained. Mandatory dated/current integrity checks produce READY/ATTENTION/BLOCKED; advances remain legitimate review items. Posting and close share company/period locking, including fresh reads under MariaDB repeatable-read. Close stores a proof; reopen requires privileged actor/reason/time and preserves old proof in audit.

## 6. AR improvements

Shared dated receivable positions drive aging, statements and credit checks, including explicit attribution plus oldest-known-due fallback. Standalone invoices and ledger sources avoid double counting. Customer/salesperson/currency/date filters, top overdue debtors and an explicit unknown-due bucket are supported. Customer statement keeps AR/advance running balances separate. Existing source-linked invoice credit notes are retained; no negative-invoice shortcut or parallel credit-note subsystem was added.

## 7. AP / supplier invoice improvements

Draft/review/approval/posting uses existing Expense and matching services. Drafts do not consume advances or post; generic expense posting cannot bypass required supplier review. Non-PO service bills are supported. Three-way matching exposes independent quantity and total price variance without modifying PO/receipt. A decorated-model persistence defect was fixed.

## 8. Payment allocations

Customer split allocation/release/reallocation is auditable attribution of existing settlement, not new cash. Supplier applications post only each incremental amount; release appends a reversal and retains the original request. Company/customer/payment locks, capacity checks, period rules and intent-bound idempotency prevent double use. Payment and allocation history are paginated separately.

## 9. Customer / supplier advances

Separate controls and reports retain advances before purchases. Required customer 1,000 advance → 1,400 sale leaves AR400 without duplicate revenue/cash. Supplier 6,000 + 10,000 advances against 25,000 bill leave AP9,000. Historical carrying cost and bill-rate differences use existing FX mappings on allocation. Prior incorrect cumulative journals are not rewritten. Approved inventory-bill variances retain the existing receipt-clearing treatment; automatic price-variance capitalization was not introduced.

## 10. Bank import / reconciliation

Generic mapped CSV adapter, strict dates/numbers, 10MB/10,000-row atomic staging, replay/overlap evidence and original-file documents. Suggestions are deterministic and bounded. One bank row can own several complete financial transactions with an exact signed sum; a nullable unique live-owner key plus locks prevents double ownership. Completion requires original statement proof, dated AIMS/GL agreement, no unexplained rows or uncovered system activity. Completed evidence is rechecked at close. Partial sharing of one cash record across bank rows is deferred.

## 11. Expense workflow

Existing expense authority remains in use. Source date, category, payee, currency/rate evidence, documents, approval/posting, selected payment account, partial settlement and linked reversals are presented through finance workflows. Recurring automatic posting was not introduced; recurring draft templates remain a later optional feature.

## 12. Landed-cost accounting

Finance shows receipt goods basis, posted freight/customs/insurance/port/broker/inland/other costs, total receipt basis, allocation method and per-product allocation/unit-cost evidence. Receipt/PO/shipment, valuation effects, documents and journals remain traceable. Reversal records independent per-product before/after valuation evidence; historical inventory reconstruction fails safely if that evidence is absent.

## 13. Subledger ↔ GL reconciliation

One read-only workspace compares six controls: AR, customer advances, AP, supplier advances, inventory and cash/bank. Dated source snapshots are independently compared with posted GL; current integrity is also inspected. Existing differences are surfaced, never repaired by editing balances.

## 14. Inventory ↔ GL reconciliation

Independent product valuation is compared with inventory GL. Period-end reconstruction subtracts later stock/landed valuation evidence. Product/receipt/movement/landed source links support investigation; missing legacy valuation snapshots block proof. Inventory quantity integrity uses chunked grouped reads rather than per-product queries.

## 15. Trial Balance

Opening debit/credit, period debit/credit and closing debit/credit, dates/period/account ranges, account drill-down and CSV. Complete trial-balance equality is checked even when a displayed account range is narrower. Posted imbalance is a critical integrity issue. Comparisons use the ledger cent precision, so SQLite binary floating-point noise cannot turn 0.10 + 0.20 = 0.30 into a false blocker; genuine one-cent differences and out-of-scale lines are still detected.

## 16. P&L

Configurable account classification presents revenue, COGS, gross profit, operating expenses/profit, other results and net profit. Posted-only comparisons require prior activity. Drill-down reaches account activity, journal and source. TB aggregation is reused for summary results.

## 17. Balance Sheet

Assets, liabilities and equity use posted values. Current fiscal-year earnings and prior earnings brought forward are presented dynamically without duplicate closing/opening profit journals. Fiscal-year start is configurable; year transition has tests. Assets = liabilities + equity is a mandatory close check.

## 18. Cash Flow

Posted cash movement aggregates use configured operating/investing/financing classifications. Mixed or missing classifications remain explicitly unclassified; transfers net to zero. Opening + movement = closing is checked. This is not a fabricated statutory cash-flow classification.

## 19. Owner financial view

Simplified cash/bank, customers owe, supplier obligations, inventory, sales/result, working capital and attention view. The owner need not navigate Trial Balance to understand the position. Technical correction controls remain permission checked.

## 20. Working capital / forecast

Cash + bank + AR + inventory − AP is explained; advances stay visible separately. Overdue AR and due-soon AP link to evidence. Financial Intelligence supplies a bounded advisory forecast summary, with known commitments and predicted timing separated from booked accounting and stale-data qualifications.

## 21. Currency / FX improvements

Manual journals and supplier bills/payments/PO advances retain historical rate/date/source. Later rates cannot silently value historical postings. Supported supplier advance application recognizes realized carrying differences. Foreign customer invoice posting and foreign cash transfers are blocked until supported; arbitrary-base expense accounting, period-end FX revaluation and automatic rate feeds are deferred.

## 22. Tax-readiness foundation

Distinct zero-rated, exempt, out-of-scope and legacy non-VAT expense treatments persist and reject invented tax. Exempt treatment requires a basis; supplier VAT evidence can be entered. Existing standard/reduced/import/reverse-charge behavior is retained. Tax cards show configured posted control balances only. Existing 8%/18% tax policy is not newly verified or certified, and this is not an official filing or Kosovo fiscalization.

## 23. Reversals / corrections

Posted financial history is immutable through application services/models. Manual reverse-and-prepare creates a linked unposted draft. Source-owned corrections respect periods, settlements, bank ownership and inventory effects. Source reversal effective company dates preserve historical reports; unknown legacy dates are not guessed. Draft sources cannot be posted through stale recovery exceptions.

## 24. Month-close experience

Fixed mandatory checks cover journals, bank proof/activity, supplier review, AR/AP/advances/inventory/cash controls, opening issues, landed costs and failed recovery. Checks explain counts/differences and link to investigation. Soft close permits only explicitly authorized adjustments; normal backdated posting to closed periods fails without shifting the date.

## 25. Ask AIMS finance

Read-only registered tools answer finance summary, AR/top debtors, AP/due bills, payment attribution, close blockers, reconciliation and posted profit comparisons. English/Albanian/mixed wording and common typos have tests; follow-ups retain finance context, including AR month-end comparison. Financial write commands are refused without a write tool. Existing audit/feedback ownership is retained.

## 26. Integrity, audit, permissions, search and documents

Critical: posted imbalance, invalid lines, cross-company parent/GL/source links, required missing posting, orphan/restored source, illegal closed-period posting and broken bank ownership. Attention: overdue AR, old drafts, review/attribution/reconciliation work. Action Center groups meaningful finance exceptions; Global Search includes journals/accounts/bills/payment/bank references. Document Center supplies company-scoped bidirectional links. Accountant grants are explicit; existing custom roles are preserved for administrator review.

## 27. Performance findings

Local SQLite fixture: 150,100 journals / 300,200 lines, 10,002 products and 10,000 customers. Last warm sample: Control Center 1,258 ms / 5,867 B / 90 queries; journal page 170 ms; account activity 602 ms; TB 277 ms; P&L 282 ms; BS 451 ms; CF 106 ms; dated reconciliation 198 ms; close readiness 2,403 ms / 11,883 B. Peak PHP memory: 44 MiB. The profile retains all three samples and slow SQL/query plans. A source-integrity index and shared GL aggregation reduce repeated work.

This fixture has 200 AR ledger rows, no AP bills and no bank rows, so those timings do not prove large-subledger performance. These are single-host synthetic measurements. The [hosted CI-sized read budget passes](https://github.com/AnisNeziri/Lab2/actions/runs/39143677243); Action Center dropped from 138 to 97 queries within its unchanged 100-query limit. On the larger 10,002-product fixture, the pre-existing inventory Dashboard returns about 14.5 MB and fails its payload/latency budget. That limitation remains outside the Finance Control Center. Existing large PDF/3D frontend chunks still warn at build.

## 28. Tests run

Local full SQLite before the last regressions: 707 passed / 6,447 assertions, one conditional Redis skip. Finance/recovery subset: 120 passed / 919 assertions on each SQLite and MariaDB 11.8.9. After the grouped-read optimization: 27 passed / 248 assertions on each engine. Chromium finance: 3 passed; Node: 89 passed; Python: 16 ML + 8 supply-optimizer tests passed. Five true MariaDB process races passed. Populated SQLite and MariaDB independent upgrade/recovery passed, preserving 31 local attachments and six zero-difference controls. On final application source 50d75e6c, hosted SQLite and MariaDB each pass 711 tests / 6,471 assertions, with one Redis skip per engine and passing DC1/DC2 races. Node 89, Python 16 + 8, Windows startup/runtime 5, Linux workers and browser smoke also passed. The final source-link refinement additionally passes 14 tests / 58 assertions on each engine and a production frontend build. The cent-precision refinement passes 89 accounting tests / 666 assertions per engine, plus the 3 targeted decimal regressions. The linked hosted run passes all eight gates on the final source revision. Full Chromium: 108 first-attempt passes + 1 passed retry in 24 minutes, no final failures. All three DC2 finance journeys passed first attempt. The existing dashboard/search test failed an immediate document-width assertion after viewport resize, then passed its configured retry; that flaky check remains disclosed. Both review artifacts uploaded successfully and their downloaded SHA-256 values match their manifests. The hosted recovery artifact independently confirms 10 attachments per engine, all six control differences at zero, restored HTTP login and tenant isolation; the larger local recovery fixture has 31 attachments. [Operator guide](dc2/ACCOUNTANT_GUIDE.md), [compact evidence](dc2/VERIFICATION.json) and [profile](dc2/FINANCE_PROFILE.json). Evidence counts overlap; they must not be added as unique tests. Populated recovery uses the selected `5db86866` migration history, backup before forward migration, independent destination storage/documents, attachment checks, authenticated business journeys and tenant isolation. CI includes all five new MariaDB process races.

Review artifacts from application source `50d75e6c`:

- [Web source + compiled frontend](https://github.com/AnisNeziri/Lab2/actions/runs/39143674151/artifacts/12143292313): 995 committed backend/deployment/script/documentation source blobs verified against Git; built frontend included. Archive SHA-256: `441f014a9aab8cd58b2cf3e57ceb135d91176ba35e4347ea99d606fcf620a0f7`.
- [Unsigned Windows installer](https://github.com/AnisNeziri/Lab2/actions/runs/39143674151/artifacts/12143392473): `AIMS-Setup-1.1.2-rc.1.exe`, 181,242,975 bytes. SHA-256: `d18e38f2f42772909498c3cb850d5b847d41c2a66e136daa783ed8f828ef26ce`. The PE certificate table is empty as expected. Downloaded and inspected, not installed over the user's system.
- [Full browser evidence](https://github.com/AnisNeziri/Lab2/actions/runs/39143674151/artifacts/12142774680) and [independent recovery evidence](https://github.com/AnisNeziri/Lab2/actions/runs/39143674151/artifacts/12143196129).

The final candidate job also passed. These artifacts contain source-time documentation; this final evidence report follows as documentation only. The release metadata remains `uncertified`, and the installer is for review. No customer deployment or signing certification occurred.

## 29. Known accounting limitations / pilot gate

No external accountant, company opening, tax/legal policy or jurisdiction certification was validated. Legacy demo/data may contain real reconciliation issues; those are intentionally not cleared or rewritten. Settled-debt cancellation funding lineage has conservative guards that may require review of older receipts before reallocation/refund. Approved inventory-bill variance/receipt-clearing balances need accountant policy review; warehouse quantities are not silently changed. Full foreign customer settlement/revaluation and foreign cash transfers are deferred; expense base currency remains EUR. Partial sharing of one cash record, XLSX bank parsing, recurring templates and statutory year-end closing are not introduced. CSV plus existing print facilities are available; PDFs are not authoritative. Optional dimensions retain existing reliable context/manual-line support; no new enterprise dimension engine. A real-company pilot requires reviewed opening data, policies, permissions and accountant sign-off. Pilot ready is therefore **NO** at this evidence stage.

## 30. Next recommended accounting / finance phase

Do not start it automatically. First perform an accountant-led acceptance session on a backed-up company clone, resolve legacy differences through source-owned corrections and confirm base currency/tax/close policy. The next scoped engineering work should cover precise settled-debt receipt funding lineage and authorized refund/reallocation, followed by verified FX customer settlement/revaluation and configured jurisdiction tax workflows.

## Required explicit results

All reconciliation/lifecycle YES entries below mean the controlled synthetic journeys and isolation checks, not an assertion about an unreviewed live company.

| Check | Result |
|---|---|
| Posted journals immutable | YES — application write paths; privileged SQL corruption is detected |
| Debit = Credit enforced | YES |
| AR ↔ GL reconciles | YES |
| Customer Advances ↔ GL reconcile | YES |
| AP ↔ GL reconciles | YES |
| Supplier Advances ↔ GL reconcile | YES |
| Inventory ↔ GL reconciles | YES |
| Cash/Bank ↔ GL reconcile | YES |
| Customer partial-payment lifecycle verified | YES |
| Customer advance lifecycle verified | YES |
| Supplier partial-payment lifecycle verified | YES |
| Supplier advance lifecycle verified | YES |
| Three-way matching works | YES |
| Bank reconciliation concurrency safe | YES — five real MariaDB process races include match ownership/completion |
| Month close blocks unresolved integrity differences | YES |
| Closed-period posting blocked | YES |
| Reversal lineage preserved | YES |
| Trial Balance balances | YES |
| Balance Sheet balances | YES |
| Two-company finance isolation | YES |
| SQLite accounting tests | PASS |
| MariaDB accounting tests | PASS |
| Accounting ready for real accountant pilot | NO — company/policy acceptance and legacy remediation remain |
