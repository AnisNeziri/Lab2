# DC13 — Customer 360 and connected sales

HEAD `fed921ad319d5f360281f11df67fbb5b96e1f656`, `main`. Existing uncommitted work is preserved. No commit, push, deployment, source reset/reseed, active database migration or cutover. The schema upgrade was exercised only on the isolated MariaDB 11.4.13 copy, using administrative credentials for DDL and its existing least-privilege account for runtime. No new financial or inventory engine.

1. **Existing functionality reused.** OrderHubService, OrderPriceService, OutboundService/OrderWorkflowService, CustomerCreditService, receivables/advances, payment/accounting authorities, Document Center, ApprovalService, Action Center, DC6 automation and existing V8/V1–V12 customer intelligence.
2. **Customer 360.** `/customers` is the customer/sales workspace. Owner/contact details, separate AR/overdue/advance/credit/commitment values, open commercial work, recent purchases and follow-ups. Unknown due dates are explicitly flagged. History and secondary sections load independently; financial data requires the existing AR permission.
3. **Contacts and opportunities.** Multiple contacts with roles, delivery/billing locations and deactivation, plus audited manual interactions. Lightweight customer/prospect pipeline with owner, value, closing date and dated next action. Sending a linked quotation advances an early opportunity to Quoted; Won/Lost remains a human decision. Identity and financial accounts are not duplicated.
4. **Quotation/order integration.** Draft creation and editing, stale-edit protection, Sent, Accepted/Rejected, derived Expired status, cancellation API and retry-safe conversion into one draft Order Hub order. Sent/accepted history cannot be edited. Prices revalidate at conversion; stock and credit revalidate at normal confirmation. Source lineage remains on both records. EN/SQ PDF uses the existing PDF renderer; linked support files use Document Center. Marking sent records a human status, not a communication.
5. **Pricing and credit.** Server-resolved catalogue/channel/customer/quantity rules are reused. Client line-price injection is rejected. Credit approval and separation-of-duties rules remain unchanged; acceptance of a quote is not credit approval. No new ad-hoc price override, margin-policy editor, customer-group engine or FX settlement implementation.
6. **Collections.** Follow-ups use existing OperationalTask/ActionCenterService with source identity, retry key and normal assignment/promise-to-pay handling. Existing escalation/recovery tests pass. Customer promises remain operational notes/tasks, never payments. Customer 360 links to statements and authorized payment workflows.
7. **Salesperson workspace.** Assigned/all/unassigned customers, my opportunities, open quotation/order counts, due follow-ups, authorized overdue AR by customer and recent relationship activity. Sales navigation/profile and existing customer/order pages connect to Customer 360.
8. **Automation and Ask AIMS.** Existing event/rule catalog registers quotation-sent review; bounded source-linked expiry issues disappear after acceptance/rejection/conversion without duplicate tasks. Existing overdue, credit, inactivity and stock conditions are reused. Typed read-only tools cover relationship review, due follow-ups, expiring quotes, previous-month purchases and policy credit capacity. EN/SQ/mixed wording and common quote typos are handled locally. High-risk chat requests only explain the authorized review boundary.
9. **UI.** PX1/PX2 headers, tabs, forms, tables and modals; EN/SQ, light/dark, 1366/1024/390 widths reviewed. Financial columns align numerically. No new UI library. An existing return-credit-note bug was corrected by calculating a new document before sealing its issued timestamp/checksum; the immutable tax guard remains intact.
10. **Validation.** 24 affected backend tests / 446 assertions; 22 existing frontend tests; production build; one browser customer→quote→order→delivery→invoice→partial-payment→copilot journey, resumed against the same preserved fixture during investigation. Dedicated MariaDB draft edit/stale edit/retry/tenant checks pass. All 23 new declared relationships have no missing or cross-company parents in the isolated copy. Browser and backend tests separately identify retry coverage; later browser runs reused already completed steps.
11. **Limits/readiness.** Full operational journey was verified in EUR. Final tax calculation remains with the invoice authority. Quote PDFs are generated on demand, not automatically archived or sent. Unknown AR due dates require real source review. The migration remains pending on the active database; the new workspace reports an actionable unavailable state there, while the existing Action Center remains usable once its database is available.

## Observed result and performance

Synthetic Company 8: €300 prior AR + €450 delivered sale − €400 payment = **€350 AR**; advances €0. Inventory **5,000 → 4,900 m**, reservation €0. One primary-customer quotation, order, sale, invoice, payment and task. A separate synthetic customer exercised draft editing without stock/order/payment/journal effects. Bank €400; posted journal totals and trial balance balance. This is test evidence, not company-opening certification.

Local isolated measurements: customer summary ~85–144 ms / 1.9 KB, customer list ~30–55 ms / 1.1 KB, timeline ~23–35 ms / 4.4 KB. Targeted task-source filtering reduced the measured summary query count from 201 to 74. These are small populated-fixture measurements, not a load certification. Lists use bounded pagination, quote lists omit line detail and the customer workspace never downloads the full frozen intelligence payload.

## Carried-forward DC12 blockers

- **Last verified:** 13 violated constraints on the original, 12 on the rehearsal. This run could not freshly rescan the original because port 3306 refused connection. No restart or repair of that damaged instance was attempted.
- Missing users 1/10 and PO 2; obsolete role/permission bindings awaiting security/retention adjudication; missing actor attribution; PO 1 chronology conflict. No parent, grant or legacy financial evidence was invented.
- Company 1 on the copy still has zero journals and unchanged differences: AR **868.86**, inventory **2,226.82**, cash/bank **3,190.94**, supplier advances **445,000 nominal with unproven currency**. No balancing entries or accountant approval.
- Active clean-database cutover, certified encrypted installation recovery, production commissioning and existing security/dependency blockers remain unresolved. SQL/CRM success does not waive DC8 gates.

| Required result | Outcome |
|---|---|
| Customer 360 fully connected | YES |
| Contact management implemented | YES |
| Opportunity pipeline usable | YES |
| Quotation → Order conversion works | YES |
| Conversion retry idempotent | YES |
| Pricing remains authoritative | YES |
| Customer AR remains authoritative | YES |
| Credit overrides permission-controlled | YES |
| Customer activity timeline integrated | YES |
| Ask AIMS uses authorized evidence | YES |
| EN/SQ and light/dark reviewed | YES |
| Mobile/tablet usability reviewed | YES |
| Tenant isolation verified | YES — changed CRM paths and new parent relationships |
| Existing financial/stock authorities preserved | YES |
| Ready for controlled CRM pilot | YES — isolated synthetic company only |
| Ready for real-company pilot | NO |

[VALIDATION.json](VALIDATION.json) indexes the focused evidence. Detailed local logs/screenshots are ignored under `output/dc13*`; credentials stay outside Git in the protected recovery directory. DC14 was not started.
