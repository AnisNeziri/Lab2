param([int]$ParentId, [string]$Registry)
$ErrorActionPreference = 'Stop'
try { $taskParent = Get-Process -Id $ParentId; $taskParentStarted = $taskParent.StartTime } catch { $taskParentStarted = $null }
while ($null -ne $taskParentStarted) {
    Start-Sleep -Milliseconds 500
    try { $taskCurrent = Get-Process -Id $ParentId; if ($taskCurrent.StartTime -ne $taskParentStarted) { break } } catch { break }
}
try {
    $taskChildren = Get-Content -LiteralPath $Registry -Raw | ConvertFrom-Json
    foreach ($taskChild in $taskChildren) {
        try {
            $taskProcess = Get-Process -Id $taskChild.pid
            $taskStarted = [DateTimeOffset]::new($taskProcess.StartTime.ToUniversalTime()).ToUnixTimeMilliseconds()
            if ($taskStarted -ge $taskChild.startedAfter -and $taskStarted -le $taskChild.startedBefore) {
                & taskkill.exe /pid $taskChild.pid /t /f | Out-Null
            }
        } catch { }
    }
    Remove-Item -LiteralPath $Registry -Force
} catch { }
