#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$SuppliesPath = 'D:\EIS-ERP\public\supplies',
    [string]$ApplicationName = 'eis.nmu.ac.th/supplies',
    [string]$PoolName = 'EIS-Supplies-Messenger',
    [string]$SharedDirectory = '\\NMU-EIS-DOC\EIS-Presence$'
)
$ErrorActionPreference = 'Stop'
if ($env:COMPUTERNAME -ne 'NMU-EIS') { throw 'Run this script on NMU-EIS (Supplies server).' }
$helper = Join-Path $PSScriptRoot 'Configure-SuppliesMessengerShare.ps1'
if (-not (Test-Path -LiteralPath $helper)) { throw 'Place Configure-SuppliesMessengerShare.ps1 beside this script.' }
if (-not (Get-LocalUser -Name 'EISMessenger' -ErrorAction SilentlyContinue)) { throw 'Create the local EISMessenger account with the same password as doc-eis first.' }
$credential = Get-Credential -UserName "$env:COMPUTERNAME\EISMessenger" -Message 'Enter the EISMessenger password used on doc-eis'
if (-not $credential -or $credential.UserName -ine "$env:COMPUTERNAME\EISMessenger") { throw 'Use the local EISMessenger account.' }
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
$backupName = 'Before-Supplies-Messenger-' + [guid]::NewGuid().ToString('N')
& $appcmd add backup $backupName
if ($LASTEXITCODE -ne 0) { throw 'IIS backup failed.' }
Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll')
$manager = New-Object Microsoft.Web.Administration.ServerManager
try {
    $siteName = $ApplicationName.Split('/')[0]
    $appPath = $ApplicationName.Substring($siteName.Length)
    $app = $manager.Sites[$siteName].Applications[$appPath]
    if (-not $app) { throw 'Supplies IIS application missing.' }
    foreach ($site in $manager.Sites) {
        foreach ($otherApp in $site.Applications) {
            if ($otherApp.ApplicationPoolName -eq $PoolName -and ($site.Name + $otherApp.Path) -ne $ApplicationName) { throw 'Target pool is used by another application.' }
        }
    }
    $oldPool = $manager.ApplicationPools[$app.ApplicationPoolName]
    $pool = $manager.ApplicationPools[$PoolName]
    if (-not $pool) { $pool = $manager.ApplicationPools.Add($PoolName) }
    $pool.ManagedRuntimeVersion = $oldPool.ManagedRuntimeVersion
    $pool.ManagedPipelineMode = $oldPool.ManagedPipelineMode
    $pool.Enable32BitAppOnWin64 = $oldPool.Enable32BitAppOnWin64
    foreach ($entry in $oldPool.GetCollection('environmentVariables')) {
        $collection = $pool.GetCollection('environmentVariables')
        $name = [string]$entry['name']
        $existing = @($collection | Where-Object { [string]$_['name'] -eq $name })
        if (-not $existing) {
            $item = $collection.CreateElement('add'); $item['name'] = $name; $item['value'] = $entry['value']; $collection.Add($item)
        }
    }
    $pool.ProcessModel.IdentityType = [Microsoft.Web.Administration.ProcessModelIdentityType]::SpecificUser
    $pool.ProcessModel.UserName = $credential.UserName
    $pool.ProcessModel.Password = $credential.GetNetworkCredential().Password
    $pool.ProcessModel.LoadUserProfile = $true
    # PHP impersonation must use the pool identity rather than anonymous IUSR.
    $anonymous = $manager.GetApplicationHostConfiguration().GetSection('system.webServer/security/authentication/anonymousAuthentication', $ApplicationName)
    $anonymous['userName'] = ''
    $anonymous['password'] = ''
    $app.ApplicationPoolName = $PoolName
    & icacls.exe $SuppliesPath /grant "$($credential.UserName):(OI)(CI)RX" /T /C
    if ($LASTEXITCODE -ne 0) { throw 'Cannot grant website read access.' }
    $sessionDirectory = Join-Path (Split-Path (Split-Path $SuppliesPath -Parent) -Parent) 'storage\sessions'
    New-Item -ItemType Directory -Path $sessionDirectory -Force | Out-Null
    & icacls.exe $sessionDirectory /grant "$($credential.UserName):(OI)(CI)M" /T /C
    if ($LASTEXITCODE -ne 0) { throw 'Cannot grant session directory access.' }
    & $helper -SuppliesPath $SuppliesPath -SharedDirectory $SharedDirectory
    $manager.CommitChanges()
    Write-Host "Supplies now uses dedicated pool $PoolName; other applications retain their pools."
    Write-Host "IIS backup: $backupName"
    Write-Host 'Refresh Supplies and test Messenger plus document uploads. Custom session/upload directories may require Modify permission for EISMessenger.'
} catch {
    Write-Host "IIS backup available: $backupName. Error: $($_.Exception.Message)"
    throw
} finally { $manager.Dispose(); $credential = $null }
