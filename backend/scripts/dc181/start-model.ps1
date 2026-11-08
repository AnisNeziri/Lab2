$ErrorActionPreference = 'Stop'
$modelRoot = 'C:/Users/anisi/.codex/recovery/dc18-1-20261104'
if (Get-NetTCPConnection -LocalPort 11438 -State Listen -ErrorAction SilentlyContinue) {
    Write-Output 'An inference server is already listening on loopback port 11438. No process changed.'
    return
}
$env:OLLAMA_HOST = '127.0.0.1:11438'
$env:OLLAMA_MODELS = "$modelRoot/models"
$env:OLLAMA_NUM_PARALLEL = '1'
$env:OLLAMA_MAX_LOADED_MODELS = '1'
$env:OLLAMA_NO_CLOUD = '1'
& "$modelRoot/ollama/ollama.exe" serve
