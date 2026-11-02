# Integration/MCP pilot operations

## Activation and credentials

Use **Administration → Integrations** with `integrations.view` / `integrations.manage`. API, MCP and local tracing have separate company switches, all off initially. Create a service account with only the required read scopes. The issuer must hold the corresponding source permissions. Credentials are shown once; store them in the client secret store. Rotation invalidates the previous credential immediately; revocation and expiry are checked on every request. Replace the account to change its immutable scope set.

Machine accounts have their own inactive backing principal. They cannot sign in through employee credentials/JWT login. They do not inherit the issuer's general role. `work.read` retains assignment and queue restrictions; use an eligible `team` and the matching domain read scope when reading a team brief.

## MCP client

Launch PHP on the AIMS host, with the backend as its working directory:

```text
command: <configured PHP executable>
args: [artisan, aims:mcp]
working directory: <AIMS>/backend
secret environment: AIMS_MCP_TOKEN=<service credential>
```

Use the client's environment/secret facility, not command arguments or a committed config. Production PHP must have the same application/runtime configuration as the normal backend.

DC9 correction: supported revisions are 2025-11-25 and 2025-06-18, from `McpServer::SUPPORTED_VERSIONS`. Initialize with the selected revision; the local adapter also accepts its explicit per-request protocol metadata. `server/discover`, `ping`, `tools/list` and `tools/call` are implemented. Legacy 2025-11-25/2025-06-18 clients may initialize first. Messages are newline-delimited JSON-RPC, limited to 64 KiB; tool results are bounded to 128 KiB. There are no write, sampling, arbitrary-resource, SQL or shell tools. Error results do not expose stack traces or private input.

For example, call `get_inventory_status` with `product_id`; returned `position` comes from InventorySnapshotService. Calls such as `adjust_stock`, `post_journal`, `pay_supplier`, `post_payroll` or `fiscalize` are unavailable. The versioned REST read contract exposes the same controlled catalog at `/api/integration-api/v1/tools` and `/tools/call` using the service bearer credential. It is not a remote MCP HTTP endpoint.

## Search/indexing and workers

No paid provider or model download is required. `BusinessEmbeddingProvider` currently binds a local 256-slot domain-concept/character-feature implementation. It supports the curated English/Albanian business vocabulary and typo similarities; it is not a general pretrained semantic model. Index/model versions are explicit. Change the provider version when its vocabulary, algorithm or dimensions change, and reindex. Old spaces are ignored rather than mixed.

The Integration Center queues metadata batches of 100 with a cursor. For an operator-controlled full backfill, use `php artisan search:reindex <company-id> [--type=product]`. Incremental model observers queue only the affected source. The index stores metadata and selected descriptions, never raw private file contents. There is no OCR/extraction approval pipeline in this phase.

The existing `php artisan aims:work default` worker drains `default,integrations,search-index`; the desktop's existing default worker therefore uses the same path. Existing optimizer/simulation workers remain separate. Restart supervised workers after deployment. During local development, a search-only worker may run independently; webhook delivery requires the normal database worker. Do not enable a real webhook until its receiver, scopes and signing policy have been reviewed.

## Webhook contract

New endpoints default disabled and use `aims.events.v1`. They subscribe only to the registered public event catalog. Legacy endpoints retain their previous envelope/signature mode, while the additional V2 header supports transition. Wildcards are restricted to the public catalog; arbitrary internal events and arbitrary metadata are not published. Review existing receiver assumptions before opting into v1.

Each endpoint delivery is a separate database-queue attempt. The payload body, event ID and delivery ID remain fixed through retries. For v1, verify:

```text
X-AIMS-Signature = "sha256=" + HMAC_SHA256(secret, timestamp + "." + raw_body)
X-AIMS-Timestamp = Unix seconds
X-AIMS-Event-ID = stable business event UUID
X-AIMS-Delivery-ID = stable endpoint delivery ID
```

`X-AIMS-Signature-V2` carries the timestamped signature in both modes. Legacy `X-AIMS-Signature` remains body-only. Reject timestamps outside a five-minute window, compare signatures in constant time, and deduplicate accepted event/delivery IDs transactionally at the receiver. `WebhookContract::verify` is the reference check. Transport is at least once; a timeout can follow a receiver-side success. A sender cannot guarantee network-wide exactly-once effects.

Only public HTTPS receivers are allowed. DNS results are checked and the selected address is pinned; redirects, userinfo, URL query strings and fragments are disallowed. Secrets are encrypted at rest, hidden on normal reads and shown only on creation/rotation. Automatic retries are bounded to five; HTTP 4xx other than 429 require review. Exhausted/permanent failures do not silently replay. Delivery history is available in Integrations. No real external delivery was performed during DC7 validation.

## Tracing and privacy

Enable company tracing in Integrations, or use the operator `AIMS_OBSERVABILITY_ENABLED` configuration. Up to 64 technical spans are retained per operation. Slow queries default to 100 ms and store a statement fingerprint, operation and duration, never SQL/bindings. Query totals and DB time appear on the HTTP result span. Trace metadata crosses queue payloads and business events; automation/tool/webhook work remains correlated.

Local span retention is seven days through the existing scheduler's `platform:prune`. Export up to 500 company spans as OTLP JSON from Integration Health. A self-hosted collector can receive that OTLP request body; automatic network forwarding, SaaS accounts and exporter secrets are not required or installed. Existing business audit logs are separate from telemetry. Full prompts/tool arguments are excluded from routine Ask AIMS audit.

Company queue counts cover newly attributed database jobs only. Older jobs without attribution remain unknown at tenant level; existing installation worker/failure checks remain in System Integrity. MCP activity records requests/denials and service identity, not a fictitious persistent connected-client count. AI configuration is shown without claiming that the optional local model's health was probed.

## Extending the platform

New package code registers a reviewed entry in the shared AimsToolRegistry, the MCP allowlist/scope mapping, SearchEntityRegistry, the public webhook contract or the existing AutomationRegistry as appropriate. Provide bounded DTOs, current authorization and focused isolation tests. Bind a different embedding implementation through the container/interface and bump its version. These are code-reviewed extension points; there is no dynamic executable plugin loader, marketplace or billing layer.
