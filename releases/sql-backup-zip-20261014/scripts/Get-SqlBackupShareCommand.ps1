#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$ScriptDirectory = 'D:\EIS-DOCS\scripts',
    [string]$AppDirectory = 'D:\EIS-DOCS\app',
    [string]$SqlServer = '192.168.201.3'
)
$ErrorActionPreference = 'Stop'
$computer = Get-CimInstance Win32_ComputerSystem
if (-not $computer.PartOfDomain) { throw 'doc-eis is not joined to an AD domain. Passwordless machine-account sharing requires a domain/trust. Use the dedicated account/password method for a workgroup.' }
$identity = $computer.Domain + '\' + $computer.Name + '$'
function PsQuoted([string]$value) { return "'" + $value.Replace("'","''") + "'" }
$setup = Join-Path $ScriptDirectory 'Setup-SqlBackup.ps1'
Write-Output "Run the following ON SQL Server $SqlServer in Administrator PowerShell (copy the scripts folder there first):"
Write-Output ("& " + (PsQuoted $setup) + " -Mode SqlServer -Connection production -SqlDirectory 'D:\EIS-SQL-Backups' -ShareName 'EISSqlBackup$' -WebServiceAccount " + (PsQuoted $identity))
Write-Output ''
Write-Output 'Then run the following ON doc-eis in Administrator PowerShell:'
Write-Output ("& " + (PsQuoted $setup) + " -Mode WebServer -Connection production -SqlDirectory 'D:\EIS-SQL-Backups' -WebDirectory " + (PsQuoted ('\\' + $SqlServer + '\EISSqlBackup$')) + " -AppDirectory " + (PsQuoted $AppDirectory))
Write-Output ''
Write-Output "Share identity: $identity"
Write-Output 'Keep the IIS pool on ApplicationPoolIdentity or NetworkService to use the web machine identity. If it uses SpecificUser, grant the actual domain service identity instead. Both servers must accept authentication from this domain; share/NTFS access and TCP 445 must be available.'
