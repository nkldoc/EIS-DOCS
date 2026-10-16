#Requires -Version 5.1
[CmdletBinding()]
param([string]$TaskName='EIS-DOCS-Supplies-Presence')
$ErrorActionPreference='Stop'
$task=Get-ScheduledTask -TaskName $TaskName -TaskPath '\'
$info=Get-ScheduledTaskInfo -TaskName $TaskName -TaskPath '\'
Write-Host "Task state: $($task.State)"
Write-Host "Task identity: $($task.Principal.UserId)"
Write-Host "Task last result: $($info.LastTaskResult) (0x$('{0:X8}' -f [uint32]$info.LastTaskResult))"
Write-Host "Last run: $($info.LastRunTime)"
$listeners=@(Get-NetTCPConnection -State Listen -LocalPort 4003 -ErrorAction SilentlyContinue)
if ($listeners.Count) {
    foreach ($listener in $listeners) {
        $owner=Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)"
        Write-Host "Port 4003: $($listener.LocalAddress), PID=$($listener.OwningProcess), executable=$($owner.ExecutablePath)"
    }
} else { Write-Host 'Port 4003: no listening process.' }
$action=@($task.Actions)[0]
Write-Host "Task executable: $($action.Execute)"
Write-Host "Task arguments: $($action.Arguments)"
Write-Host "Task directory: $($action.WorkingDirectory)"
$service=(Resolve-Path -LiteralPath ([Environment]::ExpandEnvironmentVariables($action.WorkingDirectory))).Path
$node=[Environment]::ExpandEnvironmentVariables($action.Execute)
Push-Location $service
try {
    & $node --version
    if ($LASTEXITCODE -ne 0) { throw 'Task executable cannot run Node.' }
    foreach ($file in @('server.js','store.js','chat.js','report.js')) {
        if (-not (Test-Path -LiteralPath $file)) { Write-Host "FAIL: missing runtime file $file"; continue }
        & $node --check $file
        Write-Host "Syntax $file : exit=$LASTEXITCODE"
    }
    # Import dependencies only. Do not start a second service or alter storage.
    & $node -e "for (const m of ['ws','./report','./store','./chat']) { try { require(m); console.log('PASS module '+m); } catch(e) { console.log('FAIL module '+m+' code='+(e.code||e.name)); process.exitCode=1; } }"
    $configFile=Join-Path $service 'server.config.json'
    if (Test-Path -LiteralPath $configFile) {
        $config=Get-Content -LiteralPath $configFile -Raw | ConvertFrom-Json
        Write-Host "Config transport=$($config.transport), port=$($config.port), directory=$($config.directory)"
        if ($config.directory) {
            foreach ($name in @('eis-docs.secret','supplies.secret','history.json','chat.json')) {
                Write-Host "Storage $name exists: $(Test-Path -LiteralPath (Join-Path $config.directory $name))"
            }
            # File contents and secrets are deliberately not printed.
            & $node -e "const fs=require('fs'),p=require('path'),c=JSON.parse(fs.readFileSync('server.config.json','utf8'));for(const n of ['history.json','chat.json']){const f=p.join(c.directory,n);if(!fs.existsSync(f))continue;try{JSON.parse(fs.readFileSync(f,'utf8'));console.log('PASS JSON '+n)}catch(e){console.log('FAIL JSON '+n+' code='+(e.code||e.name));process.exitCode=1}}"
        }
    } else { Write-Host 'FAIL: server.config.json missing.' }
    foreach ($name in @('EIS_PRESENCE_SERVER_CONFIG','EIS_DOCS_PRESENCE_DIR','EIS_PRESENCE_TRANSPORT','EIS_DOCS_PRESENCE_PORT')) {
        foreach ($scope in @('Process','Machine')) {
            if ([Environment]::GetEnvironmentVariable($name,$scope)) { Write-Host "Override set: $name ($scope)" }
        }
    }
} finally { Pop-Location }
try {
    $health=Invoke-RestMethod 'http://127.0.0.1:4003/health' -TimeoutSec 3
    $health | Select-Object status,service,protocolVersion,capabilities | Format-List
} catch { Write-Host "HTTP health failure: $($_.Exception.Message)" }
Write-Host 'Checks above run as the current PowerShell user. SYSTEM task permissions can differ. No config, task or chat/history data was changed.'
