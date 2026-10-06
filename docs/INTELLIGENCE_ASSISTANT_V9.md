# Intelligence Assistant & Executive Copilot V9

V9 extends the existing shared assistant. Open **Intelligence → Intelligence Assistant**, use **Ask AIMS Assistant** above a page, or open Ctrl+K and choose **Ask AIMS**. Record views can pass an explicit, server-validated record ID. Product details have a contextual button.

## Architecture and evidence

`AssistantPlanner → AssistantToolRunner → AimsToolRegistry → authoritative V1–V8 services → AssistantComposer`.

The free deterministic core supports executive briefs, priority decisions, stockout/replenishment, supplier attention, shipment risks, cash horizons, receivables, inactive customers, sales opportunities, decision explanations/alternatives, actual version changes, calendar sales summaries and read-only scenarios. Briefs use V5 severity/risk ordering and cap each domain to avoid duplicate alert walls. Empty sections are omitted. No model training or company-wide forecast rebuild runs in a chat request.

Questions can use English or Albanian business terms. Use quoted names to identify records. Ambiguous records require explicit selection. A selected new entity replaces the old context. A brief can be followed by “Explain the inventory one”, “What if I buy only 4,000 m?” and “What does that do to cash?”. Customer capacity asks for a product when the customer alone is known; a selected product then combines actual availability, incoming dates, V4 planning and authorized credit evidence.

Numbers are copied from structured service outputs. Null remains unknown. Source links, read time, available evidence cutoff, stale state, assumptions, alternative evidence and limitations are included. Historical comparisons use actual immutable V5 versions, never reconstructed yesterday balances. Calendar summaries use the saved user timezone when configured, otherwise the application timezone, and only recorded dates up to today.

## Optional local language adapter

Default `ASSISTANT_PROVIDER=deterministic` needs no other application. Optional `ASSISTANT_PROVIDER=ollama`, `ASSISTANT_LOCAL_URL=http://127.0.0.1:11434`, and `ASSISTANT_LOCAL_MODEL=<an installed local model>` enable intent classification of otherwise unknown phrasing. The adapter uses the [official local chat API](https://docs.ollama.com/api/chat). No model is downloaded or bundled by this change. Only HTTP loopback hosts are accepted, redirects are disabled, and the request has a three-second limit.

The model receives the question, not company records, document contents, credentials or conversation history. Its only accepted output is an allowlisted intent. It cannot select a tool, supply an entity ID, calculate a business value or approve an operation. Timeout, invalid output and unavailability keep deterministic answers available. The UI distinguishes “configured” from actual availability and displays fallback when used.

## Scenario and action boundaries

V4 compares hypothetical quantities, supplier delays and demand multipliers. V7 computes incremental purchase cash impact using recorded supplier prices and fixed-precision math. Thin read-only adapters use existing math for one customer's payment delay and one shipment's dated PO receipts/documented arrival-linked payments. They do not change debts, dates, stock, forecasts or accounting. Shipment results are bounded to five linked products. Unknown dates remain unknown; “next month” requests a concrete day/delay rather than inventing one. Uncontracted additional sales are not invented cash inflows.

“Prepare the recommended option” shows the existing V5 Purchase Request review with a ten-minute server-owned token. Only the explicit **Confirm — create Purchase Request draft** button calls the existing V5 draft service. It revalidates live evidence and permissions, keeps idempotency, and retains existing submission/approval rules. A chat “yes” never executes. No PO, payment, reservation, approval, email or stock movement is performed by chat.

## Safety and operations

Each tool is registered/read-only, catalog-permitted, schema-validated and company-scoped. Unknown arguments and repeated identical calls are rejected. Each question has at most six calls and a twelve-second scheduling budget; existing service/database timeouts still apply to an individual call. Stored text is rendered as text and never interpreted as planner instructions.

Context is server-owned, scoped to company + user, bounded to selected entities/scenario and expires after sixty minutes. Production/desktop context uses the local file cache so stateless HTTP requests retain follow-ups without Redis. The UI transcript is memory-only, capped at forty messages, and clears on identity/permission change. Queries, tools, entity context, scenario, action previews/results, response state and duration are stored through existing immutable analytics audit records. No hidden reasoning is stored. Audit retention follows the existing audit policy; the assistant does not delete immutable history.

Helpful/not-helpful feedback is response-owner checked and supports incorrect, unclear, missing-data and not-useful reasons. The authorized usage summary shows bounded company aggregates over thirty days; no individual productivity scoring or automatic retraining.

## Web and desktop

Both frontends use the same local Laravel endpoint and shared components. Offline answers reflect only records and calculations available in that installation. Read time is not sync time; the assistant does not claim a cloud sync. Live AIS or absent remote data cannot be recovered by conversation. This change updates shared source and the frontend production build; an already installed desktop executable needs a new installer build to contain it.

## Limits

This is a grounded decision assistant, not unrestricted general AI. It cannot certify insolvency, forecast unavailable sources, reconstruct missing history or guarantee logistics/cash outcomes. Evidence lists are bounded and source pages remain authoritative. Local language support is optional intent interpretation, not fluent model-authored financial prose. Future V10 should focus on evidence/outcome evaluation, not autonomous execution.
