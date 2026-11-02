# DC7 — Platform interoperability, search and observability

Implemented on `main`, HEAD `fed921ad319d5f360281f11df67fbb5b96e1f656`, in the existing checkout. Changes are uncommitted. No branch, worktree, marketplace, new business engine or DC8 was created.

## Completion report

| Area | Result |
| --- | --- |
| 1. Runtime inspected | Laravel 12.69.3, PHP 8.3.35, Reverb 1.10.2, Predis 3.5.0, local MariaDB 10.4.32, Node 22.14.0 and Vite 6.4.3. The existing Python 3.12 intelligence/optimization boundary is preserved. Authentication is the existing JWT/hashed API-token implementation; Sanctum/Passport are not installed. No dependency/framework upgrade. |
| 2. Integration architecture | A separate `/api/integration-api/v1` read contract uses IntegrationGateway authentication, scope mapping, strict tool arguments, existing AIMS reads and identifier-only audit. Internal frontend endpoints retain their paths. |
| 3. Service accounts | Independent machine identities use inactive, non-login backing principals rather than employee credentials. Credentials are random 256-bit values, stored as SHA-256 hashes, shown only at creation/rotation, expiring and immediately revocable. Scope grants require the creating administrator's corresponding permissions. Company API/MCP access defaults off. |
| 4. MCP | Local stdio adapter: `php artisan aims:mcp`. DC9 correction: supports 2025-11-25 and 2025-06-18 consistently across initialization, discovery and request validation. Every request rechecks the current credential, tenant, scope and resource permissions. Unknown methods/actions are rejected. No remote MCP HTTP/OAuth server is claimed. |
| 5. Tools | Thirteen explicit read tools reuse AimsToolRegistry and AssistantToolRunner: inventory status/availability, customer profile/exposure, supplier, order status, PO, shipment, operating brief, accounting control summary, payroll summary, business search and document search. Each has input/output schema, permission/scope, tenant, read-only annotation, underlying tool and audit metadata. No financial, stock, payroll, fiscal or arbitrary-code write tool is exposed. |
| 6. Hybrid search | Existing exact/keyword/fuzzy search is complemented by a replaceable local domain-concept embedding provider. Exact identifiers rank above semantic candidates. Model/index versions remain separate; incompatible spaces are not mixed. Candidate retrieval is bounded and source hydration is batched. Numeric debt queries use live customer exposure; dated shipment/contract questions use current source dates where supported. |
| 7. Documents | Metadata indexing covers titles, references, tags and document types, alongside selected product/customer/supplier/order/PO/shipment/employee/term metadata. Final document visibility is rechecked, including confidential, workforce and payroll restrictions. No raw file, OCR text, salary, bank detail, journal-line or audit-log embedding. Retrieved text is evidence and never triggers another action. Ask AIMS retains source links and uses deterministic stock/finance tools for current facts. |
| 8. Webhooks | Extended the existing event/endpoint/delivery system. Allowlisted event DTOs, frozen retry bodies, stable event/delivery IDs, timestamped HMAC signatures, rotation, per-delivery ownership lock, bounded retries and delivery history. Endpoint attempts run as separate queued jobs; disabled endpoints stop delivery. Public HTTPS addresses are resolved and pinned, redirects are disabled, and credentials/query strings are rejected in new URLs. Existing legacy envelope/signature mode remains available with an additional V2 signature. |
| 9. Observability | Opt-in local, bounded technical spans with W3C trace propagation and OTLP JSON export. HTTP, query totals/slow fingerprints, cache outcomes, jobs, automation rules, shared AIMS tools and external/webhook calls reuse one trace collector. Queue payloads and business events retain trace correlation. Seven-day pruning is scheduled. Query bindings, prompts, response bodies, salary values and secrets are excluded. |
| 10. AI governance | Existing IntelligenceProvider abstraction/local classifier remains. The embedding provider has a separate interface/version. Tool execution stays server controlled; retrieved content cannot grant permission. Routine Ask AIMS audit now records prompt length and tool/status metadata rather than full prompts/tool arguments. Remote providers and automatic external telemetry export are not enabled. |
| 11. Laravel 13 assessment | Kept Laravel 12 to avoid unrelated inventory/accounting/payroll risk. Native vector infrastructure, AI SDK adoption, queue conveniences, security improvements and Mercure are classified in [TECHNOLOGY_REVIEW.md](TECHNOLOGY_REVIEW.md). Existing Reverb and fallback remain. |
| 12. Performance | In the same small SQLite fixture, Global Search fell from 122 to 108 queries, with the same 1,573-byte response: long natural-language phrases bypass the costly entity-name typo resolver and semantic sources load in batches. Trace writes are batched; indexing uses atomic source-key upserts. Action Center's 105 queries are reported rather than broadly rewritten. This is not a scale certification or production latency claim. |
| 13. Validation | **48 backend tests / 445 assertions**, final boundary regression **15 / 209**, **20 frontend tests**, production build and **one passing Chromium/real-stdio journey**, plus populated SQLite upgrade, local MariaDB additive upgrade and a real after-commit queue-outage probe. Portable counts/evidence: [VALIDATION.json](VALIDATION.json). |
| 14. Limits | Local MCP transport only; domain-concept semantics rather than a pretrained multilingual model; metadata-only document retrieval; bounded candidate recall; local OTLP export rather than a managed collector deployment. Older unattributed jobs have unknown company backlog attribution. No full PM3/PR or external regulatory certification. |

