# DC3 — Kosovo VAT and SEF readiness

This extends the existing invoice, purchase, payment and double-entry engine. **This accountant-review / ATK TEST preparation build is not certified and is not ready to enter official certification testing.** Production transport is unavailable.

Base: DC2 6a7c0917c64d4ebe88fe1827f0bd94ac81730e45. Branch: codex/dc3-kosovo-tax-fiscalization. The original checkout at 07c66520187db1486f994e9527670b1e7dcbcd24 and business database on port 3306 were not changed. No next phase, customer deployment or merge is included.

## Completion report

1. **Official requirements.** [OFFICIAL_SOURCES.json](OFFICIAL_SOURCES.json) records document hashes: MF01/2026 (ATK publication 27 Apr 2026); technical requirements 01-06-1967 dated 29 May 2026; amendment signed 22 Jun 2026; certification conditions signed 03 Jul 2026; portal test guide and unit/category catalog without explicit revisions. The [official ATK portal](https://apps.atk-ks.org/sefaplikimi/) links the fiskalizimi repositories. Canonical C# revision 09401c83fac00bed4ef702103468792434aef44f and Go revision e28cc25d32f315674c72ed5710cb2fba274f2714 agree on the wire schema. The older PHP example was not adopted. Current [ATK TV calculator](https://crmm.atk-ks.org/sq/Public/CalculatorDetail?id=8) fields 9–72 are used. The legislation register/amendment list are recorded; unresolved statutory interpretations remain review items.

2. **Tax codes / VAT.** Company-local effective versions, 18%, 8%, exempt with/without credit, export and out-of-scope categories; configurable control accounts, legal basis and SEF group. Product defaults are explicit. Existing invoices support decimal-safe inclusive/exclusive calculations with immutable snapshots. Used rules are retired/versioned. Company/customer/supplier identity fields are reused.

3. **Accounting / reconciliation.** Posted invoice/purchase VAT is compared with all configured VAT controls, including custom accounts and effective source reversals. Future reverse-charge postings now recognize both self-assessed output and eligible input VAT. Nonrecoverable VAT remains cost. New customs assessments separate their taxable base from the VAT-only payable: base €10,000 / VAT €1,800 means AP €1,800, not €11,800. Source-linked landed costs exclude recoverable VAT, reserve available cost under locks and reclassify the original cost account. Posted history is not rewritten.

4. **VAT period / reporting.** /tax presents the current form, source and aggregate input/output drill-down, journal links, reconciliation and CSV preparation export. Normal endpoints aggregate and paginate. Purchase eligibility months remain separate from accounting-date GL comparisons. Sales credits appear in form 53/55 while economically reducing output VAT. Non/partial deduction, ambiguous corrections and cross-month tax points remain explicitly incomplete. Carry-forward/refund choices are external facts. Nothing is filed electronically.

5. **SEF boundary.** Existing issued EUR document → reviewed policy → official protobuf → signer → fixed ATK TEST client → immutable encrypted request/attempt/result. Missing unit/category mappings block preparation. The official catalog duplicates category code PM; those entries require ATK clarification. Actual source payments must prove settlement; cash is never guessed.

6. **Signing / security.** P-256 key/certificate pairs, identity and validity are checked. Secrets are encrypted and hidden from API output. Server command sef:credentials accepts file paths and an optional passphrase environment-variable name. Keys never enter browser forms or audit logs. P1363 (published C#) and DER (published Go) profiles are explicit; ATK acceptance still needs confirmation. Restore/key failures block submission and preserve evidence.

7. **Lifecycle / idempotency.** Company/source/coupon/fingerprint/provider ownership is unique. A pending attempt commits before network access. Only a documented 400 rejection is retryable, retaining the same coupon and unsigned request. Timeout, 500, malformed success or crash-pending outcomes cannot resend. A review note never unlocks them. Confirmed-success replay returns the existing receipt. QR uses the successful attempt's signature. Automated environments require protected HTTP doubles.

8. **Fiscalization Center.** /fiscalization shows TEST configuration, certificate metadata, status counts, a centralized policy-review count, recent deliveries requiring review, issued-document policy, explicit prepare/submit/retry, review history and receipt evidence. Successful TEST receipt QR content follows the published base64-protobuf/signature representation and renders locally. Certified commercial receipt printing is not claimed.

9. **Accounting / Integrity / Ask AIMS.** VAT balances/investigation links appear in the Control Center. VAT/GL and document/journal differences are critical mandatory close controls; unresolved TEST deliveries are attention. Tax-account references/manual journal acknowledgements cover custom VAT controls. English/Albanian Ask AIMS explains VAT, fiscalization failures and missing classifications through read-only tools. It cannot change rates, fiscalize, file, post or close.

10. **Official ATK TEST.** **NOT TESTED — registered credentials/access unavailable.** Local doubles/self-signed fixtures prove internal behavior only. They are never reported as external interoperability. No production endpoint was called.

11. **Certification readiness.** **NO.** Outstanding: ATK-issued registration/certificate, signing-profile acceptance, consumer retail workflow, on-credit/partial-settlement and partial-refund scenarios, offline chronology/tax-block procedure, compliant receipt printing/languages, certification dossier and commission decision. One registered TEST POS per company is supported, with an explicitly confirmed unused business coupon counter.

12. **Validation actually run.** [VALIDATION.json](VALIDATION.json) records overlapping focused runs: new-area 13 tests / 186 assertions on SQLite and MariaDB 11.8.9; earlier focused SQLite 35 / 382; existing-finance 66 / 534; existing dated customer/inventory journey. Counts overlap and must not be added. Six frontend checks, production build and one browser journey passed. Separate MariaDB workers produced one receipt/attempt/mock send under concurrent prepare/submit. A cloned DC2 database upgraded additively, preserving hashes for 72 journals, 144 lines and six expense tax records. The upgrade permission refresh also preserved an existing revocation and withheld tax configuration/fiscal submission from managers. No normal PM3/PR full synthetic suite or customer deployment ran.

13. **Remaining accountant/regulatory review.** Confirm classification/credit rights, full current VAT-law/amendment interpretations, advance/continuous-supply tax points, cross-month adjustments, partial/nonrecoverable return bases, import/customs evidence, debit-note/bad-debt/correction categories, retention/export procedures and certification scenarios. Existing B2B invoices are authoritative; standalone Daily Sales without issued tax invoices remain visibly unclassified. This is not a legal compliance opinion or certified Kosovo filing.

## Explicit status

| Control | Result |
|---|---|
| VAT 18% supported | YES |
| VAT 8% supported | YES |
| Exempt/export treatments supported | YES — explicit classification/legal basis required |
| VAT ↔ GL reconciliation | PASS in exercised journeys |
| Historical posted tax treatment immutable | YES |
| SEF payload based on current official specification | YES — pinned schema; external acceptance untested |
| Fiscalization duplicate prevention | PASS |
| Unknown-result retry protected | PASS |
| Fiscal secrets protected server-side | YES |
| ATK TEST API exercised | NOT TESTED |
| Official ATK certification obtained | NO |
| Ready to enter official SEF certification testing | NO |

## Operator workflow

Before using the new web pages after an upgrade, run the additive migrations and then php artisan tax:setup in the reviewed release. This refreshes the existing permission catalog while preserving customized grants and revocations. Normal offline desktop setup already performs that preserving refresh. Sign in again to refresh the client permission list.

1. In Tax & VAT → Tax configuration, initialize missing templates, confirm legal basis/accounts, version rules and explicitly classify products. Product names never determine tax. Optional required-from policy disables percentage-only source entry for future dates.
2. Select codes in existing invoice/supplier/expense forms and review net/VAT/gross. Customs VAT-only assessments have a separate taxable base. Record goods, duties and services separately. Link posted bills to landed costs when reclassifying their capitalizable cost.
3. Review VAT period, incomplete groups and timing evidence. Drill to sources/journals. Correct VAT/GL differences through the owning source module before close.
4. Register externally through ATK's official workflow. Configure genuine TEST identifiers, reviewed activity and next unused coupon number. Install registered credentials locally through sef:credentials. Do not send private keys/passwords in chat.
5. Correct mappings, prepare, review, then intentionally submit to TEST. A 400 rejection may be explicitly retried after correction. Pending/unknown deliveries require ATK confirmation; review notes cannot unlock retries.
6. Preserve the application encryption key in controlled recovery. Reinstalling a signing key does not decrypt historical evidence. Preserve all source and attempt lineage.

## Focused reproduction

- Backend PHPUnit: DC3TaxControlTest.php, DC3FiscalizationTest.php, DC3VatReportingTest.php. Existing DC2 tests cover the engine.
- MariaDB: disposable loopback aims_dc3_*_test databases only. tests/Support/dc3-fiscal-concurrency.php additionally requires owned port 3312. Never use the business database.
- Frontend: node --test tests/dc3-tax.test.mjs tests/navigation.test.mjs; node node_modules/vite/bin/vite.js build.
- Browser: node node_modules/@playwright/test/cli.js test --config=playwright.dc3.config.js --project=chromium. This migrates existing isolated backend/database/e2e.sqlite upward, creates a local synthetic signing fixture, and uses ports 8010 / 4173. It cannot send to ATK.
- Protocol bindings: scripts/generate-sef-protobuf.py --protoc <verified-protoc-36.2>. Provenance/license: backend/resources/sef. Local QR renderer provenance/license: frontend/src/vendor.

Small-fixture probe: VAT period 7 queries/~8.7KB, fiscal summary 10 queries/~1.4KB, invoice page 4 queries/~1.1KB. These bounded observations are not a scale certification. Existing PDF/3D build chunk warnings remain.
