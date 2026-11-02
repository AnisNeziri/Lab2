# DC8 completion and commercial release decision

Implemented in the existing `main` checkout, HEAD `fed921ad319d5f360281f11df67fbb5b96e1f656`. Changes are uncommitted. No branch, worktree, commit, push, customer deployment or DC9 was created.

**Commercial deployment pilot: NO, pending the release blockers below.** The security/commercial feature journey passes locally. This is evidence for a controlled review, not security certification.

| Requested area | Delivered behavior |
|---|---|
| 1. Authentication architecture | Existing custom HS256 JWT, hashed refresh/API credentials and password login retained. No Sanctum/Passport migration. Generic credential errors, bounded inputs and failed-login threshold events. Newly chosen/reset passwords require 15 characters and at most 72 UTF-8 bytes; existing hashes remain valid. |
| 2. Passkeys | `lbuchs/webauthn` 2.2.0 verifies standard browser registration/assertions. Named, discoverable credentials; user verification required; exact configured origin, RP hash, signature, credential ownership, backup flags and counters checked. Three-minute random challenges are atomically consumed before verification. Replays and failed-attempt reuse fail. |
| 3. Sessions/tokens | Server-tracked devices, login/last-active times, own-session revocation and revoke-others. JWTs bind a session and current token version. Refresh rotates within that session without renewing recent authentication. Disable/role change revokes access; password changes/resets revoke old credentials. Existing legacy API tokens receive a 30-day compatibility expiry and cannot satisfy step-up. |
| 4. Authorization | Company role overrides layered over existing role defaults; effective permissions also require the capability. Ordinary managers cannot change company security or employee accounts into administrators. Service identities must remain inactive `integration` users, preventing employee-role inheritance. Sensitive integration, fiscal configuration, payroll configuration/export and security changes require recent authentication. Enforced administrator passkeys also protect factor enrolment and policy removal. |
| 5. Secrets/data | Existing encrypted HR/fiscal/provider/document boundaries reused. Encrypted installation key witness and stable random installation ID added; migration first checks existing encrypted evidence where present. No issuer private key ships in AIMS. API headers/no-store strengthened; document sandbox retained; existing explicit CORS policy preserved. Nginx frontend CSP starts in report-only mode pending complete target-host workflow review; the security browser journey also passes with enforcement. |
| 6. Entitlements | Separate server-authoritative capability registry, checked in API access, effective permissions, MCP/API identities and queued automation/webhooks. Navigation, direct pages and search commands filter unavailable capabilities. Existing companies retain legacy capabilities until explicitly configured. |
| 7. Licensing | RSA-SHA256 signed schema-2 license envelope binds company UUID and installation UUID, package/capabilities, validity and signed offline grace, capped at 30 days. Every request revalidates issuer signature. Re-import cannot reset the clock high-water mark. Expiry restricts premium changes while preserving existing reads and core security/license resolution. |
| 8. Packages | One platform; generic capability composition with platform, distribution and core-accounting templates. No pricing, billing provider, vertical implementation or code fork. Manual configuration is visibly distinct from an issuer-signed commercial license. |
| 9. Provisioning | Existing registration/onboarding reused. Company UUID, timezone, currency, locale, initial administrator and distribution capability defaults are provisioned together. Existing companies/custom role grants are not reset. Sales, Purchasing, Warehouse and Read Only templates are added without resynchronizing existing templates. |
| 10. Releases/updates | Explicit release/build/schema/runtime metadata. One signed update contract; trusted issuer signature, runtime/version path, archive hashes and full immutable staged-tree digest checked. Added executable files are detected. Current trusted release verifies staged code before execution. Server script records stages and whether migration/code activation occurred. Unattended Electron download/install disabled. |
| 11. Backup/recovery | Existing encrypted authenticated archive service retained. Checkpoints require decrypting/verifying the archive, database/engine/key compatibility and current file hash; update preflight requires a recent snapshot. Restore clears browser sessions/challenges and revokes/disables integration access. Owner passkey recovery requires maintenance, a separately escrowed recovery key and reason; ordinary managers cannot invoke it. |
| 12. Security/System centers | `/security`: personal passkeys/devices plus authorized adoption/access, segregation warnings, company policy and meaningful events. `/system-health`: explicit healthy/attention/blocked/not-probed evidence for key, database, schema, workers, storage, backup, license, onboarding, accounting and integrations. No invented security score or inferred mail/Reverb success. |
| 13. Support | Explicit authorized JSON support bundle: version/runtime, migration/queue/integration health, bounded technical error evidence and presence booleans. No raw logs, environment dumps, credentials, salary/bank values, files or business database dump. Existing incident reference retained and linked to DC7 trace context. Authorized bounded security/decision audit export provided. Ask AIMS gets read-only permission-scoped security summaries; no security write tool was added. |
| 14. Dependencies | Composer Symfony Mime/Yaml advisories patched; Vite and compatible transitive npm fixes applied. PHP patched from 8.3.35 to the verified official **8.3.36-1 Windows build** (runtime reports 8.3.36). Composer/frontend/declared Python audit results are clean. Full desktop audit still reports **1 high / 15 moderate**, including Electron 41.10.7. Release workflows now check desktop development/runtime dependencies too. [Dependency inventory](DEPENDENCIES.json) includes Composer/npm/Python pins and native runtime requirements. |
| 15. ASVS findings | [Targeted ASVS 5.0 review](SECURITY_REVIEW.md): fixed session control, factor-policy bypass, shared tenant permissions, service-identity role inheritance, weak new-password floor, key-readiness and update-integrity gaps. Remaining deployment/runtime findings are explicit; no ASVS certification claimed. |
| 16. Validation | **42 unique focused backend cases** (core run: 38 / 354 assertions; three administrator compatibility checks; one additional backup regression), **22 frontend tests**, **5 desktop tests**, production build, one Chromium journey with real virtual-authenticator signatures and production-style CSP, and self-contained desktop startup. Populated SQLite upgrade preserved **207 tables / 1,980 rows**; authenticated archive/preflight and staged-code tamper rejection passed. Local MariaDB additive upgrade preserved original fields in **20 business tables**. Details/hashes: [VALIDATION.json](VALIDATION.json). |
| 17. Limits | See release blockers and operational limits below; no remote deployment or Windows Hello hardware attestation certification performed. |

