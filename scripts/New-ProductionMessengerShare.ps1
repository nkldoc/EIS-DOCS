#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$ProjectPath = 'D:\EIS-DOCS',
    [string]$ShareName = 'EIS-Presence$',
    [Parameter(Mandatory = $true)][string]$RemoteAccount
)
$ErrorActionPreference = 'Stop'
$config = Get-Content -LiteralPath (Join-Path $ProjectPath 'app\services\presence\server.config.json') -Raw | ConvertFrom-Json
$storage = (Resolve-Path -LiteralPath $config.directory).Path
foreach ($secret in @('eis-docs.secret','supplies.secret')) {
    if (-not (Test-Path -LiteralPath (Join-Path $storage $secret))) { throw "Missing $secret; use the existing Messenger storage." }
}
# Require an explicit account; never grant broad access to chat history/secrets.
if ($RemoteAccount -match '^(Everyone|Authenticated Users|Users|Guest|Guests|ANONYMOUS LOGON)$') { throw 'Specify the Supplies service account or its AD machine account.' }
$account = New-Object Security.Principal.NTAccount($RemoteAccount)
$sid = $account.Translate([Security.Principal.SecurityIdentifier])
$existing = Get-SmbShare -Name $ShareName -ErrorAction SilentlyContinue
if ($existing -and [IO.Path]::GetFullPath($existing.Path).TrimEnd('\') -ne $storage.TrimEnd('\')) { throw 'Share name already points to a different directory.' }
$backup = Join-Path $ProjectPath ('storage\config-backups\share-acl-' + [guid]::NewGuid().ToString('N') + '.txt')
New-Item -ItemType Directory -Path (Split-Path $backup -Parent) -Force | Out-Null
& icacls.exe $storage /save $backup /T /C
if ($LASTEXITCODE -ne 0) { throw 'ACL backup failed.' }
& icacls.exe $storage /grant "*$($sid.Value):(OI)(CI)M" /T /C
if ($LASTEXITCODE -ne 0) { throw 'NTFS permission update failed.' }
if ($existing) {
    Grant-SmbShareAccess -Name $ShareName -AccountName $RemoteAccount -AccessRight Change -Force | Out-Null
} else {
    New-SmbShare -Name $ShareName -Path $storage -ChangeAccess $RemoteAccount -FolderEnumerationMode AccessBased | Out-Null
}
Write-Host "Share: \\$env:COMPUTERNAME\$ShareName"
Write-Host "Storage and history retained: $storage"
Write-Host "Account: $RemoteAccount; ACL backup: $backup"
Write-Host 'Allow TCP 445 only from the Supplies server through the server/network firewall. Verify access using the actual PHP identity.'
