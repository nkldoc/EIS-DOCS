#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param([string]$ApplicationName = 'doc-eis.nmu.ac.th/eis-docs')
$ErrorActionPreference = 'Stop'
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
$physicalPath = & $appcmd list vdir "$ApplicationName/" /text:physicalPath
if ($LASTEXITCODE -ne 0 -or -not $physicalPath) { throw 'Cannot resolve IIS application physical path.' }
$physicalPath = [Environment]::ExpandEnvironmentVariables(($physicalPath -join '').Trim())
$file = Join-Path $physicalPath 'web.config'
if (-not (Test-Path -LiteralPath $file)) { throw "Missing $file" }
try { $health = Invoke-RestMethod 'http://127.0.0.1:4003/health' -TimeoutSec 5 }
catch { throw 'Production Messenger must serve HTTP on 127.0.0.1:4003. Check Node transport=http, service configuration and scheduled task before repairing IIS.' }
if ($health.status -ne 'ok' -or $health.service -ne 'docs-supplies-presence') { throw 'Messenger backend is not healthy.' }
$xml = New-Object Xml.XmlDocument
$xml.PreserveWhitespace = $true
$xml.Load($file)
$rules = $xml.SelectSingleNode('/configuration/system.webServer/rewrite/rules')
if (-not $rules) { throw 'Rewrite rules section missing.' }
$rule = $rules.SelectSingleNode('rule[@name="EIS Messenger Presence"]')
if ($rule) { $rule.RemoveAll() } else { $rule = $xml.CreateElement('rule') }
$rule.SetAttribute('name', 'EIS Messenger Presence')
$rule.SetAttribute('stopProcessing', 'true')
$match = $xml.CreateElement('match')
$match.SetAttribute('url', '^presence/?$')
$action = $xml.CreateElement('action')
$action.SetAttribute('type', 'Rewrite')
$action.SetAttribute('url', 'http://127.0.0.1:4003/presence')
$action.SetAttribute('appendQueryString', 'false')
[void]$rule.AppendChild($match)
[void]$rule.AppendChild($action)
[void]$rules.PrependChild($rule)
# A remove directive must run before the replacement, including on repeated runs.
$remove = $rules.SelectSingleNode('remove[@name="EIS Messenger Presence"]')
if ($remove) { [void]$rules.PrependChild($remove) }
$backupDirectory = Join-Path $env:ProgramData 'EIS-Messenger\config-backups'
New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
$backup = Join-Path $backupDirectory ('web-' + [guid]::NewGuid().ToString('N') + '.config.bak')
Copy-Item -LiteralPath $file -Destination $backup
$iisBackup = 'Before-Messenger-Proxy-' + [guid]::NewGuid().ToString('N')
& $appcmd add backup $iisBackup
if ($LASTEXITCODE -ne 0) { throw 'Cannot back up IIS configuration; no changes made.' }
try {
    # These are server-owned sections; placing webSocket in web.config can
    # cause 500.19 on installations that lock delegation.
    & $appcmd set config /section:system.webServer/proxy /enabled:true /commit:apphost
    if ($LASTEXITCODE -ne 0) { throw 'Cannot enable ARR proxy. Verify Application Request Routing is installed.' }
    & $appcmd set config $ApplicationName /section:system.webServer/webSocket /enabled:true /commit:apphost
    if ($LASTEXITCODE -ne 0) { throw 'Cannot enable WebSocket for this IIS application. Verify WebSocket Protocol is installed.' }
    $xml.Save($file)
    $validation = & $appcmd list config $ApplicationName /section:system.webServer/rewrite/rules 2>&1
    if ($LASTEXITCODE -ne 0) { throw ($validation -join "`n") }
} catch {
    Copy-Item -LiteralPath $backup -Destination $file -Force
    throw
}
Write-Host "Updated IIS config: $file"
Write-Host "Backup: $backup"
Write-Host "IIS configuration backup: $iisBackup"
Write-Host 'Presence proxy added: /eis-docs/presence -> http://127.0.0.1:4003/presence'
& (Join-Path $PSScriptRoot 'Test-ProductionMessenger.ps1') -ApplicationName $ApplicationName
