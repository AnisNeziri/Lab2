$ErrorActionPreference = 'Stop'
$taskWorkspace = Split-Path $PSScriptRoot -Parent
$release = Get-Content -LiteralPath (Join-Path $taskWorkspace 'RELEASE.json') -Raw | ConvertFrom-Json
if ($release.toolchain.php -ne '8.3.36') { throw 'Update the official runtime URL and checksum together with RELEASE.json.' }
$runtimePath = Join-Path $taskWorkspace 'desktop/runtime/php-8.3.36-1'
$archivePath = Join-Path $taskWorkspace 'tmp/php-8.3.36-nts-x64.zip'
New-Item -ItemType Directory -Path (Split-Path $archivePath -Parent) -Force | Out-Null
# SHA-256 published by php.net/downloads.php for this exact Windows NTS build.
if (-not (Test-Path -LiteralPath $archivePath)) {
    try { Invoke-WebRequest -Uri 'https://downloads.php.net/~windows/releases/php-8.3.36-1-nts-Win32-vs16-x64.zip' -OutFile $archivePath }
    catch { Invoke-WebRequest -Uri 'https://downloads.php.net/~windows/releases/archives/php-8.3.36-1-nts-Win32-vs16-x64.zip' -OutFile $archivePath }
}
if ((Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne '1a097d52b7a68622cdc723073b61cc70caf79c941218ff65b2d8441dbc6b10d4') { throw 'Official PHP runtime checksum mismatch.' }
Expand-Archive -LiteralPath $archivePath -DestinationPath $runtimePath -Force -ErrorAction Stop
$runtimeVersion = & (Join-Path $runtimePath 'php.exe') -n -r 'echo PHP_VERSION;'
if ($LASTEXITCODE -ne 0 -or $runtimeVersion -ne $release.toolchain.php) { throw 'Extracted runtime version does not match the release.' }
Write-Output 'Pinned official PHP 8.3.36 portable runtime verified.'
