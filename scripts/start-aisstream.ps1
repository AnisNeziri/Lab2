$ErrorActionPreference = 'Stop'

$backendDir = (Resolve-Path (Join-Path $PSScriptRoot '..\backend')).Path
$php = (Get-Command php -ErrorAction Stop).Source
$alreadyRunning = Get-CimInstance Win32_Process | Where-Object {
    $_.Name -match '^php(\.exe)?$' -and $_.CommandLine -match 'artisan\s+tracking:aisstream'
}

if ($alreadyRunning) {
    Write-Host 'AIMS AISStream worker is already running.'
    exit 0
}

$logDir = Join-Path $backendDir 'storage\logs'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null

Start-Process -FilePath $php `
    -ArgumentList @('artisan', 'tracking:aisstream') `
    -WorkingDirectory $backendDir `
    -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logDir 'aisstream-worker.log') `
    -RedirectStandardError (Join-Path $logDir 'aisstream-worker-error.log')

Write-Host 'AIMS AISStream worker started in the background.'
