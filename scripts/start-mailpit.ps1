$ErrorActionPreference = 'Stop'

$mailpitDir = Join-Path $PSScriptRoot '..\tools\mailpit'
$mailpitExe = Join-Path $mailpitDir 'mailpit.exe'

if (-not (Test-Path $mailpitExe)) {
    Write-Host 'Mailpit not found. Downloading...'
    New-Item -ItemType Directory -Force -Path $mailpitDir | Out-Null
    $zip = Join-Path $mailpitDir 'mailpit.zip'
    $url = 'https://github.com/axllent/mailpit/releases/download/v1.22.3/mailpit-windows-amd64.zip'
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
    Expand-Archive -Path $zip -DestinationPath $mailpitDir -Force
}

Write-Host 'Starting Mailpit...'
Write-Host '  SMTP: 127.0.0.1:1025'
Write-Host '  Web UI: http://127.0.0.1:8025'
& $mailpitExe --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025
