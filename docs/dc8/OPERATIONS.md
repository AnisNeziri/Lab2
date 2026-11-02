# DC8 operator guide

## Preserve the checkout and data

This cycle uses the existing `main` checkout. Do not reset previous DC5–DC7 changes to install DC8. Back up the database and separately escrow the original `APP_KEY` before applying additive migrations. Never run `migrate:fresh` or blindly run a reverse migration. DC8 migrations deliberately refuse destructive rollback.

The migration is resumable after MariaDB DDL interruption. It first tries existing encrypted evidence, then establishes an encrypted key witness and stable random installation UUID. A failed key check is a blocker, not permission to generate a new key. Installation restore already requires the original key before importing data into a separate empty database.

## Passkeys and authentication

Configure `AIMS_PASSKEY_RP_ID` and `AIMS_PASSKEY_ORIGINS` to a stable domain and exact browser origins, for example `erp.company.example` and `https://erp.company.example`. Do not derive trust from the request Host header. HTTPS is required except the supported localhost development/desktop origin. For the local web workspace, use `http://localhost:5173/security`; passwords continue to work at `127.0.0.1`. Desktop now opens its frontend at localhost with an explicit matching RP/origin. Changing the origin can require a fresh browser login; company data and the application key do not move.

Open Security → Passkeys & devices. Add a named passkey, keep a second recovery passkey and verify sign-in before enabling administrator, finance or payroll enforcement. Biometric/PIN verification remains on the authenticator; no biometric data is stored by AIMS. Attestation is `none`; device provenance/enterprise attestation is not certified. Company passkey policy does not cover global installation recovery identities.

Challenge lifetime is three minutes, single-use even after a cryptographic failure. Public option/verification requests use separate bounded rate limiters. Access JWT lifetime remains 15 minutes by default. Server sessions default to 24 hours, configurable 1–720 hours; refresh cannot extend that session or refresh its authentication age. Sensitive actions require authentication within ten minutes. When administrator passkeys are enforced, password-only sessions cannot register replacement credentials, remove enforcement, rotate integration secrets or perform other recent-authentication operations. Ordinary warehouse users do not acquire a mandatory step-up prompt.

New/reset/temporary passwords require 15 characters and no more than 72 UTF-8 bytes. Existing passwords remain valid. Password changes/resets revoke old tokens. Sessions & devices shows only the user's own sessions, approximate browser/platform and login IP context; no raw token is returned. Removing a passkey revokes that user's sessions, requiring sign-in again.

Existing hashed user API tokens receive a 30-day transition expiry during upgrade; they cannot satisfy sensitive step-up. Move machine clients to DC7's scoped service accounts. Rotating a refresh token never creates an additional device session. Role change or disabling an account invalidates prior privileges immediately.

## Recovery

Password recovery uses the existing verified-email channel if production mail is configured. A password reset cannot remove enforced passkey policy. Ordinary company managers cannot recover another administrator or change security policy.

For installation-owner break-glass recovery, generate a separate random secret of at least 32 characters outside the repository, protect it in a file/owner escrow and configure only its password hash as `AIMS_IDENTITY_RECOVERY_KEY_HASH`. Do not put the secret in a command argument, `.env` example, support bundle, database field or chat. Keep the original application key separately as well.

During approved maintenance, stop writers/workers and use:

```text
php artisan down
php artisan aims:recover-identity --company=<exact-company-id> --user=<exact-admin-id> --key-file=<protected-owner-key-file> --reason="Verified owner recovery reason"
```

The command requires maintenance, a correct escrowed key and an active administrator in that exact company. It revokes the user's passkeys/sessions, disables administrator passkey enforcement so the owner can enrol again, and preserves security plus business audit evidence. It does not expose or replace the password. Complete existing verified-email password recovery if necessary; register replacement passkeys, review sessions and re-enable policy before reopening access. No recovery credential is automatically configured by DC8.

## Company capabilities and licenses

Permissions answer whether a person can act; capabilities answer whether the company has that module enabled. Both are required. Existing companies retain their capability set; registration provisions a distribution template with explicit company UUID/timezone/currency/locale. New role templates do not overwrite existing/custom grants. Owner and Administrator continue to use the existing `admin` role; there is no new ownership engine.

Security → People & access shows groups, advanced effective permissions and segregation warnings. `PUT /api/security/roles/{role}` is an administrator-only, recent-authenticated company customization of a non-administrator role. It accepts only current grantable permission slugs and cannot delegate security/deployment/licensing administration. Other companies remain independent.

System Health → Package & license allows deliberate configuration while a company is unmanaged. This is not proof of purchase. Once an issuer-signed license is installed, capability changes require another issuer-signed license. No pricing, payment provider or remote licensing service is enabled.

Configure `AIMS_LICENSE_PUBLIC_KEY_FILE` to a protected issuer **public** PEM file. Private RSA signing keys must remain outside distributed AIMS installations. The standard RSA-SHA256 envelope is:

```json
{"payload":"base64url-of-exact-UTF8-JSON-bytes","signature":"base64-RSA-SHA256-signature-over-those-bytes"}
```

The signed payload requires `schema: 2`, `product: "AIMS"`, `kind: "license"`, `license_id`, `company` (company UUID), `installation_id` (installation UUID), `package`, a known `capabilities` array containing `core`, `valid_from`, `valid_until` and `offline_until`. The signed offline extension cannot exceed 30 days. Use the identities shown in System Health; do not copy a license between companies/installations.

