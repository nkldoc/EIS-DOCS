#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][ValidateSet('SqlServer','WebServer','SameServer')][string]$Mode,
    [ValidateSet('local','production','product')][string]$Connection = 'production',
    [string]$SqlDirectory = 'D:\EIS-SQL-Backups',
    [string]$WebDirectory,
    [string]$ShareName = 'EISSqlBackup$',
    [string]$SqlServiceAccount,
    [string]$WebServiceAccount,
    [string]$ApplicationPool,
    [string]$SqlInstanceService = 'MSSQLSERVER',
    [PSCredential]$StorageCredential,
    [string]$AppDirectory = 'D:\EIS-DOCS\app'
)
$ErrorActionPreference = 'Stop'
if ($Connection -eq 'product') { $Connection = 'production' }
if ($Mode -eq 'SameServer') {
    $grantScript = Join-Path $PSScriptRoot 'Grant-SqlBackupPermissions.ps1'
    & $grantScript -Connection $Connection -Role SameServer -Directory $SqlDirectory -AppDirectory $AppDirectory -ApplicationPool $ApplicationPool -SqlInstanceService $SqlInstanceService -SqlServiceAccount $SqlServiceAccount -WebServiceAccount $WebServiceAccount
    $WebDirectory = $SqlDirectory
}
if ($SqlDirectory -notmatch '^[A-Za-z]:[\\/]') { throw 'SqlDirectory must be an absolute local path on the SQL Server.' }
$SqlDirectory = [IO.Path]::GetFullPath($SqlDirectory).TrimEnd('\','/')
if ($SqlDirectory -match '^[A-Za-z]:$') { throw 'Use a dedicated backup directory, not a drive root.' }
if ($Mode -eq 'SqlServer') {
    if (-not $SqlServiceAccount) {
        $service = Get-CimInstance Win32_Service | Where-Object { $_.Name -eq $SqlInstanceService } | Select-Object -First 1
        if (-not $service) { throw "SQL service $SqlInstanceService not found. Specify SqlInstanceService or SqlServiceAccount." }
        $SqlServiceAccount = $service.StartName
        if ($SqlServiceAccount -eq 'LocalSystem') { $SqlServiceAccount = 'NT AUTHORITY\SYSTEM' }
    }
    if (-not $WebServiceAccount) { throw 'Specify WebServiceAccount: the actual network identity used by doc-eis to access the SQL share.' }
    if ($WebServiceAccount -match '^(Everyone|Guest|Anonymous Logon|Authenticated Users|Users)$') { throw 'Use a specific account for the web service.' }
    if ($ShareName -notmatch '^[A-Za-z0-9_-]+\$?$') { throw 'Invalid share name.' }
    New-Item -ItemType Directory -Path $SqlDirectory -Force | Out-Null
    # SQL writes .bak; the web identity creates request directories and ZIP files.
    & icacls.exe $SqlDirectory /grant "${SqlServiceAccount}:(OI)(CI)M" "${WebServiceAccount}:(OI)(CI)M"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot grant NTFS permissions.' }
    $share = Get-SmbShare -Name $ShareName -ErrorAction SilentlyContinue
    if ($share) {
        if ([IO.Path]::GetFullPath($share.Path).TrimEnd('\','/') -ne $SqlDirectory) { throw 'The existing share points to another directory; it was not changed.' }
        Grant-SmbShareAccess -Name $ShareName -AccountName $WebServiceAccount -AccessRight Change -Force | Out-Null
    } else {
        New-SmbShare -Name $ShareName -Path $SqlDirectory -ChangeAccess $WebServiceAccount -FolderEnumerationMode AccessBased | Out-Null
    }
    Write-Output "SQL backup folder ready: $SqlDirectory"
    Write-Output ('Web UNC path: \\' + $env:COMPUTERNAME + '\' + $ShareName)
    Write-Output 'Next: run WebServer mode ON the web server, using its actual application directory and this UNC path.'
    return
}
if (-not $WebDirectory) { throw 'Specify WebDirectory: the UNC path to the SAME SQL backup folder, or the same local folder if SQL and IIS run on one server.' }
if ($WebDirectory -notmatch '^(?:[A-Za-z]:[\\/]|\\\\[^\\]+\\[^\\]+)') { throw 'WebDirectory must be absolute or UNC.' }
if (-not (Test-Path -LiteralPath (Join-Path $AppDirectory 'src\SqlBackup.php'))) { throw 'AppDirectory must be the installed EIS-DOCS app directory containing src\SqlBackup.php.' }
$publicRoot = [IO.Path]::GetFullPath((Join-Path $AppDirectory 'public')).TrimEnd('\','/')
$resolvedWeb = [IO.Path]::GetFullPath($WebDirectory).TrimEnd('\','/')
if ($resolvedWeb -eq $publicRoot -or $resolvedWeb.StartsWith($publicRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Backup storage must be outside public.' }
if ($WebServiceAccount -and $WebDirectory -match '^[A-Za-z]:') {
    if (-not (Test-Path -LiteralPath $WebDirectory)) { New-Item -ItemType Directory -Path $WebDirectory -Force | Out-Null }
    & icacls.exe $WebDirectory /grant "${WebServiceAccount}:(OI)(CI)M"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot grant web NTFS permissions.' }
}
# A supplied credential verifies the backup service account rather than the setup administrator.
$probeScript = Join-Path $PSScriptRoot 'Invoke-SqlBackupStorageProbe.ps1'
if (Test-Path -LiteralPath $probeScript) {
    . $probeScript
    Invoke-SqlBackupStorageProbe -Directory $WebDirectory -Credential $StorageCredential
} else {
    if ($StorageCredential) { throw 'Invoke-SqlBackupStorageProbe.ps1 is required to verify service-account access.' }
    if (-not (Test-Path -LiteralPath $WebDirectory)) { New-Item -ItemType Directory -Path $WebDirectory -Force | Out-Null }
    $probe = Join-Path $WebDirectory ('.setup-' + [guid]::NewGuid().ToString('N') + '.tmp')
    try { [IO.File]::WriteAllText($probe, 'backup-storage-test'); if ([IO.File]::ReadAllText($probe) -ne 'backup-storage-test') { throw 'Storage verification failed.' } }
    finally { if (Test-Path -LiteralPath $probe) { Remove-Item -LiteralPath $probe } }
}
function PhpString([string]$value) { return "'" + $value.Replace('\','\\').Replace("'","\'") + "'" }
$configDirectory = Join-Path $AppDirectory 'config'
if (-not (Test-Path -LiteralPath $configDirectory)) { throw 'Application config directory missing.' }
$configFile = Join-Path $configDirectory ('backup.' + $Connection + '.php')
if (Test-Path -LiteralPath $configFile) {
    Copy-Item -LiteralPath $configFile -Destination ($configFile + '.' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.' + [guid]::NewGuid().ToString('N') + '.bak')
}
$content = "<?php`r`ndeclare(strict_types=1);`r`nreturn ['sql_directory'=>" + (PhpString $SqlDirectory) + ", 'web_directory'=>" + (PhpString $WebDirectory) + "];`r`n"
[IO.File]::WriteAllText($configFile, $content, (New-Object Text.UTF8Encoding($false)))
Write-Output "Configured $Connection backup: $configFile"
Write-Output 'Open SQL Manager, refresh the database list, enter a folder/name or leave blank, then press Create Backup ZIP.'
Write-Output 'Ensure PHP extension zip is enabled. The IIS identity must have share/NTFS read-write access; SQL service must have NTFS write access. Profile-specific environment variables override this file.'
