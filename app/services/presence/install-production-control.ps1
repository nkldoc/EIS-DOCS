param([Parameter(Mandatory=$true)][string]$AppPool, [string]$NodePath = 'C:\Program Files\nodejs\node.exe')
$ErrorActionPreference = 'Stop'
$config = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'server.config.json') -Raw | ConvertFrom-Json
$directory = $env:EIS_DOCS_PRESENCE_DIR
if (-not $directory) { $directory = $config.directory }
if (-not $directory -or -not (Test-Path -LiteralPath $directory)) { throw 'Configure existing shared presence directory first' }
if (-not (Test-Path -LiteralPath $NodePath)) { throw 'Node executable missing' }
$controlDirectory = Join-Path $directory 'control'
New-Item -ItemType Directory -Path $controlDirectory -Force | Out-Null
& icacls.exe $controlDirectory /grant ('IIS AppPool\' + $AppPool + ':(OI)(CI)M')
if ($LASTEXITCODE -ne 0) { throw 'Cannot grant control directory access' }
$powershellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$principal = New-ScheduledTaskPrincipal -UserId SYSTEM -LogonType ServiceAccount -RunLevel Highest
$runner = Join-Path $PSScriptRoot 'run-production.ps1'
$arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $runner + '" -NodePath "' + $NodePath + '" -Directory "' + $directory + '"'
$action = New-ScheduledTaskAction -Execute $powershellPath -Argument $arguments -WorkingDirectory $PSScriptRoot
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable
$taskName = 'EIS-DOCS-Supplies-Presence'
if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $taskName
    $deadline = (Get-Date).AddSeconds(20)
    while ((Get-ScheduledTask -TaskName $taskName).State -eq 'Running') {
        if ((Get-Date) -gt $deadline) { throw 'Existing service did not stop' }
        Start-Sleep -Milliseconds 500
    }
}
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger (New-ScheduledTaskTrigger -AtStartup) -Settings $settings -Principal $principal -Force | Out-Null
$controlScript = Join-Path $PSScriptRoot 'production-control.ps1'
$controlArgs = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $controlScript + '" -Directory "' + $directory + '"'
$controlAction = New-ScheduledTaskAction -Execute $powershellPath -Argument $controlArgs -WorkingDirectory $PSScriptRoot
$controlTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$controlSettings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Seconds 50) -MultipleInstances IgnoreNew -StartWhenAvailable
Register-ScheduledTask -TaskName 'EIS-Presence-Control' -Action $controlAction -Trigger $controlTrigger -Settings $controlSettings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $taskName
Start-ScheduledTask -TaskName 'EIS-Presence-Control'
Write-Output 'Presence control installed. Verify user-online page and public WebSocket connection.'
