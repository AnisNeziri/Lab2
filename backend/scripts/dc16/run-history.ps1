# Test-only bounded orchestrator. Each PHP process still commits one day/checkpoint.
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
Set-Location $repo
$env:PHPRC = Join-Path $repo 'output/dc8-runtime-php.ini'
$env:PHP_INI_SCAN_DIR = ''
$env:OPENSSL_CONF = 'C:/xampp/apache/conf/openssl.cnf'
$php = Join-Path $repo 'desktop/resources/php/php.exe'
$evidence = 'C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence'
for ($batch = 0; $batch -lt 12; $batch++) {
    $prior = Get-Content (Join-Path $evidence 'history-checkpoint.json') -Raw | ConvertFrom-Json
    if ($prior.completed_day -ge 364) { break }
    & $php backend/scripts/dc16/history.php 31 >> output/dc16/history-batches.log 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Replay stopped; inspect private history-failure.json before resuming.' }
    $checkpoint = Get-Content (Join-Path $evidence 'history-checkpoint.json') -Raw | ConvertFrom-Json
    if ($checkpoint.completed_day -le $prior.completed_day) { throw 'Replay made no durable progress. No further batch was started.' }
    Write-Output "Durable checkpoint: day $($checkpoint.completed_day + 1), $($checkpoint.date)"
    & $php backend/scripts/dc16/verify.php >> output/dc16/integrity-batches.log 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Checkpoint verification failed to execute.' }
    if ($checkpoint.completed_day -ge 364) { break }
}
