# Release and recovery controls

## Desktop upgrades

The desktop launcher makes a consistent SQLite snapshot and runs `PRAGMA quick_check`
before migration. If this fails, startup stops without upgrading the database. The
last five successful startup snapshots are retained under the Windows user's AIMS
application-data `backups` directory. These are local recovery points, not off-device
disaster recovery and not a substitute for regular portable exports.

Upgrade seeding preserves custom role names, grants and revoked permissions. Newly
introduced permissions receive the normal role defaults. Failed migrations stop setup.
Recreated default roles also receive their module permissions without regranting revoked
permissions on existing roles. The setup command rejects online mode, non-SQLite
connections and database URL overrides before running any migration or seeder.

## Recovery account

New desktop installations receive a cryptographically random, installation-specific
temporary password for `aimsadmin@company.com`. The owner sees it in a native startup
dialog and must save it in a password manager. First login requires a password change.
The pending credential is protected by Windows secure storage until acknowledged;
it is never written to logs or passed in command-line arguments. A crash before
acknowledgement redisplays the same credential at the next startup.

The old shipped shared password hash is rotated by this process. An independently
changed owner password is preserved. Web users are not modified. Recovery provisioning
is restricted to offline mode. Do not distribute an existing client's application-data
folder or its recovery password to another client.

## Portable backup coverage

Reports supports encrypted full exports and a separate Analytics selection, including
snapshots, issues, frozen datasets and predictions. Referenced master records are
included, and live entity IDs are remapped on import. Importing Analytics alone does
not overwrite existing product/customer master records or warehouse quantities.

Frozen dataset payloads and fact payloads retain their original identifiers as historical
evidence; relational references point at restored records. A dataset version remains
unique inside its company. Conflicting immutable observations/datasets cause an explicit
restore rejection, never silent replacement. Deleted source entities are archived rather
than linked to an unrelated live ID. Old issues are restored as resolved history with
their unsafe links removed; the next capture re-evaluates current issues.

Complete operational backups from just before Analytics was introduced still support
confirmed full replacement. They clear existing derived Analytics history and report
that it was absent from the archive, rather than leaving links to replaced records.

Keep encrypted exports off the computer and retain the passphrase separately. Test a
restore into a clean installation before depending on it. Windows protected storage
protects launcher secrets, **not the live SQLite database or local SQLite snapshots**.
Use BitLocker/device encryption, a protected Windows account and restricted backup
access for data at rest. Full database encryption is not claimed by this release.

## Background health

System Integrity reports stale analytics observations after 36 hours, the last completed
observation, the last successful scheduled capture and a safe failure code. Failures
preserve the prior success timestamp. Status is scoped to the current company, and
maintenance execution metadata is not imported as a fictitious successful local run.

## Signed releases

The signed desktop GitHub workflow now depends on the complete CI workflow, checks
self-contained desktop startup, builds without publishing, verifies the installer with
Windows Authenticode and only then publishes the installer, blockmap and update manifest.
The existing owner-managed certificate secrets remain required. Running checks locally
does not sign an installer, and old unsigned installers are not certified by these changes.
Use a new package version for each commercial release; do not replace a published version.
The old `npm run release:win` direct-publish shortcut is disabled and directs the owner
to this verified workflow. Local non-publishing builds remain available.
