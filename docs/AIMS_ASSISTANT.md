# AIMS guided assistant

The V9 implementation now extends this original guided assistant with server-side intelligence orchestration. Current behavior, configuration and limitations are documented in [INTELLIGENCE_ASSISTANT_V9.md](INTELLIGENCE_ASSISTANT_V9.md). The sections below describe the earlier guided-only baseline.

The shared help panel is a read-only guided assistant, not a general-purpose language model. It works with the existing Laravel API in web and desktop frontends, without Docker, an external AI subscription or a new database.

## Supported uses

- Bilingual page guides and workflow steps across the existing navigation.
- Search accessible company records by name, SKU or reference.
- Product availability and warehouse/location balances.
- Latest 25 product movements, with original units and dates.
- Existing 30-day forecasts, marked advisory and stale when applicable.
- Customer debt/advance/credit facts, purchase-order summaries and supplier details.
- Source links for verification and real actions in the original module.

Examples: `stock for Laptop Stand`, `movements for Laptop Stand`, `forecast for Laptop Stand`, `How do forecasts work?`, `stoku për Doreza`, `lëvizjet e Doreza`.

## Boundaries

Intent matching is deliberately limited and deterministic. Unsupported questions prompt record lookup or page guidance rather than invented answers. The current numeric forecasting models are not conversational language models. A future local language-model adapter can use the same read-only capability boundary, but is not bundled or claimed here.

All record reads reuse `/api/search` and the existing `/api/capabilities/{tool}/execute` allowlist. The server rechecks permissions and tenant scope; frontend routing is not an authorization substitute. The assistant accepts neither SQL nor a user-provided tool name. It cannot mutate records or bypass approvals.

Conversation exists only in memory, clears on user/company/permission change, and is limited to 40 messages. No company data is sent to external AI. Answers show read time; they are snapshots, not a live promise. Clearing or unmounting invalidates pending UI responses.

## Checks

Pure presentation tests cover bilingual intents, write refusal, tool allowlist, safe internal links and preservation of authoritative/unknown values. Browser checks cover dashboard ordering, search width, real record lookup, backend rejection, duplicate submission prevention, language, dark mode and mobile sizing. Existing PlatformFoundation tests verify backend capability permissions and company scope.
