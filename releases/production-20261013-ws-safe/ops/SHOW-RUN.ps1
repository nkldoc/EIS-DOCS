#Requires -Version 5.1
[CmdletBinding()]
param(
    [ValidateSet('Show','Check','Restart','Repair')][string]$Mode = 'Show',
    [string]$ProjectPath = 'D:\EIS-DOCS',
    [string]$ApplicationName = 'doc-eis.nmu.ac.th/eis-docs',
    [string]$TaskName = 'EIS-DOCS-Supplies-Presence',
    [switch]$InstallDependencies,
    [switch]$CreateNewAppKeys
)
$ErrorActionPreference = 'Stop'
if ($Mode -eq 'Show') {
    Write-Host 'Open PowerShell as Administrator ON the production IIS / Node server.'
    Write-Host 'Copy payload and merge configuration before using Repair.'
    Write-Host ''
    Write-Host '.\VERIFY.ps1'
    Write-Host '.\ops\SHOW-RUN.ps1 -Mode Check'
    Write-Host '.\ops\SHOW-RUN.ps1 -Mode Repair -InstallDependencies -CreateNewAppKeys'
    Write-Host '# Future code updates, when transport and proxy are correct:'
    Write-Host '.\ops\SHOW-RUN.ps1 -Mode Restart'
    Write-Host ''
    Write-Host 'Repair: Node HTTP localhost:4003 -> IIS WSS /eis-docs/presence.'
    Write-Host 'Existing keys and history are preserved. No DB configuration is replaced.'
    return
}
$principal = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run PowerShell as Administrator on the production server.'
}
$task = Get-ScheduledTask -TaskName $TaskName -TaskPath '\' -ErrorAction SilentlyContinue
$service = Join-Path $ProjectPath 'app\services\presence'
$nodeCommand = Get-Command node.exe -ErrorAction SilentlyContinue
$node = if ($nodeCommand) { $nodeCommand.Source } else { 'C:\Program Files\nodejs\node.exe' }
if ($task) {
    $actions = @($task.Actions)
    if ($actions.Count -ne 1 -or $actions[0].Arguments.Trim() -notin @('server.js','"server.js"')) {
        throw 'Unexpected task action. Inspect the actual launcher; no changes made.'
    }
    $service = [Environment]::ExpandEnvironmentVariables($actions[0].WorkingDirectory)
    $node = [Environment]::ExpandEnvironmentVariables($actions[0].Execute)
}
$service = (Resolve-Path -LiteralPath $service).Path
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'Install the approved Node runtime first; task Node executable is missing.' }
$configPath = Join-Path $service 'server.config.json'
$overrides = @()
foreach ($name in @('EIS_PRESENCE_SERVER_CONFIG','EIS_DOCS_PRESENCE_DIR','EIS_PRESENCE_TRANSPORT','EIS_DOCS_PRESENCE_PORT')) {
    foreach ($scope in @('Process','Machine')) {
        if ([Environment]::GetEnvironmentVariable($name,$scope)) { $overrides += "$name ($scope)" }
    }
}
if ($overrides.Count) { throw ('Environment overrides detected: ' + ($overrides -join ', ') + '. Align the actual task environment first. Values were not printed.') }
if (-not (Test-Path -LiteralPath $configPath)) {
    throw "Missing $configPath. Configure the EXISTING private production storage first; do not start with development defaults."
}
$config = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
if (-not $config.directory) { throw 'server.config.json must specify the existing private storage directory.' }
$storage = (Resolve-Path -LiteralPath $config.directory).Path
$publicRoots = @((Join-Path $ProjectPath 'app\public'), 'C:\xampp\htdocs')
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
if (Test-Path -LiteralPath $appcmd) {
    $actualPublic = & $appcmd list vdir "$ApplicationName/" /text:physicalPath
    if ($LASTEXITCODE -eq 0 -and $actualPublic) {
        $publicRoots += [Environment]::ExpandEnvironmentVariables(($actualPublic -join '').Trim())
    } elseif ($Mode -eq 'Repair') { throw 'Cannot resolve the target IIS application. Specify its actual -ApplicationName.' }
} elseif ($Mode -eq 'Repair') { throw 'IIS appcmd missing. Run Repair on the production IIS / Node server.' }
foreach ($publicRoot in $publicRoots) {
    $publicBoundary = [IO.Path]::GetFullPath($publicRoot).TrimEnd('\') + '\'
    if (($storage.TrimEnd('\') + '\').StartsWith($publicBoundary,[StringComparison]::OrdinalIgnoreCase)) {
        throw 'Signing keys and chat/history must be outside the web root.'
    }
}
Write-Host "Actual Node directory: $service"
Write-Host "Private storage: $storage"
Write-Host "Task exists: $([bool]$task); transport=$($config.transport); port=$($config.port)"
foreach ($file in @('server.js','store.js','chat.js','report.js','package-lock.json')) {
    $runtimeFile = Join-Path $service $file
    if (-not (Test-Path -LiteralPath $runtimeFile)) { throw "Missing $file. Deploy the release to the ACTUAL task directory above." }
    if ($file.EndsWith('.js')) { & $node --check $runtimeFile; if ($LASTEXITCODE -ne 0) { throw "Invalid JavaScript: $file" } }
}
if ((Get-Content -LiteralPath (Join-Path $service 'server.js') -Raw) -notmatch '20261013-ws-safe') {
    throw 'The actual task directory does not contain the release. Copy the updated Node files there first.'
}
foreach ($keyName in @('eis-docs.secret','supplies.secret')) {
    $keyPath = Join-Path $storage $keyName
    if (-not (Test-Path -LiteralPath $keyPath)) { throw "Missing existing $keyName. Use the existing production storage; do not generate replacement keys." }
    if (([IO.File]::ReadAllText($keyPath).Trim()).Length -lt 32) { throw "Invalid key: $keyName. Secret values were not printed." }
}
foreach ($keyName in @('procure.secret','nmu-eis.secret')) { Write-Host "$keyName exists: $(Test-Path -LiteralPath (Join-Path $storage $keyName))" }
function Test-LocalHealth {
    try {
        $health = Invoke-RestMethod 'http://127.0.0.1:4003/health' -TimeoutSec 2
        return $health.status -eq 'ok' -and $health.service -eq 'docs-supplies-presence' -and $health.build -eq '20261013-ws-safe'
    } catch { return $false }
}
function Test-PresencePort {
    $probe = [Net.Sockets.TcpClient]::new()
    try { return $probe.ConnectAsync('127.0.0.1',4003).Wait(500) -and $probe.Connected }
    catch { return $false } finally { $probe.Dispose() }
}
if ($Mode -eq 'Check') {
    Write-Host "Local HTTP health / expected build: $(Test-LocalHealth)"
    Write-Host "Port 4003 listening: $(Test-PresencePort)"
    if ($task) {
        $info = Get-ScheduledTaskInfo -TaskName $TaskName -TaskPath '\'
        Write-Host "Task state=$($task.State), LastTaskResult=$($info.LastTaskResult)"
    }
    & (Join-Path $PSScriptRoot 'helpers\Test-ProductionMessenger.ps1') -ApplicationName $ApplicationName
    return
}
if (-not $task -and (Test-PresencePort)) { throw 'Port 4003 is occupied without the expected task. Identify its owner before changing config; no second service started.' }
foreach ($file in @('history.json','chat.json')) {
    $dataPath = Join-Path $storage $file
    if (Test-Path -LiteralPath $dataPath) {
        try { [void](Get-Content -LiteralPath $dataPath -Raw -Encoding UTF8 | ConvertFrom-Json) }
        catch { throw "Invalid JSON in $file; inspect/restore data before starting. No data changed." }
    }
}
if ($InstallDependencies) {
    $npm = Join-Path (Split-Path -Parent $node) 'npm.cmd'
    if (-not (Test-Path -LiteralPath $npm)) { throw 'npm.cmd missing next to the task Node executable.' }
    Push-Location $service
    try { & $npm ci --omit=dev --ignore-scripts; if ($LASTEXITCODE -ne 0) { throw 'npm ci failed; no restart requested.' } }
    finally { Pop-Location }
}
Push-Location $service
try {
    & $node -e "if(typeof [].findLast!=='function')throw new Error('Node runtime lacks Array.findLast; use an approved compatible runtime');require('ws');require('./store');require('./chat');require('./report');console.log('PASS runtime dependencies');"
    if ($LASTEXITCODE -ne 0) { throw 'Dependencies missing. Retry with -InstallDependencies after checking npm availability.' }
} finally { Pop-Location }
if ($CreateNewAppKeys) { & (Join-Path $PSScriptRoot 'New-MessengerKeys.ps1') -PrivateDirectory $storage }
foreach ($keyName in @('procure.secret','nmu-eis.secret')) {
    if (-not (Test-Path -LiteralPath (Join-Path $storage $keyName))) { throw "Missing $keyName. Provision it or use -CreateNewAppKeys." }
}
if ($Mode -eq 'Repair') {
    $backupRoot = Join-Path $env:ProgramData 'EIS-Messenger\config-backups'
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    $backup = Join-Path $backupRoot ('node-' + [guid]::NewGuid().ToString('N') + '.json.bak')
    Copy-Item -LiteralPath $configPath -Destination $backup
    $config | Add-Member -NotePropertyName transport -NotePropertyValue 'http' -Force
    $config | Add-Member -NotePropertyName port -NotePropertyValue 4003 -Force
    $origins = @($config.origins) + @('https://doc-eis.nmu.ac.th','https://eis.nmu.ac.th')
    $config | Add-Member -NotePropertyName origins -NotePropertyValue @($origins | Where-Object { $_ } | Select-Object -Unique) -Force
    [IO.File]::WriteAllText($configPath,($config | ConvertTo-Json -Depth 30),[Text.UTF8Encoding]::new($false))
    Write-Host "Config backup: $backup"
} elseif ($config.transport -ne 'http' -or [int]$config.port -ne 4003) {
    throw 'Transport/port does not match the production IIS proxy. Use -Mode Repair.'
}
if (-not $task) {
    if (Test-PresencePort) { throw 'Port 4003 occupied without the expected task. Identify its owner; no second Node started.' }
    $action = New-ScheduledTaskAction -Execute $node -Argument 'server.js' -WorkingDirectory $service
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable
    $identity = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $TaskName -TaskPath '\' -Action $action -Trigger $trigger -Settings $settings -Principal $identity -Description 'Shared Messenger; localhost HTTP only.' | Out-Null
    Write-Host 'Created startup task. SYSTEM needs permission to the private storage; UNC shares need matching authentication.'
} else {
    Stop-ScheduledTask -TaskName $TaskName -TaskPath '\'
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    do {
        $running = (Get-ScheduledTask -TaskName $TaskName -TaskPath '\').State -eq 'Running'
        $portBusy = Test-PresencePort
        if (-not $running -and -not $portBusy) { break }
        if ([DateTime]::UtcNow -ge $deadline) { throw 'Task/port did not stop; no second service started. Inspect the port owner.' }
        Start-Sleep -Milliseconds 300
    } while ($true)
}
Start-ScheduledTask -TaskName $TaskName -TaskPath '\'
$deadline = [DateTime]::UtcNow.AddSeconds(25)
do {
    if (Test-LocalHealth) { break }
    if ([DateTime]::UtcNow -ge $deadline) { throw 'HTTP health/build unavailable. Inspect Task LastTaskResult, storage permissions and server.config.json.' }
    Start-Sleep -Milliseconds 500
} while ($true)
Write-Host 'PASS: local HTTP backend, build=20261013-ws-safe.'
if ($Mode -eq 'Repair') { & (Join-Path $PSScriptRoot 'helpers\Repair-ProductionMessengerProxy.ps1') -ApplicationName $ApplicationName }
else { & (Join-Path $PSScriptRoot 'helpers\Test-ProductionMessenger.ps1') -ApplicationName $ApplicationName }
Write-Host 'PASS: Ctrl+F5, then verify authenticated User Online / Messenger / Ticket.'
