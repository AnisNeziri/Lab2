# AIMS commercial licensing

The desktop application accepts only RSA-signed `.aims-license` files bound to
one Windows computer. The packaged application contains the public verification
key only. The private signing key remains under the AIMS owner's Windows profile
in `.aims-license-authority` and must never be distributed with the installer.

## Issue a licence

Ask the customer for the Computer ID displayed by the AIMS activation screen,
then run:

```powershell
node tools/license/generate-license.cjs --customer "Company Name" --machine "COMPUTER-ID" --output "Company-Name.aims-license" --expires never
```

Send only the generated `.aims-license` file to that customer. A copied
installation or licence will fail verification on a different computer.

Use a real expiry date for subscription licences. Use `--expires never` only
for a perpetual licence. Because validation is intentionally offline, an
already-issued perpetual licence cannot be remotely revoked. Keep a private
licence register containing the customer, Computer ID, licence ID, issue date,
expiry date and the filename you delivered. Never store the private key or its
passphrase in that register or send them to a customer.

If a customer replaces their computer, issue a new licence for the new Computer
ID. The previous file remains bound to the old computer and cannot activate the
replacement computer.

The customer imports the licence from the activation screen. A valid licence is
stored in `%APPDATA%\aims-desktop\license.aims-license`; the packaged installer
contains only the public verification key.

## Windows installer signing

For a publicly trusted Windows signature, set `CSC_LINK` to the purchased
Authenticode certificate (`.pfx`) and `CSC_KEY_PASSWORD` to its password, then
run:

```powershell
cd desktop
npm run dist:win:signed
```

The signing certificate is external security material and is deliberately not
stored in this repository.

The included GitHub Actions workflow can build and publish signed automatic
updates. Add these repository secrets before running **AIMS signed desktop
release**:

- `AIMS_WINDOWS_CERTIFICATE_BASE64`
- `AIMS_WINDOWS_CERTIFICATE_PASSWORD`

Every published installer and update is checked against its Windows signature
before Electron installs it.

Use Node.js 22.12 or newer to build the current Electron release. An unsigned
local installer is suitable for internal testing, but public commercial
distribution should use the signed release workflow so Windows can identify the
publisher and the automatic updater can consume trusted release artifacts.
