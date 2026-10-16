#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][ValidateSet('SqlServer','WebServer')][string]$Mode,
    [string]$AccountName = 'eis_backup',
    [Security.SecureString]$Password,
    [string]$ApplicationPool,
    [string]$AppDirectory = 'D:\EIS-DOCS\app',
    [string]$SqlDirectory = 'D:\EIS-SQL-Backups',
    [string]$WebDirectory = '\\192.168.201.3\EISSqlBackup$',
    [string]$SqlInstanceService = 'MSSQLSERVER',
    [string]$PhpExecutable = 'C:\php\php.exe',
    [string]$SessionDirectory,
    [switch]$CreateDedicatedPool,
    [string]$DedicatedPoolName = 'EIS-DOCS-Backup',
    [switch]$UpdateExistingAccount
)
$ErrorActionPreference = 'Stop'
if ([Environment]::Is64BitOperatingSystem -and ![Environment]::Is64BitProcess) { throw "Use 64-bit Administrator PowerShell on $env:COMPUTERNAME. From this 32-bit process launch $env:windir\Sysnative\WindowsPowerShell\v1.0\powershell.exe." }
if ($AccountName -notmatch '^[A-Za-z][A-Za-z0-9_-]{0,19}$') { throw 'Use a dedicated service account name (1-20 ASCII letters/digits/_/-).' }
$setupScript = Join-Path $PSScriptRoot 'Setup-SqlBackup.ps1'
if (-not (Test-Path -LiteralPath $setupScript)) { throw 'Keep Setup-SqlBackup.ps1 in the same scripts folder.' }
$account = $env:COMPUTERNAME + '\' + $AccountName
$description = 'EIS-DOCS SQL backup service account'
$existing = Get-LocalUser -Name $AccountName -ErrorAction SilentlyContinue
if ($existing -and (!$UpdateExistingAccount -or $existing.Description -ne $description)) {
    throw 'Account already exists. Only a dedicated account created by this script can be updated using -UpdateExistingAccount.'
}
# Validate IIS before creating/resetting the account on the web server.
$manager = $null
if ($Mode -eq 'WebServer') {
    if (-not (Test-Path -LiteralPath (Join-Path $AppDirectory 'src\SqlBackup.php'))) { throw 'Install the backup application files before setting the web account.' }
    $iisLoader = Join-Path $PSScriptRoot 'Import-SqlBackupIisAdministration.ps1'
    if (-not (Test-Path -LiteralPath $iisLoader)) { throw 'Copy Import-SqlBackupIisAdministration.ps1 from the latest scripts bundle.' }
    . $iisLoader
    Import-SqlBackupIisAdministration
    $manager = New-Object Microsoft.Web.Administration.ServerManager
    $public = [IO.Path]::GetFullPath((Join-Path $AppDirectory 'public')).TrimEnd('\','/')
    if (-not $ApplicationPool) {
        $candidates = @(foreach ($site in $manager.Sites) {
            foreach ($app in $site.Applications) {
                $root = $app.VirtualDirectories['/']
                if ($root -and [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($root.PhysicalPath)).TrimEnd('\','/') -eq $public) {
                    $app.ApplicationPoolName
                }
            }
        }) | Select-Object -Unique
        $candidates = @($candidates)
        if ($candidates.Count -ne 1) { $manager.Dispose(); throw 'Cannot identify one pool for the EIS-DOCS public directory. Run appcmd list app and list vdir, then specify ApplicationPool and AppDirectory.' }
        $ApplicationPool = $candidates[0]
        Write-Output "Detected EIS-DOCS pool: $ApplicationPool"
    }
    $pool = $manager.ApplicationPools[$ApplicationPool]
    if (-not $pool) {
        $names = @($manager.ApplicationPools | ForEach-Object { $_.Name }) -join ', '
        $manager.Dispose(); throw "IIS pool '$ApplicationPool' not found. Existing pools: $names. Omit ApplicationPool to detect the actual EIS-DOCS pool."
    }
    $sharedPool = $false
    $matchingApps = @(foreach ($site in $manager.Sites) {
        foreach ($app in $site.Applications) {
            if ($app.ApplicationPoolName -eq $ApplicationPool) {
                $root = $app.VirtualDirectories['/']
                if (!$root -or [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($root.PhysicalPath)).TrimEnd('\','/') -ne $public) {
                    $sharedPool = $true
                    continue
                }
                $app
            }
        }
    })
    if (!$matchingApps.Count) { $manager.Dispose(); throw 'The pool does not run the specified EIS-DOCS public directory.' }
    if ($sharedPool) {
        if (!$CreateDedicatedPool) { $manager.Dispose(); throw 'This pool also runs another application. Run again with -CreateDedicatedPool to move only EIS-DOCS to a separate pool.' }
        if ($DedicatedPoolName -notmatch '^[A-Za-z0-9_-]{1,80}$' -or $manager.ApplicationPools[$DedicatedPoolName]) { $manager.Dispose(); throw 'Dedicated pool name is invalid or already exists. Choose a new DedicatedPoolName.' }
        $copyScript = Join-Path $PSScriptRoot 'Copy-SqlBackupIisPool.ps1'
        if (-not (Test-Path -LiteralPath $copyScript)) { $manager.Dispose(); throw 'Copy-SqlBackupIisPool.ps1 is required.' }
        . $copyScript
        $dedicatedPool = $manager.ApplicationPools.Add($DedicatedPoolName)
        Copy-SqlBackupIisPoolElement -Source $pool -Target $dedicatedPool -PoolRoot
        foreach ($app in $matchingApps) { $app.ApplicationPoolName = $DedicatedPoolName }
        $pool = $dedicatedPool
        $ApplicationPool = $DedicatedPoolName
        Write-Output "Prepared dedicated pool: $DedicatedPoolName (only EIS-DOCS applications moved; shared pool unchanged)"
    }
}
try {
    if (-not $Password) { $Password = Read-Host 'Enter the SAME backup account password on SQL Server and doc-eis' -AsSecureString }
    if (!$Password.Length) { throw 'Password cannot be empty.' }
    if ($existing) {
        Set-LocalUser -Name $AccountName -Password $Password -PasswordNeverExpires $true
        Enable-LocalUser -Name $AccountName
    } else {
        New-LocalUser -Name $AccountName -Password $Password -Description $description -AccountNeverExpires -PasswordNeverExpires -UserMayNotChangePassword | Out-Null
    }
    if ($Mode -eq 'SqlServer') {
        & $setupScript -Mode SqlServer -Connection production -SqlDirectory $SqlDirectory -WebServiceAccount $account -SqlInstanceService $SqlInstanceService
        Write-Output "Created backup share account: $account"
        Write-Output 'Next: run WebServer mode on doc-eis with the SAME AccountName and password.'
        return
    }
    $iisGroup = Get-LocalGroup -SID 'S-1-5-32-568'
    if (-not (Get-LocalGroupMember -Group $iisGroup | Where-Object { $_.SID -eq (Get-LocalUser -Name $AccountName).SID })) {
        Add-LocalGroupMember -Group $iisGroup -Member $account
    }
    & icacls.exe $AppDirectory /grant "${account}:(OI)(CI)RX"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot grant app read access.' }
    # Session files, audit/logs, documents and other app-owned storage remain writable.
    $storage = Join-Path (Split-Path $AppDirectory -Parent) 'storage'
    if (-not (Test-Path -LiteralPath $storage)) { New-Item -ItemType Directory -Path $storage -Force | Out-Null }
    & icacls.exe $storage /grant "${account}:(OI)(CI)M"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot grant storage write access.' }
    if (-not $SessionDirectory -and (Test-Path -LiteralPath $PhpExecutable)) {
        $sessionHelper = Join-Path $PSScriptRoot 'Get-SqlBackupPhpSessionDirectory.ps1'
        if (-not (Test-Path -LiteralPath $sessionHelper)) { throw 'Copy Get-SqlBackupPhpSessionDirectory.ps1 from the latest scripts bundle.' }
        . $sessionHelper
        $SessionDirectory = Get-SqlBackupPhpSessionDirectory -PhpExecutable $PhpExecutable
    }
    if ($SessionDirectory) {
        if ($SessionDirectory -notmatch '^[A-Za-z]:[\\/].+') { throw 'SessionDirectory must be a dedicated absolute local directory.' }
        $sessionPath = [IO.Path]::GetFullPath($SessionDirectory).TrimEnd('\','/')
        if ($sessionPath -match '^[A-Za-z]:$') { throw 'SessionDirectory cannot be a drive root.' }
        if (-not (Test-Path -LiteralPath $sessionPath)) { New-Item -ItemType Directory -Path $sessionPath -Force | Out-Null }
        & icacls.exe $sessionPath /grant "${account}:(OI)(CI)M"
        if ($LASTEXITCODE -ne 0) { throw 'Cannot grant PHP session access.' }
    }
    # Probe the share with the exact service credentials, before committing an IIS identity change.
    $storageCredential = New-Object Management.Automation.PSCredential($account,$Password)
    & $setupScript -Mode WebServer -Connection production -SqlDirectory $SqlDirectory -WebDirectory $WebDirectory -AppDirectory $AppDirectory -StorageCredential $storageCredential
    $appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
    $backupName = 'Before-EIS-BackupAccount-' + [guid]::NewGuid().ToString('N')
    & $appcmd add backup $backupName
    if ($LASTEXITCODE -ne 0) { throw 'Cannot save IIS rollback backup; pool identity was not changed.' }
    $buffer = [IntPtr]::Zero
    try {
        $buffer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password)
        $pool.ProcessModel.IdentityType = [Microsoft.Web.Administration.ProcessModelIdentityType]::SpecificUser
        $pool.ProcessModel.UserName = $account
        # Use IIS API, never plaintext command-line arguments or an application config file.
        $pool.ProcessModel.Password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($buffer)
        $manager.CommitChanges()
    } finally {
        if ($buffer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($buffer) }
    }
    $poolState = ((& $appcmd list apppool "/apppool.name:$ApplicationPool" /text:state) -join '').Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Pool identity saved; cannot read pool state. Check IIS before continuing.' }
    if ($poolState -eq 'Stopped') {
        & $appcmd start apppool "/apppool.name:$ApplicationPool"
        if ($LASTEXITCODE -ne 0) { throw 'Pool identity saved; start failed. Check the IIS/WAS event log.' }
    } elseif ($poolState -eq 'Started') {
        & $appcmd recycle apppool "/apppool.name:$ApplicationPool"
        if ($LASTEXITCODE -ne 0) { throw 'Pool identity saved; recycle failed. Check the IIS/WAS event log.' }
    } elseif ($poolState -ne 'Starting') {
        throw "Pool identity saved; pool state is '$poolState'. Wait for IIS to finish the state change, then start the pool."
    }
    Write-Output "Backup account ready: $account; IIS pool: $ApplicationPool"
    Write-Output "IIS rollback backup: $backupName"
    Write-Output 'Select Production in SQL Manager and test Backup. Check app login, document storage and Messenger after the pool identity change.'
} finally {
    if ($manager) { $manager.Dispose() }
    $Password = $null
}
