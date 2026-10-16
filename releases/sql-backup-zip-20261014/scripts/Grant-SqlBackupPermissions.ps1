#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [ValidateSet('local','production','product')][string]$Connection = 'local',
    [ValidateSet('SameServer','SqlServer','WebServer')][string]$Role = 'SameServer',
    [string]$Directory = 'D:\EIS-SQL-Backups',
    [string]$AppDirectory = 'D:\EIS-DOCS\app',
    [string]$ApplicationPool,
    [string]$SqlInstanceService = 'MSSQLSERVER',
    [string]$SqlServiceAccount,
    [string]$WebServiceAccount
)
$ErrorActionPreference = 'Stop'
if ($Connection -eq 'product') { $Connection = 'production' }
if ($Directory -notmatch '^[Dd]:[\\/]') { throw 'Use a dedicated backup folder on drive D:.' }
$Directory = [IO.Path]::GetFullPath($Directory).TrimEnd('\','/')
if ($Directory -eq 'D:') { throw 'Specify a backup subdirectory; a drive root is not a backup directory.' }
$public = [IO.Path]::GetFullPath((Join-Path $AppDirectory 'public')).TrimEnd('\','/')
if ($Directory -eq $public -or $Directory.StartsWith($public + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Backup folder must be outside the public web directory.' }
$accounts = @()
if ($Role -in @('SameServer','SqlServer')) {
    if (-not $SqlServiceAccount) {
        $service = Get-CimInstance Win32_Service | Where-Object { $_.Name -eq $SqlInstanceService } | Select-Object -First 1
        if (-not $service) { throw "SQL service $SqlInstanceService not found. Specify SqlInstanceService or SqlServiceAccount." }
        $SqlServiceAccount = $service.StartName
        if ($SqlServiceAccount -eq 'LocalSystem') { $SqlServiceAccount = 'NT AUTHORITY\SYSTEM' }
    }
    $accounts += $SqlServiceAccount
}
if ($Role -in @('SameServer','WebServer') -and -not $WebServiceAccount) {
    Import-Module WebAdministration -ErrorAction Stop
    if (-not $ApplicationPool) {
        function MatchesPublicPath($path) {
            if (-not $path) { return $false }
            return [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables([string]$path)).TrimEnd('\','/') -eq $public
        }
        $apps = @(Get-WebApplication | Where-Object { MatchesPublicPath $_.PhysicalPath })
        $sites = @(Get-Website | Where-Object { MatchesPublicPath $_.PhysicalPath })
        $pools = @(@($apps) + @($sites) | ForEach-Object { $_.applicationPool } | Where-Object { $_ } | Select-Object -Unique)
        if ($pools.Count -ne 1) {
            # WebAdministration may omit application PhysicalPath; IIS stores it on the root virtual directory.
            Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll')
            $manager = New-Object Microsoft.Web.Administration.ServerManager
            try {
                $pools = @(foreach ($site in $manager.Sites) {
                    foreach ($application in $site.Applications) {
                        $root = $application.VirtualDirectories['/']
                        if ($root -and (MatchesPublicPath $root.PhysicalPath)) { $application.ApplicationPoolName }
                    }
                }) | Select-Object -Unique
                $pools = @($pools)
            } finally { $manager.Dispose() }
        }
        if ($pools.Count -ne 1) { throw 'Cannot identify one EIS-DOCS IIS pool from its physical path. Run appcmd list app to find the active pool, then pass -ApplicationPool explicitly.' }
        $ApplicationPool = $pools[0]
    }
    $pool = Get-Item -LiteralPath ('IIS:\AppPools\' + $ApplicationPool)
    switch ([string]$pool.processModel.identityType) {
        { $_ -in @('ApplicationPoolIdentity','4') } { $WebServiceAccount = 'IIS APPPOOL\' + $ApplicationPool }
        { $_ -in @('SpecificUser','3') } { $WebServiceAccount = [string]$pool.processModel.userName }
        { $_ -in @('NetworkService','2') } { $WebServiceAccount = 'NT AUTHORITY\NETWORK SERVICE' }
        { $_ -in @('LocalService','1') } { $WebServiceAccount = 'NT AUTHORITY\LOCAL SERVICE' }
        { $_ -in @('LocalSystem','0') } { $WebServiceAccount = 'NT AUTHORITY\SYSTEM' }
        default { throw 'Cannot identify the IIS pool identity. Specify WebServiceAccount.' }
    }
}
# SqlServer role can also grant a specified remote web identity on the shared folder.
if ($WebServiceAccount) { $accounts += $WebServiceAccount }
$accounts = @($accounts | Select-Object -Unique)
if (!$accounts.Count) { throw 'No service identities found.' }
$rules = foreach ($account in $accounts) {
    $principal = New-Object Security.Principal.NTAccount($account)
    # Resolve all principals before creating directories or changing permissions.
    $sid = $principal.Translate([Security.Principal.SecurityIdentifier])
    [pscustomobject]@{Account=$account;Sid=$sid}
}
if (-not (Test-Path -LiteralPath $Directory)) { New-Item -ItemType Directory -Path $Directory -Force | Out-Null }
$item = Get-Item -LiteralPath $Directory
if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Backup destination must be a normal directory.' }
$acl = Get-Acl -LiteralPath $Directory
$auditFolder = Join-Path $env:ProgramData 'EIS-SqlBackup\acl-backups'
New-Item -ItemType Directory -Path $auditFolder -Force | Out-Null
$auditFile = Join-Path $auditFolder ((Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N') + '.json')
@{Directory=$Directory;Connection=$Connection;Computer=$env:COMPUTERNAME;Sddl=$acl.Sddl;Accounts=@($rules.Account)} | ConvertTo-Json | Set-Content -LiteralPath $auditFile -Encoding UTF8
foreach ($rule in $rules) {
    $access = New-Object Security.AccessControl.FileSystemAccessRule($rule.Sid, [Security.AccessControl.FileSystemRights]::Modify, ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit), [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow)
    $acl.AddAccessRule($access)
}
Set-Acl -LiteralPath $Directory -AclObject $acl
$updated = Get-Acl -LiteralPath $Directory
foreach ($rule in $rules) {
    $matches = @($updated.GetAccessRules($true,$false,[Security.Principal.SecurityIdentifier]) | Where-Object { $_.IdentityReference -eq $rule.Sid -and $_.AccessControlType -eq 'Allow' -and ($_.FileSystemRights -band [Security.AccessControl.FileSystemRights]::Modify) -eq [Security.AccessControl.FileSystemRights]::Modify })
    if (!$matches.Count) { throw "Cannot verify Modify grant for $($rule.Account)." }
}
Write-Output "Granted create/read/write/Modify permissions: $Directory ($Connection on $env:COMPUTERNAME)"
$rules | Select-Object Account
Write-Output "Previous ACL saved: $auditFile"
Write-Output 'Existing permissions are preserved. New child folders/files inherit these grants. Existing protected child ACLs are unchanged; verify Backup through SQL Manager.'
