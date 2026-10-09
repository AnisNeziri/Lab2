param([string]$PhpPath='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
$backend=Join-Path (Split-Path -Parent $PSScriptRoot) 'backend'
if(!(Test-Path -LiteralPath $PhpPath)){throw 'PHP executable not found.'}
$existing=Get-CimInstance Win32_Process -Filter "name = 'php.exe'" | Where-Object { $_.CommandLine -match 'artisan\s+simulation:work' }
if(!$existing){Start-Process -FilePath $PhpPath -ArgumentList @('artisan','simulation:work') -WorkingDirectory $backend -WindowStyle Hidden}
Write-Host 'AIMS isolated simulation queue is running in the background.'
