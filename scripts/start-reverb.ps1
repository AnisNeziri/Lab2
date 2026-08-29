$ErrorActionPreference = 'Stop'

$backendDir = Join-Path $PSScriptRoot '..\backend'

Write-Host 'Starting Laravel Reverb websocket server...'
Write-Host '  WebSocket: ws://127.0.0.1:8080'
Write-Host 'Set BROADCAST_CONNECTION=reverb in backend/.env for live updates.'

Push-Location $backendDir
try {
    php artisan reverb:start --host=127.0.0.1 --port=8080
} finally {
    Pop-Location
}
