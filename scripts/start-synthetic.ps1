param(
    [ValidateRange(1,2147483647)][int]$Seed = 20261006,
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$NodePath = 'C:\Users\anisi\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe',
    [string]$PythonPath = 'C:\Users\anisi\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root 'backend'
$db = Join-Path $backend "storage\app\synthetic\aims-pm3-$Seed.sqlite"
$reportPath = "$db.report.json"
if (!(Test-Path -LiteralPath $db) -or !(Test-Path -LiteralPath $reportPath)) { throw 'Generate the PM3 database first with aims:generate-demo-company --yes.' }
$report = Get-Content -LiteralPath $reportPath -Raw | ConvertFrom-Json
if ($report.configuration.seed -ne $Seed -or !$report.integrity.synthetic_tenant_isolated) { throw 'Refusing to start a database without a matching isolated synthetic report.' }
foreach ($port in @(8013,5174)) { if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) { throw "Port $port is already in use. Stop that specific process before starting the demo." } }
if (!(Test-Path -LiteralPath $PhpPath) -or !(Test-Path -LiteralPath $NodePath)) { throw 'Pass the installed PHP and Node executable paths.' }
& $PhpPath (Join-Path $backend 'artisan') aims:check-demo-company "--seed=$Seed"
if ($LASTEXITCODE -ne 0) { throw 'Refusing to start an incomplete or unrecognized synthetic database.' }
$env:APP_ENV = 'synthetic'
$env:APP_CONFIG_CACHE = Join-Path $backend 'storage\app\synthetic\uncached-config.php'
$env:APP_DEBUG = 'false'
$env:APP_OPERATION_MODE = 'online'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $db
$env:DB_URL = ''
$env:CACHE_STORE = 'file'
$env:CACHE_PREFIX = "aims_synthetic_$Seed"
$env:AIMS_CACHE_PATH = Join-Path $backend "storage\app\synthetic\cache-$Seed"
$env:REDIS_ENABLED = 'false'
if (Test-Path -LiteralPath $PythonPath) { $env:AIMS_ML_PYTHON = $PythonPath }
$env:SESSION_DRIVER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:BROADCAST_CONNECTION = 'log'
$env:TRACKING_EXTERNAL_ENABLED = 'false'
$env:AIMS_DOCUMENT_ROOT = Join-Path $backend "storage\app\synthetic\documents-$Seed"
$env:ASSISTANT_PROVIDER = 'deterministic'
$env:VITE_API_PROXY_TARGET = 'http://127.0.0.1:8013'
$env:VITE_API_BASE_URL = '/api'
$env:VITE_AIMS_INSTALLATION = 'synthetic'
$log = Join-Path $backend "storage\app\synthetic\server-$Seed"
$api = Start-Process -FilePath $PhpPath -ArgumentList @((Join-Path $backend 'artisan'),'serve','--host=127.0.0.1','--port=8013') -WorkingDirectory $backend -WindowStyle Hidden -RedirectStandardOutput "$log-api.log" -RedirectStandardError "$log-api.err.log" -PassThru
$web = Start-Process -FilePath $NodePath -ArgumentList @((Join-Path $root 'frontend\node_modules\vite\bin\vite.js'),'--host','127.0.0.1','--port','5174','--strictPort') -WorkingDirectory (Join-Path $root 'frontend') -WindowStyle Hidden -RedirectStandardOutput "$log-web.log" -RedirectStandardError "$log-web.err.log" -PassThru
Write-Output "PM3 synthetic company: http://127.0.0.1:5174/login"
Write-Output "Owner: $($report.configuration.email) / $($report.configuration.password)"
Write-Output "Only synthetic servers started (API PID $($api.Id), frontend PID $($web.Id)). No Docker, Redis, SMTP or external AIS worker is required."
