#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string]$Location='doc-eis.nmu.ac.th/eis-docs',
    [string]$ConfigPath='D:\EIS-DOCS\app\public\web.config',
    [string]$BackupDirectory='D:\EIS-DOCS\storage\config-backups'
)
$ErrorActionPreference='Stop'
$appcmd=Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
$resolved=(Resolve-Path -LiteralPath $ConfigPath).Path
$public=Split-Path -Parent $resolved
$backupRoot=[IO.Path]::GetFullPath($BackupDirectory)
if(($backupRoot.TrimEnd('\')+'\').StartsWith(($public.TrimEnd('\')+'\'),[StringComparison]::OrdinalIgnoreCase)) { throw 'Backup directory must be outside public.' }
$stamp=Get-Date -Format 'yyyyMMdd-HHmmss-fff'
& $appcmd add backup "Before-WebSocket-Delegation-$stamp"
if($LASTEXITCODE -ne 0) { throw 'IIS backup failed.' }
New-Item -ItemType Directory -Force -Path $backupRoot | Out-Null
$backup=Join-Path $backupRoot "web.config.websocket.$stamp.bak"
Copy-Item -LiteralPath $resolved -Destination $backup
$xml=New-Object System.Xml.XmlDocument
$xml.PreserveWhitespace=$true
$xml.Load($resolved)
$node=$xml.SelectSingleNode('/configuration/system.webServer/webSocket')
try {
    if($node) { [void]$node.ParentNode.RemoveChild($node); $xml.Save($resolved) }
    # Store the setting in applicationHost.config, not the delegated web.config.
    & $appcmd set config $Location /section:system.webServer/webSocket /enabled:true /commit:apphost
    if($LASTEXITCODE -ne 0) { throw 'Cannot configure WebSocket in applicationHost.config.' }
    & $appcmd list config $Location /section:system.webServer/webSocket
    if($LASTEXITCODE -ne 0) { throw 'Effective WebSocket configuration is still invalid.' }
} catch {
    Copy-Item -LiteralPath $backup -Destination $resolved -Force
    throw "Configuration failed; original web.config restored. $($_.Exception.Message)"
}
$pool=& $appcmd list app $Location /text:applicationPool
if($LASTEXITCODE -ne 0 -or !$pool) { throw 'WebSocket configured, but cannot resolve App Pool.' }
& $appcmd recycle apppool "/apppool.name:$($pool.Trim())"
if($LASTEXITCODE -ne 0) { throw 'App Pool recycle failed.' }
Write-Output "WebSocket now configured in applicationHost.config. File backup: $backup"
try {
    $result=Invoke-RestMethod ('https://' + $Location.TrimEnd('/') + '/api/health') -TimeoutSec 15
    if($result.status -ne 'UP') { throw 'Health response is not UP.' }
    Write-Output 'PASS: public health UP. Refresh browser and test PDF and notifications.'
} catch {
    Write-Warning "WebSocket configuration check passed, but public health failed: $($_.Exception.Message)"
}
