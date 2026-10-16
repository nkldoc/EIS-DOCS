$ErrorActionPreference = 'Stop'
$mutex = New-Object System.Threading.Mutex($false, 'Local\EISDocsSuppliesPresenceSupervisor')
if (-not $mutex.WaitOne(0)) { exit }
try {
    while ($true) {
        $listener = Get-NetTCPConnection -LocalPort 4003 -State Listen -ErrorAction SilentlyContinue
        if (-not $listener) {
            try {
                $process = Start-Process -FilePath 'C:\Program Files\nodejs\node.exe' -ArgumentList 'server.js' -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput 'D:/EIS-DOCS/storage/presence/service.log' -RedirectStandardError 'D:/EIS-DOCS/storage/presence/service-error.log'
                $process.WaitForExit()
            } catch {
                Add-Content -LiteralPath 'D:/EIS-DOCS/storage/presence/supervisor-error.log' -Value "$(Get-Date -Format o) $_"
            }
        }
        Start-Sleep -Seconds 5
    }
} finally {
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}
