param(
    [string]$MariaDbRoot = 'C:\xampp\mysql',
    [int]$Port = 3306
)

$ErrorActionPreference = 'Stop'
$server = Join-Path $MariaDbRoot 'bin\mysqld.exe'
$config = Join-Path $MariaDbRoot 'bin\my.ini'
$errorLog = Join-Path $MariaDbRoot 'data\mysql_error.log'

function Test-DatabasePort {
    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $connection = $client.ConnectAsync('127.0.0.1', $Port)
        return $connection.Wait(400) -and $client.Connected
    }
    catch {
        return $false
    }
    finally {
        $client.Dispose()
    }
}

if (Test-DatabasePort) {
    Write-Host "MariaDB is already online on port $Port."
    exit 0
}

if (-not (Test-Path -LiteralPath $server) -or -not (Test-Path -LiteralPath $config)) {
    throw "MariaDB was not found under $MariaDbRoot."
}

# --console keeps MariaDB attached to this hidden child process. Without it,
# some XAMPP builds initialize successfully and then exit immediately when
# they were not launched by the Windows service manager.
$process = Start-Process -FilePath $server `
    -ArgumentList "--defaults-file=$config", '--console', '--standalone', '--bind-address=127.0.0.1' `
    -WorkingDirectory $MariaDbRoot `
    -WindowStyle Hidden `
    -PassThru

$deadline = (Get-Date).AddSeconds(20)
do {
    Start-Sleep -Milliseconds 300
    if ($process.HasExited) {
        $details = if (Test-Path -LiteralPath $errorLog) {
            (Get-Content -LiteralPath $errorLog -Tail 25) -join [Environment]::NewLine
        } else {
            'No MariaDB error log was created.'
        }
        throw "MariaDB stopped during startup.$([Environment]::NewLine)$details"
    }
} until ((Test-DatabasePort) -or (Get-Date) -ge $deadline)

if (-not (Test-DatabasePort)) {
    Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
    throw "MariaDB did not open port $Port within 20 seconds."
}

Write-Host "MariaDB is online on 127.0.0.1:$Port (process $($process.Id))."
