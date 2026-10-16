#Requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess)]
param(
    [string]$ConfigPath = 'D:\EIS-DOCS\app\public\web.config',
    [string]$BackupDirectory = 'D:\EIS-DOCS\storage\config-backups'
)
$ErrorActionPreference = 'Stop'
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
if (!(Test-Path -LiteralPath $appcmd)) { throw 'IIS appcmd not found.' }
$configFile = (Resolve-Path -LiteralPath $ConfigPath).Path
$publicDirectory = Split-Path -Parent $configFile
if (!(Test-Path -LiteralPath (Join-Path $publicDirectory 'login.php'))) { throw 'Choose the EIS-DOCS public web.config beside login.php.' }
$backupRoot = [IO.Path]::GetFullPath($BackupDirectory)
if (($backupRoot.TrimEnd('\')+'\').StartsWith(($publicDirectory.TrimEnd('\')+'\'),[StringComparison]::OrdinalIgnoreCase)) { throw 'Backups must be outside public.' }
$health = Invoke-RestMethod 'http://127.0.0.1:8080/eis-docs/api/health' -TimeoutSec 8
if ($health.status -ne 'UP') { throw 'Tomcat health is not UP. No configuration was changed.' }
$null = & $appcmd list config /section:system.webServer/proxy 2>&1
if ($LASTEXITCODE -ne 0) { throw 'Install IIS Application Request Routing (ARR) before running this script.' }
$null = & $appcmd list config /section:system.webServer/rewrite/rules 2>&1
if ($LASTEXITCODE -ne 0) { throw 'Install IIS URL Rewrite before running this script.' }
$modules = & $appcmd list modules
if (($modules -join ' ') -notmatch 'WebSocketModule') { throw 'Install IIS WebSocket Protocol (Web-WebSockets) before running this script.' }

$xml = New-Object System.Xml.XmlDocument
$xml.PreserveWhitespace = $true
$xml.Load($configFile)
$server = $xml.SelectSingleNode('/configuration/system.webServer')
if (!$server) { throw 'system.webServer not found.' }
$rewrite = $server.SelectSingleNode('rewrite')
if (!$rewrite) { $rewrite=$xml.CreateElement('rewrite'); [void]$server.AppendChild($rewrite) }
$rules = $rewrite.SelectSingleNode('rules')
if (!$rules) { $rules=$xml.CreateElement('rules'); [void]$rewrite.AppendChild($rules) }
$definitions = @(
    @('Notification WebSocket','^ws(?:/(.*))?$','http://127.0.0.1:8080/eis-docs/ws/{R:1}'),
    @('PDF Manager friendly route','^pdf(?:/(.*))?$','http://127.0.0.1:8080/eis-docs/api/pdf/{R:1}'),
    @('PDF Manager API route','^api/pdf(?:/(.*))?$','http://127.0.0.1:8080/eis-docs/api/pdf/{R:1}'),
    @('Java API reverse proxy','^api(?:/(.*))?$','http://127.0.0.1:8080/eis-docs/api/{R:1}')
)
$newNodes = @()
foreach ($definition in $definitions) {
    foreach ($node in @($rules.ChildNodes)) {
        if ($node -is [System.Xml.XmlElement] -and $node.GetAttribute('name') -eq $definition[0]) { [void]$rules.RemoveChild($node) }
    }
    $remove=$xml.CreateElement('remove'); $remove.SetAttribute('name',$definition[0]); $newNodes+=$remove
    $rule=$xml.CreateElement('rule'); $rule.SetAttribute('name',$definition[0]); $rule.SetAttribute('stopProcessing','true')
    $match=$xml.CreateElement('match'); $match.SetAttribute('url',$definition[1]); [void]$rule.AppendChild($match)
    $action=$xml.CreateElement('action'); $action.SetAttribute('type','Rewrite'); $action.SetAttribute('url',$definition[2]); $action.SetAttribute('appendQueryString','true'); [void]$rule.AppendChild($action)
    $newNodes+=$rule
}
for($i=$newNodes.Count-1;$i -ge 0;$i--) { [void]$rules.PrependChild($newNodes[$i]) }
$webSocket=$server.SelectSingleNode('webSocket')
if(!$webSocket) { $webSocket=$xml.CreateElement('webSocket'); [void]$server.AppendChild($webSocket) }
$webSocket.SetAttribute('enabled','true')
if ($PSCmdlet.ShouldProcess($configFile,'Back up config, enable ARR server proxy, and repair EIS-DOCS routes')) {
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    $stamp=Get-Date -Format 'yyyyMMdd-HHmmss-fff'
    Copy-Item -LiteralPath $configFile -Destination (Join-Path $backupRoot "web.config.proxy.$stamp.bak")
    & $appcmd add backup "EIS-DOCS-Proxy-$stamp"
    if ($LASTEXITCODE -ne 0) { throw 'IIS backup failed.' }
    & $appcmd set config /section:system.webServer/proxy /enabled:true /commit:apphost
    if ($LASTEXITCODE -ne 0) { throw 'Could not enable ARR proxy.' }
    $xml.Save($configFile)
    Write-Output 'Proxy rules saved. Tomcat restart is not needed.'
    try {
        $public = Invoke-RestMethod 'https://doc-eis.nmu.ac.th/eis-docs/api/health' -TimeoutSec 8
        if ($public.status -ne 'UP') { throw 'Public health is not UP.' }
        Write-Output 'PASS: public IIS health is UP. Refresh the page and check WebSocket status 101.'
    } catch {
        Write-Warning 'Public health still fails. Verify /eis-docs is an IIS Application pointing to this public directory. Check parent-site rewrite rules and IIS logs for the HTTP substatus.'
    }
}