Verification is local. Internet loss does not invalidate a still-valid signed lease. During signed grace the existing capabilities remain usable. Expiry or clock rollback preserves reads and core security/license resolution while restricting premium changes. Re-importing a document cannot reset the time high-water mark. Existing schema-1 desktop activation remains a compatibility bridge; expired authentic licenses allow access and the PHP authority restricts premium writes. Migrate legacy hardware-bound activation through the issuer/operator process; new company licenses use UUIDs. A host administrator capable of replacing code/database/configuration is outside the software-only licensing trust boundary.

Disabling automation/integration capabilities pauses their queued effects too. Historical business events remain. Replaying previously paused outbound events requires operator review; expiry does not promise automatic catch-up or exactly-once external delivery.

## Updates and backup checkpoints

`RELEASE.json` contains explicit application/build/schema/runtime/compatibility metadata. This working candidate remains uncertified; production commit/build/date must be set by a controlled build and bound to the signed manifest. Never use arbitrary local Git state as the sole release identity.

Configure a separate trusted update public key using `AIMS_UPDATE_PUBLIC_KEY_FILE`. An update envelope uses the same standard signing format with `schema: 2`, `product: "AIMS"`, `kind: "update"`. Required fields are `version`, `build_id`, a 40-hex `commit`, `migration_level`, `minimum_php`, `minimum_version`, `released_at`, `compatibility.api: 1`, `tree_sha256` and bounded `artifacts` containing safe filenames and SHA-256 digests. No URL is accepted for execution/download.

The staged-tree digest is standard SHA-256 over lexically sorted `relative-path + NUL + content-sha256 + newline` rows. All immutable files are included, including additional executable files. Exclusions are only `artifacts/`, `UPDATE.signed.json`, `backend/.env` and `backend/storage`; the server runbook requires the latter two to point to the approved shared configuration/storage. No other symlink is accepted. Generate the digest from the final staged release, after controlled metadata/build generation, then sign it at the issuer.

Using the **current trusted release**, verify artifacts and staged code:

```text
php artisan aims:verify-release --file=<candidate>/UPDATE.signed.json --artifacts-root=<candidate>/artifacts --release-root=<candidate>
```

Modified signatures, wrong issuer, unsupported versions/runtime, changed archives and unlisted/changed staged code fail. This verifies bytes without executing them.

Create the existing encrypted installation archive during maintenance with writers stopped:

```text
php artisan aims:installation-backup create --key-file=<protected-backup-passphrase-file>
```

System Health → Updates & recovery can authenticate that actual archive using its filename/passphrase, recording a checkpoint with database, version, key compatibility and file hash. A filename or `.verified.json` sidecar alone is insufficient. Preflight requires an unchanged authenticated archive and recent snapshot/checkpoint (within 24 hours), compatible key/database/runtime, free-space reserve and operator-confirmed worker quiescence with no reserved jobs. Extra artifact-specific disk space is an operator check.

The existing server upgrade script now verifies from trusted current code, enters maintenance, stops workers, creates a verified backup, migrates additively, validates and activates reviewed code. Initial adoption from a release without DC8's verifier requires a separately reviewed bootstrap; the script stops rather than executing an unverified candidate. `aims-update-state.json` reports stage, migration start/completion and code activation, including failure. Failure keeps maintenance active. Do not assume reverse migrations are safe; restore into a separate clean installation when necessary.

Electron auto-download and auto-install-on-quit are disabled. Checking for updates directs the administrator to reviewed maintenance. No real unattended updater, artifact installation or automatic rollback was enabled. The commercial desktop workflow blocks high advisories in the full dependency tree, including Electron even though npm classifies it as development tooling.

## Health, support and release checks

Security and System Health are company-authorized administration surfaces. Missing mail/Reverb probes are explicitly unknown, not success. Ordinary staff cannot use deployment diagnostics. Support bundles are generated only on explicit authorized request with recent authentication. They include allowlisted technical JSON and are downloaded locally; nothing is sent to a vendor. Security audit export is bounded and company-scoped; detailed security/business evidence is separate from technical telemetry.

Nginx frontend CSP starts **report-only** so existing map/PDF/warehouse workflows can be reviewed on the target host before enforcement. The focused security browser journey passes with the same style of policy enforced. API CSP/no-store/nosniff and document sandbox responses are enforced independently. Configure exact HTTPS/CORS/Reverb origins and verify the complete relevant target-host workflows before switching the frontend header to enforcement.

Run `security:prune-challenges` through the existing scheduler hourly; it removes at most 10,000 challenges expired for a day per run. It does not delete security audit history. Existing DC7 telemetry pruning remains.

Release review commands:

```text
composer audit --locked
npm audit --audit-level=high                # frontend AND desktop; retain full output
python -m pip_audit --no-deps --disable-pip -r backend/ml/supply_optimizer_requirements.txt
node scripts/dependency-inventory.cjs output/dependency-inventory.json
```

The pinned Windows PHP download is 8.3.36-1, SHA-256 `1a097d52b7a68622cdc723073b61cc70caf79c941218ff65b2d8441dbc6b10d4`, from the [official PHP Windows release metadata](https://downloads.php.net/~windows/releases/releases.json). The downloader extracts to a version-specific cache so an active runtime's DLLs are not overwritten, verifies the archive and extracted runtime version, and the packager requires the declared PHP version. Preserve the original application key across runtime replacement.

The dependency inventory is not a vulnerability scanner. Python evidence covers the declared NumPy/SciPy pins, not every native OS library. Current commercial release remains blocked by the unresolved Electron/build advisories and target-host commissioning. No DC9 work is implied by this guide.
