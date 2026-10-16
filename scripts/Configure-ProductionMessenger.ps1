#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$ProjectPath = 'D:\EIS-DOCS',
    [Parameter(Mandatory = $true)][string]$StorageDirectory,
    [switch]$RestartService
)
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectPath).Path
$storage = (Resolve-Path -LiteralPath $StorageDirectory).Path
$servicePath = Join-Path $project 'app\services\presence'
$phpPath = Join-Path $project 'app\src\presence.config.php'
$nodePath = Join-Path $servicePath 'server.config.json'
$webPath = Join-Path $project 'app\public\web.config'
$publicPath = [IO.Path]::GetFullPath((Join-Path $project 'app\public')).TrimEnd('\') + '\'
if (($storage.TrimEnd('\') + '\').StartsWith($publicPath, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Presence storage must be outside the public web directory.'
}
foreach ($name in @('eis-docs.secret', 'supplies.secret')) {
    if (-not (Test-Path -LiteralPath (Join-Path $storage $name) -PathType Leaf)) {
        throw "Missing $name in the existing shared presence storage. No changes made."
    }
}
foreach ($file in @((Join-Path $servicePath 'server.js'), $webPath)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing file: $file" }
}
# Explicit environment overrides take precedence over files. Report names only.
foreach ($name in @('EIS_PRESENCE_CONFIG','EIS_PRESENCE_WS_URL','EIS_PRESENCE_SERVER_CONFIG','EIS_DOCS_PRESENCE_DIR','EIS_PRESENCE_TRANSPORT')) {
    if ([Environment]::GetEnvironmentVariable($name)) {
        throw "Environment override $name is set. Align or remove it before running this script; no changes made."
    }
}
$config = if (Test-Path -LiteralPath $nodePath) {
    Get-Content -LiteralPath $nodePath -Raw | ConvertFrom-Json
} else { [pscustomobject]@{} }
# Refuse to move an existing deployment to a different history directory.
if ($config.directory -and [IO.Path]::GetFullPath($config.directory).TrimEnd('\') -ne $storage.TrimEnd('\')) {
    throw 'StorageDirectory differs from the existing Node config. Use its existing history directory.'
}
$utf8 = New-Object Text.UTF8Encoding($false)
$web = New-Object Xml.XmlDocument
$web.PreserveWhitespace = $true
$web.Load($webPath)
$rules = $web.SelectSingleNode('/configuration/system.webServer/rewrite/rules')
if (-not $rules) { throw 'Existing IIS rewrite/rules section required; no changes made.' }
$rule = $web.SelectSingleNode('/configuration/system.webServer/rewrite/rules/rule[@name="EIS Messenger Presence"]')
if (-not $rule) { $rule = $web.CreateElement('rule') } else { $rule.RemoveAll() }
$rule.SetAttribute('name', 'EIS Messenger Presence')
$rule.SetAttribute('stopProcessing', 'true')
$match = $web.CreateElement('match')
$match.SetAttribute('url', '^presence$')
$action = $web.CreateElement('action')
$action.SetAttribute('type', 'Rewrite')
$action.SetAttribute('url', 'http://127.0.0.1:4003/presence')
$action.SetAttribute('appendQueryString', 'false')
[void]$rule.AppendChild($match)
[void]$rule.AppendChild($action)
[void]$rules.PrependChild($rule)
$remove = $rules.SelectSingleNode('remove[@name="EIS Messenger Presence"]')
if ($remove) { [void]$rules.PrependChild($remove) }

$backup = Join-Path $project ('storage\config-backups\messenger-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $backup -Force | Out-Null
$files = @($phpPath, $nodePath, $webPath)
$original = @{}
foreach ($file in $files) {
    $original[$file] = Test-Path -LiteralPath $file
    if ($original[$file]) { Copy-Item -LiteralPath $file -Destination (Join-Path $backup ([IO.Path]::GetFileName($file))) }
}
foreach ($entry in @{directory=$storage.Replace('\','/'); transport='http'; port=4003; origins=@('https://doc-eis.nmu.ac.th','https://eis.nmu.ac.th')}.GetEnumerator()) {
    $config | Add-Member -NotePropertyName $entry.Key -NotePropertyValue $entry.Value -Force
}
# Keep unrelated PHP config options. The wrapper reads the backed-up config first.
$previousPhp = Join-Path $backup 'presence.config.php'
$phpStorage = $storage.Replace('\','/').Replace("'", "\'")
$phpPrevious = $previousPhp.Replace('\','/').Replace("'", "\'")
$php = @"
<?php
`$config = is_file('$phpPrevious') ? (array) require '$phpPrevious' : [];
`$config['enabled'] = true;
`$config['directory'] = '$phpStorage';
`$config['websocket_url'] = 'wss://doc-eis.nmu.ac.th/eis-docs/presence';
return `$config;
"@
try {
    [IO.File]::WriteAllText($phpPath, $php, $utf8)
    [IO.File]::WriteAllText($nodePath, ($config | ConvertTo-Json -Depth 20), $utf8)
    $web.Save($webPath)
} catch {
    foreach ($file in $files) {
        if ($original[$file]) { Copy-Item -LiteralPath (Join-Path $backup ([IO.Path]::GetFileName($file))) -Destination $file -Force }
        elseif (Test-Path -LiteralPath $file) { Remove-Item -LiteralPath $file }
    }
    throw
}
Write-Host "Backup: $backup"
Write-Host 'Production Messenger: wss://doc-eis.nmu.ac.th/eis-docs/presence'
Write-Host 'IIS requires ARR proxy and WebSocket Protocol enabled for this application.'
if ($RestartService) {
    $taskName = 'EIS-DOCS-Supplies-Presence'
    $task = Get-ScheduledTask -ErrorAction Stop | Where-Object { $_.TaskName -eq $taskName -and $_.TaskPath -eq '\' }
    if (-not $task) {
        $installer = Join-Path $servicePath 'install-production-task.ps1'
        if (-not (Test-Path -LiteralPath $installer)) { throw "Task missing. Run the production task installer from $servicePath." }
        & $installer
        if (-not $?) { throw 'Production task installation failed.' }
        $task = Get-ScheduledTask -TaskName $taskName -TaskPath '\' -ErrorAction Stop
        if ($task.State -ne 'Running') { throw 'Task installed but not running. Inspect Task Scheduler and the Node service configuration.' }
        Write-Host 'Presence task installed and running. Verify Messenger from both EIS-DOCS and Supplies.'
        return
    }
    foreach ($taskAction in $task.Actions) {
        if ($taskAction.WorkingDirectory.TrimEnd('\') -ne $servicePath.TrimEnd('\')) {
            throw 'Scheduled task uses a different service directory. Config saved; restart its actual service manually.'
        }
    }
    Stop-ScheduledTask -TaskName $taskName -TaskPath '\' -ErrorAction Stop
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    while ((Get-ScheduledTask -TaskName $taskName -TaskPath '\' -ErrorAction Stop).State -eq 'Running') {
        if ([DateTime]::UtcNow -gt $deadline) { throw 'Scheduled task did not stop within 30 seconds.' }
        Start-Sleep -Milliseconds 500
    }
    Start-ScheduledTask -TaskName $taskName -TaskPath '\' -ErrorAction Stop
    Write-Host 'Presence task restarted. Verify Messenger from both EIS-DOCS and Supplies.'
}
