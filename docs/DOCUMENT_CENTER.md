# AIMS Central Document Center

## Architecture and deployment

`documents` stores identity, tenant, classification, lifecycle and retention metadata.
`document_versions` stores immutable file metadata and SHA-256 hashes. Bytes are stored
outside the public web root under `backend/storage/app/documents-private`, using generated
company/shard/UUID keys. The local provider implements `DocumentStorageProvider`; business
services do not depend on public file URLs. `DocumentProcessor` is an extension contract,
not an OCR/AI implementation.

For an existing web installation, use `php artisan migrate --force`, then
`php artisan documents:setup`. The latter adds the default document permissions without
resetting existing role permissions. Users must sign out/in to refresh cached frontend
permissions. Fresh installs use the existing role seeder. No company records are reset.

Web-server request limits and PHP `upload_max_filesize` / `post_max_size` must accommodate
the configured file limit plus multipart overhead; company settings cannot override the
hosting server's limits. The bundled desktop runtime already provides sufficient limits.

The desktop build uses the same APIs, SQLite and private local storage. Its normal startup
initializes migrations automatically. User storage is not included in distributable assets
and is not replaced when application code is refreshed. Document access needs no internet.

## Use

Open **Documents**, or **Related documents** from a business record. Contextual uploads
carry the selected entity automatically. Upload a file, choose its type and optionally
enter title/expiry. Edit metadata later for reference, issuer, document date, tags,
confidentiality and retention. Company administrators can extend types and configure
allowed extensions, maximum size (up to 50 MB) and expiry notice days.

One document can link to multiple tenant-owned entities. A matching checksum produces a
duplicate warning; use the existing identity or deliberately create a separate identity.
Within a company, verified identical bytes may share physical storage. Identities are
never silently merged. New versions require a change note and retain historical files.

Images/PDFs can be previewed; other accepted formats download. PDF previews use the
lazy-loaded local Mozilla PDF.js compatibility build and local worker, with page navigation,
page count, bounded canvas size and no document scripting. Lists fetch metadata only,
are paginated, and do not load original image bytes. Search covers metadata and permitted
business references. Ctrl+K includes Document Center/upload/expiry/review commands.

## Access and review

All APIs enforce tenant and role permissions. Downloads additionally require
`documents.download`; confidential/restricted documents require elevated document access,
not just access to their supplier or PO. Restricted access also requires document management.
Downloads verify the physical checksum before sending bytes. Generated storage keys are
not exposed in ordinary document responses. Preview/download and restricted detail access
are recorded in the existing event history.

Reviews use the existing Approval Engine, including its self-approval and assigned-approver
restrictions. Optional `document_review` rules can be managed through the existing approval
rule API. A new version does not inherit the preceding version's approval. Pending review
and expiry notifications link directly to the document and respect its classification.

Archiving preserves versions, bytes and links. No permanent-deletion endpoint or automatic
retention deletion is provided. Retention dates flag review eligibility. Legal hold changes
require a reason and prevent unlinking and destructive backup replacement of held evidence.

## Existing attachments and integrations

New quality, shipment and expense evidence uses the central private store. Existing APIs
retain metadata pointers and delegate downloads; old blob/base64 files remain readable.
Document Settings offers an explicit **Verify & import** compatibility adapter for existing
quality, shipment and expense files. Imports verify checksums, preserve original source
bytes and relationships, and are idempotent. Production legacy files are NOT automatically
or destructively migrated. Product images and company branding remain presentation assets.

Related-document widgets cover products, customers, suppliers, requests/RFQs, POs, goods
receipts, inspections, claims, shipments/containers, sales orders, deliveries, returns,
invoices/payments, expenses and journals. Required-type rules return complete, incomplete,
expired or review-required states; they do not block unrelated operational completion.

## Maintenance and recovery

- Web: run the existing Laravel scheduler (`php artisan schedule:work` or scheduled
  `schedule:run`). Document expiry processing is scheduled daily at 07:00.
- Desktop: hidden local maintenance runs at application startup and hourly while open.
- Manual expiry processing: `php artisan documents:expiry-alerts`.
- Manual company verification: `php artisan documents:verify COMPANY_ID`.
- Document detail has **Verify file integrity**. Missing, changed or unavailable files
  are flagged without replacing evidence. System Integrity shows saved file-verification
  failures; manual company verification also checks current versions and entity links.

Encrypted portable backups include document metadata, version history, related approval
and event history, links and verified bytes. Restore remaps company IDs and generated keys,
preserves checksums and links, and rejects conflicting immutable versions or missing bytes.
Both merge and full replace workflows have automated coverage, including expense pointers
and restoring documents into another company.

### Deliberate limits

- Portable document archives have a **32 MiB total unique file-byte limit** to bound the
  existing in-memory archive format. Oversized exports fail explicitly, never silently
  omit evidence. Larger installations need an encrypted filesystem-plus-database backup
  until a streaming archive format is implemented. This is not unlimited backup capacity.
- The private store is access-controlled, not encrypted at rest by this feature. Protect
  the computer with disk encryption, OS account permissions and verified external backups.
  A local machine administrator can access local files.
- MIME/extension validation is not antivirus scanning. There is no malware scanner, OCR,
  Office preview, PDF editor, cloud sync, AI or e-signature feature.
- Restores clean up their own unused staged files on success or failure. Referenced
  evidence is protected; existing version keys are preserved during merges. A process/OS
  crash can still interrupt cleanup; there is no broad filesystem deletion job.
  Missing current versions and broken entity links appear in System Integrity without
  scanning file bytes. Full checksum verification remains explicit/scheduled.
- A document-only backup carries linked-record dependencies, not a complete operational
  history. Use the full company backup when recovering an entire installation. On merge,
  existing linked records are reused without overwriting newer PO/supplier values.
- Legacy imports are deliberate per-file operations. No claim is made that historical
  production attachments have already been imported.
- Installer signing/public release publication are separate existing release operations;
  running desktop startup certification does not publish a new installer.
- The repository's existing dependency audit still reports advisories in older development
  tooling and a transitive 3D dependency. PDF.js itself was not reported vulnerable by this
  audit. Dependency hardening should precede commercial release; it was not folded into
  this document-focused change.

## Verification commands

Backend: `php artisan test --compact`; targeted: `php artisan test --filter=DocumentCenterTest`.
Use a dedicated disposable database for MySQL tests; never the company database.
Frontend: `npm test`, `npm run test:e2e`, `npm run build`.
Desktop: `npm run test:startup`.
Use Node.js 22.13+ (or Node.js 24) for frontend development/dependency installation.

The browser suite includes upload/download, PO linking and contextual access, immutable
versions, duplicate handling, restricted access, review, expiry, archive, image preview,
English/Albanian controls and encrypted restore. Document viewport checks cover 390, 768,
1024, 1366 and 1920 pixels in light/dark themes.
