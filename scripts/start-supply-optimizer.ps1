param([string]$PhpPath='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$backend=Join-Path $root 'backend'
if(!(Test-Path -LiteralPath $PhpPath)){throw 'PHP executable not found.'}
Start-Process -FilePath $PhpPath -ArgumentList @('artisan','supply-optimizer:work') -WorkingDirectory $backend -WindowStyle Hidden
Start-Process -FilePath $PhpPath -ArgumentList @('artisan','schedule:work') -WorkingDirectory $backend -WindowStyle Hidden
Write-Host 'AIMS optimizer queue and scheduled plan reconciliation are running in the background.'
