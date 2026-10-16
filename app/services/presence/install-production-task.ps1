param([string]$NodePath = '')
$ErrorActionPreference = 'Stop'
if (-not $NodePath) {
    $nodeCommand = Get-Command node.exe -ErrorAction SilentlyContinue
    if ($nodeCommand) { $NodePath = $nodeCommand.Source }
    else { $NodePath = 'C:\Program Files\nodejs\node.exe' }
}
if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'server.config.json'))) { throw 'Configure server.config.json first' }
if (-not (Test-Path -LiteralPath $NodePath)) { throw 'Node executable not found. Run scripts/Install-ProductionMessengerRuntime.ps1 or specify -NodePath.' }
$NodePath = (Resolve-Path -LiteralPath $NodePath).Path
& $NodePath --version
if ($LASTEXITCODE -ne 0) { throw 'Node executable failed to start.' }
$action = New-ScheduledTaskAction -Execute $NodePath -Argument 'server.js' -WorkingDirectory $PSScriptRoot
$trigger = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName 'EIS-DOCS-Supplies-Presence' -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'EIS-DOCS and Supplies presence backend; localhost only.' -Force | Out-Null
Start-ScheduledTask -TaskName 'EIS-DOCS-Supplies-Presence'
Write-Output 'Production presence task installed and started. Check localhost health and public WebSocket upgrade.'