## Required results

| Check | Result |
| --- | --- |
| Existing business authorities preserved | YES |
| External integrations cannot directly mutate models | YES — first contract is read-only |
| MCP available | YES — permission-scoped stdio adapter |
| MCP read tools tenant/permission scoped | YES |
| High-risk MCP writes unavailable/approval-protected | YES — unavailable |
| Semantic + exact hybrid search available | YES — local domain-concept/fuzzy semantics with explicit limitations |
| Exact business IDs outrank semantic matches | YES |
| Semantic search respects permissions | YES |
| Documents remain permission-scoped | YES |
| Paid embedding provider required | NO |
| Webhook signatures implemented | YES |
| Webhook retries idempotent | YES — stable delivery/body/event identity; receivers must deduplicate |
| Optional integration failure cannot block core ERP operation | YES — validated with missing optional tables and a real after-commit queue outage |
| OpenTelemetry-compatible tracing available | YES — W3C context and OTLP JSON export |
| Sensitive telemetry protected | YES |
| AI provider coupling reduced/preserved behind abstraction | YES |
| Laravel upgrade performed | NO — unnecessary for this implementation |
| Ready for controlled integration/MCP pilot | YES — within the documented read/metadata/webhook scope |

## Validation and preservation

The focused backend command covers `DC7PlatformTest`, `ProductMaturitySearchTest`, `IntelligenceAssistantTest`, `DC6OrchestrationTest`, `SupplyChainControlTowerTest` and `ProductionSecurityTest`. It validates tenant/permission boundaries, credential revocation/rotation, protocol versions, denied writes, exact precedence, document reauthorization, webhook signature/retry identity and telemetry redaction. The browser journey creates a scoped synthetic credential, launches the actual PHP stdio command, reads current Milano stock, rejects an adjustment, searches by concepts and checks the administration view on mobile.

The populated SQLite upgrade preserved **204 tables / 1,656 existing rows** without a fresh database. Local MariaDB was backed up to `backups/before-dc7-20261031-07b31a27.sql` (**8,795,305 bytes**); original-column hashes/row counts stayed unchanged in **20 business/automation/queue/webhook tables**. The local search metadata index was populated with **46 records**; existing business rows were not rewritten. API and MCP remain disabled for all existing companies until explicitly enabled.

Before editing, 59 existing changed files were snapshotted. **53 remain byte-identical; six shared integration files were extended.** Inventory, accounting, customer debt, procurement, V11 planning, payroll and fiscalization authority files match HEAD. No commit or push was performed.

Local evidence lives under ignored `output/`: `dc7-focused.log`, `dc7-final-controls.log`, `dc7-frontend.log`, `dc7-build.log`, `dc7-browser.log`, `dc7-browser-report/`, `dc7-profile-before.json`, `dc7-profile.json`, `dc7-upgrade.json`, `dc7-local-upgrade.json`, `dc7-outage.log`, `dc7-runtime.json` and `dc7-preservation.json`. [Operations and client setup](OPERATIONS.md) describes activation, queues, indexing, signatures and trace export.

The seven-path synthetic baseline is included in VALIDATION.json. Production volumes, general-language semantic recall, external client products, real public webhook receivers and a live OTLP collector require their own controlled pilot validation. HTTPS delivery is at least once, not exactly once across the network. Exhausted/permanent failures require operator review rather than unbounded replay.

DC8 has not been started.
