#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param([string]$TaskName='EIS-DOCS-Supplies-Presence')
$ErrorActionPreference='Stop'
# Resolve the directory used by the running task, not a second checkout.
$task=Get-ScheduledTask -TaskName $TaskName -TaskPath '\' -ErrorAction Stop
if (@($task.Actions).Count -ne 1) { throw 'Expected one Node service action.' }
$action=$task.Actions[0]
if ($action.Arguments.Trim() -notin @('server.js','"server.js"')) { throw 'Unexpected task arguments; inspect the actual service launcher.' }
$service=(Resolve-Path -LiteralPath ([Environment]::ExpandEnvironmentVariables($action.WorkingDirectory))).Path
$node=[Environment]::ExpandEnvironmentVariables($action.Execute)
& $node --check (Join-Path $service 'server.js')
if ($LASTEXITCODE -ne 0) { throw 'Server JavaScript validation failed. No changes made.' }
foreach ($name in @('EIS_PRESENCE_SERVER_CONFIG','EIS_DOCS_PRESENCE_DIR','EIS_PRESENCE_TRANSPORT','EIS_DOCS_PRESENCE_PORT')) {
    foreach ($scope in @('Process','Machine')) {
        if ([Environment]::GetEnvironmentVariable($name,$scope)) { throw "Environment override $name exists at $scope scope; it takes precedence over server.config.json. No changes made." }
    }
}
$file=Join-Path $service 'server.config.json'
if (-not (Test-Path -LiteralPath $file)) { throw 'server.config.json is missing. Configure the existing production storage directory before restarting; do not generate new secrets.' }
$config=Get-Content -LiteralPath $file -Raw | ConvertFrom-Json
if (-not $config.directory) { throw 'Node config directory is missing. Identify the existing shared storage before changing transport.' }
$storage=(Resolve-Path -LiteralPath $config.directory).Path
foreach ($name in @('eis-docs.secret','supplies.secret')) {
    if (-not (Test-Path -LiteralPath (Join-Path $storage $name) -PathType Leaf)) { throw "Missing existing $name in shared storage. No changes made." }
}
Write-Host "Task directory: $service"
Write-Host "Current transport: $($config.transport); configured port: $($config.port)"
# Preserve storage, origins, TLS fields and all unrelated configuration.
$config | Add-Member -NotePropertyName transport -NotePropertyValue 'http' -Force
$config | Add-Member -NotePropertyName port -NotePropertyValue 4003 -Force
$backupRoot=Join-Path $env:ProgramData 'EIS-Messenger\config-backups'
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
$backup=Join-Path $backupRoot ('node-' + [guid]::NewGuid().ToString('N') + '.json.bak')
Copy-Item -LiteralPath $file -Destination $backup
[IO.File]::WriteAllText($file,($config | ConvertTo-Json -Depth 30),(New-Object Text.UTF8Encoding($false)))
Write-Host "Configuration backup: $backup"
Stop-ScheduledTask -TaskName $TaskName -TaskPath '\'
$deadline=[DateTime]::UtcNow.AddSeconds(30)
while ((Get-ScheduledTask -TaskName $TaskName -TaskPath '\').State -eq 'Running') {
    if ([DateTime]::UtcNow -ge $deadline) { throw 'Task did not stop. Config updated; inspect the service process before restarting.' }
    Start-Sleep -Milliseconds 300
}
Start-ScheduledTask -TaskName $TaskName -TaskPath '\'
$deadline=[DateTime]::UtcNow.AddSeconds(20)
do {
    try {
        $health=Invoke-RestMethod 'http://127.0.0.1:4003/health' -TimeoutSec 2
        if ($health.status -eq 'ok' -and $health.service -eq 'docs-supplies-presence') {
            Write-Host 'PASS: production backend is available over HTTP on 127.0.0.1:4003.'
            return
        }
    } catch { }
    Start-Sleep -Milliseconds 500
} while ([DateTime]::UtcNow -lt $deadline)
throw 'HTTP health is still unavailable. Inspect task LastTaskResult and the process owning port 4003; do not run the IIS repair yet.'
