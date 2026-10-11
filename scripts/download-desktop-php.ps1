$ErrorActionPreference = 'Stop'
$taskWorkspace = Split-Path $PSScriptRoot -Parent
$release = Get-Content -LiteralPath (Join-Path $taskWorkspace 'RELEASE.json') -Raw | ConvertFrom-Json
if ($release.toolchain.php -ne '8.3.35') { throw 'Update the official runtime URL and checksum together with RELEASE.json.' }
$runtimePath = Join-Path $taskWorkspace 'desktop/runtime/php'
$archivePath = Join-Path $taskWorkspace 'tmp/php-8.3.35-nts-x64.zip'
New-Item -ItemType Directory -Path (Split-Path $archivePath -Parent) -Force | Out-Null
# SHA-256 published by php.net/downloads.php for this exact Windows NTS build.
Invoke-WebRequest -Uri 'https://downloads.php.net/~windows/releases/archives/php-8.3.35-nts-Win32-vs16-x64.zip' -OutFile $archivePath
if ((Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne '25a8e2ac9ff30f1d768d1447c09a600617fa6e6082729f6e95f008b59c91fe45') { throw 'Official PHP runtime checksum mismatch.' }
Expand-Archive -LiteralPath $archivePath -DestinationPath $runtimePath -Force
Write-Output 'Pinned official PHP 8.3.35 portable runtime verified.'
