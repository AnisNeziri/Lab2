# AIMS 1.1.1-rc.1 — production readiness candidate

This candidate builds on AIMS V1–V12 and PM1–PM5 with startup diagnostics, supervised offline workers, safer recovery credentials, stronger login-token revocation and verified encrypted installation recovery. System Integrity now distinguishes verified backups, recovery drills and failed maintenance; Settings/About and support diagnostics identify the authoritative release.

Upgrade from the selected `5db86866` baseline requires a verified full backup and a maintenance window. Forward migrations preserve data, add token revocation and correct MariaDB timestamp behavior. Existing sessions require login again. Keep the original APP_KEY and separately escrow backup keys. Do not use destructive migrations or reseed a live company.

Windows bundles local PHP/Python and uses SQLite; the server blueprint uses Linux/Nginx/PHP-FPM/MariaDB/Redis with three database workers and a scheduler. Optional mail and external tracking providers require explicit configuration. Encrypted backups include the database and actual document files and must be restored on a separate installation before release approval.

The release is currently uncertified. Supplier ML promotion, limited predictive history, carrier/AIS providers, tax/fiscal integrations, a signed fresh-VM desktop installation and actual server reboot require explicit validation before customer rollout. Review `PR1_RELEASE_CHECKLIST.md`, `PR1_OPERATIONS.md` and the final certification report. Back up before every upgrade and retain an off-server known-good copy with tested secret escrow.
