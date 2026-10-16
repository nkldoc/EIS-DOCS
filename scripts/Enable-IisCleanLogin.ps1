[CmdletBinding(SupportsShouldProcess)]
param(
    [string]$ConfigPath = 'D:\EIS-DOCS\app\public\web.config',
    [ValidatePattern('^(/[A-Za-z0-9_-]+)+$')][string]$ApplicationPath = '/eis-docs',
    [string]$BackupDirectory = 'D:\EIS-DOCS\storage\config-backups'
)
$ErrorActionPreference = 'Stop'
$resolved = (Resolve-Path -LiteralPath $ConfigPath).Path
$publicDirectory = Split-Path -Parent $resolved
if (-not (Test-Path -LiteralPath (Join-Path $publicDirectory 'login.php'))) { throw 'login.php must exist beside web.config.' }
$backupRoot = [IO.Path]::GetFullPath($BackupDirectory)
if (($backupRoot.TrimEnd('\')+'\').StartsWith(($publicDirectory.TrimEnd('\')+'\'), [StringComparison]::OrdinalIgnoreCase)) { throw 'Backups must be outside the public directory.' }
$xml = New-Object System.Xml.XmlDocument
$xml.PreserveWhitespace = $true
$xml.Load($resolved)
$server = $xml.SelectSingleNode('/configuration/system.webServer')
if ($null -eq $server) { throw 'system.webServer section not found.' }
$rewrite = $server.SelectSingleNode('rewrite')
if ($null -eq $rewrite) { $rewrite=$xml.CreateElement('rewrite'); [void]$server.AppendChild($rewrite) }
$rules=$rewrite.SelectSingleNode('rules')
if ($null -eq $rules) { $rules=$xml.CreateElement('rules'); [void]$rewrite.AppendChild($rules) }
$pathPattern=[regex]::Escape($ApplicationPath)
[xml]$newRules=@"
<rules>
  <remove name="EIS-DOCS clean login redirect" />
  <remove name="EIS-DOCS clean login rewrite" />
  <rule name="EIS-DOCS clean login redirect" stopProcessing="true">
    <match url="^login(?:\.php|/)$" ignoreCase="true" />
    <conditions logicalGrouping="MatchAll">
      <add input="{REQUEST_METHOD}" pattern="^(GET|HEAD)$" />
      <add input="{THE_REQUEST}" pattern="\s+$pathPattern/login(?:\.php|/)(?:[?\s])" ignoreCase="true" />
    </conditions>
    <action type="Redirect" url="$ApplicationPath/login" redirectType="Found" appendQueryString="true" />
  </rule>
  <rule name="EIS-DOCS clean login rewrite" stopProcessing="true">
    <match url="^login/?$" ignoreCase="true" />
    <action type="Rewrite" url="login.php" appendQueryString="true" />
  </rule>
</rules>
"@
$names=@('EIS-DOCS clean login redirect','EIS-DOCS clean login rewrite')
$before=$xml.OuterXml
$existing=@($rules.ChildNodes | Where-Object { $_ -is [System.Xml.XmlElement] -and $names -contains $_.GetAttribute('name') })
foreach($node in $existing) { [void]$rules.RemoveChild($node) }
# Prepend in reverse order so these routes run before broader inherited/local rules.
$nodes=@($newRules.DocumentElement.ChildNodes)
for($i=$nodes.Count-1;$i -ge 0;$i--) { [void]$rules.PrependChild($xml.ImportNode($nodes[$i],$true)) }
if ($xml.OuterXml -eq $before) { Write-Output 'Clean login routes already configured.'; return }
if (-not $PSCmdlet.ShouldProcess($resolved,'Add clean /login routes; back up existing config')) { return }
[void][IO.Directory]::CreateDirectory($backupRoot)
$backup=Join-Path $backupRoot ('web.config.clean-login.'+[DateTime]::Now.ToString('yyyyMMdd-HHmmss-fff')+'.bak')
Copy-Item -LiteralPath $resolved -Destination $backup
try { $xml.Save($resolved) } catch { Copy-Item -LiteralPath $backup -Destination $resolved -Force; throw }
Write-Output "Configured $ApplicationPath/login -> login.php (GET and POST)."
Write-Output 'GET/HEAD login.php redirects to /login; POST login.php is preserved.'
Write-Output "Backup: $backup"
Write-Output 'IIS automatically reloads web.config; no iisreset is needed.'
