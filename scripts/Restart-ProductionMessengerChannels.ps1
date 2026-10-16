#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param([string]$TaskName = 'EIS-DOCS-Supplies-Presence')
$ErrorActionPreference = 'Stop'
$task = Get-ScheduledTask -TaskName $TaskName -TaskPath '\' -ErrorAction Stop
if (@($task.Actions).Count -ne 1) { throw 'Expected one Node action. Inspect the actual service task before restarting.' }
$action = $task.Actions[0]
$serviceDirectory = [Environment]::ExpandEnvironmentVariables($action.WorkingDirectory)
if (-not $serviceDirectory) { throw 'Task working directory is missing.' }
$serviceDirectory = (Resolve-Path -LiteralPath $serviceDirectory).Path
$nodePath = [Environment]::ExpandEnvironmentVariables($action.Execute)
if (-not (Test-Path -LiteralPath $nodePath)) { throw 'Task Node executable is unavailable.' }
if ($action.Arguments.Trim() -notin @('server.js','"server.js"')) { throw 'Unexpected task arguments. Restart its actual service manually.' }
foreach ($name in @('server.js','chat.js')) {
    $file = Join-Path $serviceDirectory $name
    if (-not (Test-Path -LiteralPath $file)) { throw "Missing $file" }
    & $nodePath --check $file
    if ($LASTEXITCODE -ne 0) { throw "Invalid JavaScript in $file; service not stopped." }
}
$serverSource = Get-Content -LiteralPath (Join-Path $serviceDirectory 'server.js') -Raw
$chatSource = Get-Content -LiteralPath (Join-Path $serviceDirectory 'chat.js') -Raw
if ($serverSource -notmatch 'protocolVersion:\s*2' -or $serverSource -notmatch 'capabilities' -or $chatSource -notmatch 'storedRoom\(room\)' -or $chatSource -notmatch 'input\.generalTeam') {
    throw "Deploy BOTH updated server.js and chat.js to $serviceDirectory first; current files do not support the new channels. Service not stopped."
}
Write-Host "Validated channel release in actual task directory: $serviceDirectory"
Stop-ScheduledTask -TaskName $TaskName -TaskPath '\' -ErrorAction Stop
$deadline = [DateTime]::UtcNow.AddSeconds(30)
while ((Get-ScheduledTask -TaskName $TaskName -TaskPath '\').State -eq 'Running') {
    if ([DateTime]::UtcNow -ge $deadline) { throw 'Task did not stop within 30 seconds.' }
    Start-Sleep -Milliseconds 300
}
Start-ScheduledTask -TaskName $TaskName -TaskPath '\' -ErrorAction Stop
$deadline = [DateTime]::UtcNow.AddSeconds(20)
do {
    try {
        $health = Invoke-RestMethod 'http://127.0.0.1:4003/health' -TimeoutSec 2
        if ($health.status -eq 'ok' -and $health.service -eq 'docs-supplies-presence' -and $health.protocolVersion -eq 2 -and $health.capabilities.conversations -eq $true) {
            Write-Host 'PASS: running Messenger backend supports general conversations and tickets. Refresh Supplies and EIS-DOCS.'
            return
        }
    } catch { }
    Start-Sleep -Milliseconds 500
} while ([DateTime]::UtcNow -lt $deadline)
throw 'Restart requested but the running backend did not advertise channel support. Inspect task LastTaskResult, its configuration and the process owning port 4003.'
