#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$SuppliesPath = 'D:\EIS-ERP\public\supplies',
    [Parameter(Mandatory = $true)][string]$SharedDirectory,
    [string]$BackupDirectory = "$env:ProgramData\EIS-Messenger\config-backups"
)
$ErrorActionPreference = 'Stop'
if ($SharedDirectory -notmatch '^\\\\[^\\]+\\[^\\]+') { throw 'Use a UNC path, such as \\DOC-SERVER\EIS-Presence$.' }
$target = Join-Path $SuppliesPath 'conf\presence\presence.config.php'
if (-not (Test-Path -LiteralPath (Join-Path $SuppliesPath 'conf\presence\Presence.php'))) { throw 'Supplies presence helper missing.' }
foreach ($secret in @('eis-docs.secret','supplies.secret')) {
    if (-not (Test-Path -LiteralPath (Join-Path $SharedDirectory $secret))) { throw "Cannot read $secret through the share. Check SMB authentication and permissions." }
}
if ([Environment]::GetEnvironmentVariable('EIS_DOCS_PRESENCE_DIR') -or [Environment]::GetEnvironmentVariable('EIS_PRESENCE_CONFIG') -or [Environment]::GetEnvironmentVariable('EIS_PRESENCE_WS_URL')) {
    throw 'Presence environment overrides are set; align them before updating the config file.'
}
$public = [IO.Path]::GetFullPath($SuppliesPath).TrimEnd('\') + '\'
$backup = [IO.Path]::GetFullPath($BackupDirectory).TrimEnd('\')
if (($backup + '\').StartsWith($public, [StringComparison]::OrdinalIgnoreCase)) { throw 'Backups must be outside the website.' }
New-Item -ItemType Directory -Path $backup -Force | Out-Null
$oldFile = Join-Path $backup ('presence-' + [guid]::NewGuid().ToString('N') + '.php.bak')
if (Test-Path -LiteralPath $target) { Copy-Item -LiteralPath $target -Destination $oldFile }
$phpPrevious = $oldFile.Replace('\','/').Replace("'", "\'")
$phpDirectory = $SharedDirectory.Replace('\','/').Replace("'", "\'")
$php = @"
<?php
`$config = is_file('$phpPrevious') ? (array) require '$phpPrevious' : [];
`$config['enabled'] = true;
`$config['directory'] = '$phpDirectory';
`$config['websocket_url'] = 'wss://doc-eis.nmu.ac.th/eis-docs/presence';
return `$config;
"@
[IO.File]::WriteAllText($target, $php, (New-Object Text.UTF8Encoding($false)))
Write-Host "Supplies Messenger configured: $target"
Write-Host "Shared storage: $SharedDirectory; previous config: $oldFile"
Write-Host 'Administrator share access does not prove IIS/PHP access. Verify login/token in the browser under its actual app pool identity.'
