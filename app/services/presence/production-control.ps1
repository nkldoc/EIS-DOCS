param([Parameter(Mandatory=$true)][string]$Directory)
$ErrorActionPreference = 'Stop'
$controlDirectory = Join-Path $Directory 'control'
$taskName = 'EIS-DOCS-Supplies-Presence'
$controlLog = Join-Path $controlDirectory 'control.log'
function Write-ControlLog([string]$Message) {
    if ((Test-Path -LiteralPath $controlLog) -and (Get-Item -LiteralPath $controlLog).Length -gt 1MB) {
        Move-Item -LiteralPath $controlLog -Destination ($controlLog + '.1') -Force
    }
    Add-Content -LiteralPath $controlLog -Value ((Get-Date -Format o) + ' ' + $Message) -Encoding UTF8
}
try {
    $requestFile = Join-Path $controlDirectory 'restart.request'
    if (Test-Path -LiteralPath $requestFile) {
        # Claim before acting: requests contain no executable commands or parameters.
        Remove-Item -LiteralPath $requestFile -Force
        Write-ControlLog 'Administrator restart requested'
        Stop-ScheduledTask -TaskName $taskName
        $deadline = (Get-Date).AddSeconds(20)
        while ((Get-ScheduledTask -TaskName $taskName).State -eq 'Running') {
            if ((Get-Date) -gt $deadline) { throw 'Presence task did not stop within 20 seconds' }
            Start-Sleep -Milliseconds 500
        }
        Start-ScheduledTask -TaskName $taskName
        Write-ControlLog 'Presence task start requested; verify browser connection and service log'
    }
    $task = Get-ScheduledTask -TaskName $taskName
    $info = Get-ScheduledTaskInfo -TaskName $taskName
    @{at=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds();state=[string]$task.State;result=$info.LastTaskResult} | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $controlDirectory 'worker.json') -Encoding UTF8
} catch {
    Write-ControlLog ('Control error: ' + $_.Exception.Message)
    exit 1
}