## Required decisions

| Check | Result |
|---|---|
| WebAuthn/passkeys implemented | YES — company users; standard browser ceremonies |
| WebAuthn replay protection verified | YES |
| Password-only operation still supported where policy allows | YES |
| Session revocation works | YES |
| Disabled user loses privileged access appropriately | YES |
| Sensitive endpoints enforce authorization server-side | YES |
| Entitlements separate from permissions | YES |
| Entitlements enforced server-side | YES |
| Future industry packages can compose capabilities | YES |
| License data cannot be changed only from frontend | YES |
| Signed/offline license verification available | YES — trusted issuer must be configured |
| Temporary internet outage does not immediately disable offline ERP | YES — local verification and signed grace |
| Expired license does not destroy company data | YES |
| Update manifest signature verified | YES |
| Tampered update rejected | YES — signature, archive and staged-tree checks |
| Production upgrade avoids migrate:fresh/destructive reset | YES |
| Application encryption-key compatibility checked before restore/update | YES |
| Support bundle excludes sensitive business secrets/data | YES |
| Security telemetry remains sanitized | YES |
| Two-company security/licensing isolation verified | YES |
| ASVS-based targeted review completed | YES — not certification |
| Known critical security issue remaining | NONE identified by this targeted review; high desktop findings remain |
| Ready for controlled commercial deployment pilot | NO |

## Release blockers and operational limits

1. **Electron remains a release blocker.** Its pinned 41.10.7 runtime has a high advisory and the desktop build tree has additional moderate findings. Compatible transitive fixes were applied; no forced major framework upgrade was kept. The full desktop audit intentionally blocks commercial release until a separately reviewed supported Electron/build chain is validated. Native Windows signing/publishing was not performed.
2. **Production commissioning remains required.** Configure the real HTTPS origin/RP, trusted issuer public keys, recovery escrow, production mail if needed, least-privilege database credentials, supervised workers/scheduler, verified installation backup and host security. The development MariaDB 10.4 installation is not a certified production deployment. Actual Linux Nginx/systemd upgrade execution and host hardening are not proven by local tests.
3. **Distribution is staged/manual.** No arbitrary URL downloader, unattended installation or automatic rollback. The server upgrade verifier requires a current release with DC8 available; initial adoption from older releases needs a reviewed bootstrap. Release/build identifiers must be stamped by the controlled build process, followed by signing actual artifact/tree hashes. The working release remains `uncertified` with no fabricated production commit.
4. **Passkeys have a configured site identity.** Local web users should open `http://localhost:5173`; passwords still work at `127.0.0.1`. Desktop frontend origin is now `localhost` for passkeys, so previous browser sign-in storage may need a fresh login. Changing RP/site identity needs a migration/re-enrolment plan. Browser evidence uses Chromium's UV-capable virtual authenticator, not physical Windows Hello/Touch ID certification. Global platform recovery identities are outside company passkey-policy enforcement.
5. **Legacy desktop licensing is a compatibility bridge.** Existing schema-1 hardware-bound licenses remain readable; signed expired licenses allow access and the server restricts premium writes. New company licensing uses random installation/company UUIDs. No hardware fingerprint was added. Moving deployed legacy licenses to issuer-managed schema-2 licenses still needs the issuer/operator process. Host administrators who can modify application binaries/database/configuration are outside this software-only licensing trust boundary.
6. **No broad certification claim.** Persistent browser bearer storage is retained; no cookie-auth migration, SAML, TOTP, remote licensing/billing, malware scanner, external incident service or comprehensive penetration test was added. Existing document storage/scanner-provider boundaries remain available for later deployment integration. Unknown/not-configured health is shown explicitly.

## Preservation and evidence

All **116** pre-existing changed files were snapshotted under ignored `output/dc8-baseline/`. **98 remain byte-identical; 18 shared files were extended; none was deleted.** Stock, accounting, customer debt, procurement, inventory planning, payroll and fiscalization authority files still match HEAD.

Local MariaDB upgrades were backed up before each attempt. The first attempt exposed MariaDB's legacy implicit TIMESTAMP defaults; explicit date-time fields and resumable additive steps fixed it. Both migrations completed without resetting/reseeding business data. The original backup is `backups/before-dc8-20261031-f47739b5.sql`; the latest pre-step backup is `backups/before-dc8-20261031-c433274b.sql`. No keys or passwords were changed for existing users.

Ignored evidence includes `output/dc8-focused.log`, `dc8-frontend.log`, `dc8-build.log`, `dc8-browser.log`, `dc8-desktop-tests.log`, `dc8-desktop-startup.log`, `dc8-admin-compatibility.log`, `dc8-backup-boundary.log`, `dc8-upgrade.json`, `dc8-local-upgrade.json`, `dc8-release-recheck.log`, `dc8-preservation.json` and the four ecosystem audit JSON files. [Operations](OPERATIONS.md) explains configuration, recovery, signing and upgrade boundaries.
