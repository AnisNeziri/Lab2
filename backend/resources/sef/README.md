# ATK protocol boundary

The authoritative source is linked by the [official ATK portal](https://apps.atk-ks.org/sefaplikimi/). Schema: pos-csharp commit09401c83fac00bed4ef702103468792434aef44f (2026-07-24), cross-checked with pos-golang e28cc25d32f315674c72ed5710cb2fba274f2714. The older PHP example has a stale schema and is not used.

PROVENANCE.json pins the original schema checksum. Google protoc36.2 generated the PHP bindings, using namespace options only. Runtime: google/protobuf5.36.2. Run scripts/generate-sef-protobuf.py with --protoc pointing to the verified generator to reproduce the bindings. The ATK sample's MIT notice is retained.

Prices are integers in ten-thousandths of EUR; monetary totals are integer cents. Source quantities use the schema's float field. The current C# signing profile uses ECDSA P-256/SHA-256 and P1363 encoding; the published Go example uses DER. Both profiles are explicit configuration choices. Official interoperability must confirm the chosen profile; local signature verification is not ATK acceptance.

Only the documented ATK TEST receipt endpoint is implemented. Production transport is absent. A documented400 rejection may be explicitly retried with the same frozen payload/coupon. A timeout, server error, malformed response or interrupted pending delivery remains unknown/pending and cannot be resent.

The catalog is the portal's published unit/category file. A duplicate category codePM (IDs8 and36) remains blocked pending ATK clarification. No generic unit/category fallback is applied.

AIMS is not ATK certified. Test doubles and synthetic certificates are labelled local evidence in audits.
