$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$script:acl = New-Object Security.AccessControl.DirectorySecurity
$script:savedAcl = $null
$script:audit = $null
function Get-CimInstance { [pscustomobject]@{Name='MSSQLSERVER';StartName=$identity} }
function Import-Module {}
function Get-WebApplication { if ($script:missingPhysicalPath) { return [pscustomobject]@{Path='/eis-docs';applicationPool='EIS-DOCS'} }; [pscustomobject]@{PhysicalPath='D:\EIS-DOCS\app\public';applicationPool='EIS-DOCS'} }
function Get-Website { @() }
function Get-Item {
    param($LiteralPath)
    if ($LiteralPath -like 'IIS:*') { return [pscustomobject]@{processModel=[pscustomobject]@{identityType='SpecificUser';userName=$identity}} }
    return [pscustomobject]@{Attributes=[IO.FileAttributes]::Directory}
}
function Test-Path { $true }
function New-Item {}
function Get-Acl { $script:acl }
function Set-Acl { param($LiteralPath,$AclObject) $script:savedAcl=$LiteralPath; $script:acl=$AclObject }
function Set-Content { param($LiteralPath,$Encoding,[Parameter(ValueFromPipeline=$true)]$Value) process {$script:audit=$Value} }
$source = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Grant-SqlBackupPermissions.ps1')) -replace '(?m)^#Requires[^\r\n]*',''
$run = [scriptblock]::Create($source)
& $run -Connection local -Role SameServer | Out-Null
if ($script:savedAcl -ne 'D:\EIS-SQL-Backups') { throw 'Wrong ACL destination' }
if (-not $script:audit) { throw 'Original ACL was not saved' }
$grants=@($script:acl.GetAccessRules($true,$false,[Security.Principal.SecurityIdentifier]))
if (!$grants.Count -or ($grants[0].FileSystemRights -band [Security.AccessControl.FileSystemRights]::Modify) -ne [Security.AccessControl.FileSystemRights]::Modify) { throw 'Modify missing' }
if (($grants[0].InheritanceFlags -band [Security.AccessControl.InheritanceFlags]::ContainerInherit) -eq 0 -or ($grants[0].InheritanceFlags -band [Security.AccessControl.InheritanceFlags]::ObjectInherit) -eq 0) { throw 'Child inheritance missing' }
$script:missingPhysicalPath=$true
$script:manager=[pscustomobject]@{Sites=@([pscustomobject]@{Applications=@([pscustomobject]@{ApplicationPoolName='EIS-DOCS';VirtualDirectories=@{'/'=[pscustomobject]@{PhysicalPath='D:\EIS-DOCS\app\public\'}}})})}
$script:manager | Add-Member ScriptMethod Dispose {}
function Add-Type {}
$fallback=[scriptblock]::Create($source.Replace('New-Object Microsoft.Web.Administration.ServerManager','$script:manager'))
$script:savedAcl=$null
& $fallback -Connection local -Role SameServer | Out-Null
if ($script:savedAcl -ne 'D:\EIS-SQL-Backups') { throw 'IIS virtual-directory path fallback failed' }
$script:missingPhysicalPath=$false
$script:savedAcl=$null
& $run -Connection product -Role SqlServer -SqlServiceAccount $identity | Out-Null
if ($script:savedAcl -ne 'D:\EIS-SQL-Backups') { throw 'Production alias grant failed' }
foreach ($path in @('D:\','D:\EIS-DOCS\app\public\backups','C:\backup')) {
    $script:savedAcl=$null;$denied=$false
    try { & $run -Directory $path -Role SqlServer -SqlServiceAccount $identity | Out-Null } catch { $denied=$true }
    if (!$denied -or $script:savedAcl) { throw "Unexpected ACL mutation: $path" }
}
Write-Output 'PASS automatic SQL/IIS account discovery, local/product grants, ACL backup, child inheritance and path guards (mocked filesystem; no actual ACL changed)'
