#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$AppDirectory='D:\EIS-DOCS\app',
    [string]$SessionDirectory,
    [ValidateSet('local','production')][string]$Connection='production'
)
$ErrorActionPreference='Stop'
$loader=Join-Path $PSScriptRoot 'Import-SqlBackupIisAdministration.ps1'
. $loader
Import-SqlBackupIisAdministration
if (!$SessionDirectory) { $SessionDirectory=Join-Path (Split-Path $AppDirectory -Parent) 'storage\php-sessions' }
$sessionPath=[IO.Path]::GetFullPath($SessionDirectory).TrimEnd('\','/')
$public=[IO.Path]::GetFullPath((Join-Path $AppDirectory 'public')).TrimEnd('\','/')
if ($sessionPath -notmatch '^[A-Za-z]:[\\/].+' -or $sessionPath -eq $public -or $sessionPath.StartsWith($public+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Use a private local session directory outside public.' }
$bootstrap=Join-Path $AppDirectory 'src\bootstrap.php'
if (![IO.File]::ReadAllText($bootstrap).Contains('EIS_DOCS_SESSION_DIR')) { throw 'Install the updated bootstrap.php and login.php first.' }
$manager=New-Object Microsoft.Web.Administration.ServerManager
try {
    $targets=@(foreach ($site in $manager.Sites) {
        foreach ($app in $site.Applications) {
            $root=$app.VirtualDirectories['/']
            if ($root -and [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($root.PhysicalPath)).TrimEnd('\','/') -eq $public) {
                [pscustomobject]@{Location=($site.Name+$app.Path).TrimEnd('/');Pool=$app.ApplicationPoolName}
            }
        }
    })
    $names=@($targets.Pool | Select-Object -Unique)
    if ($names.Count -ne 1) { throw 'Cannot find exactly one EIS-DOCS pool.' }
    $pool=$manager.ApplicationPools[$names[0]]
    $poolSid='IIS APPPOOL\'+$names[0]
    $accounts=@($poolSid)
    switch ([string]$pool.ProcessModel.IdentityType) {
        {$_ -in @('SpecificUser','3')} {$accounts+=$pool.ProcessModel.UserName}
        {$_ -in @('NetworkService','2')} {$accounts+='NT AUTHORITY\NETWORK SERVICE'}
        {$_ -in @('LocalService','1')} {$accounts+='NT AUTHORITY\LOCAL SERVICE'}
        {$_ -in @('LocalSystem','0')} {$accounts+='NT AUTHORITY\SYSTEM'}
    }
    if (!(Test-Path -LiteralPath $sessionPath)) {New-Item -ItemType Directory -Path $sessionPath -Force | Out-Null}
    foreach ($account in ($accounts | Select-Object -Unique)) {
        & icacls.exe $sessionPath /grant "${account}:(OI)(CI)M"
        if ($LASTEXITCODE -ne 0) {throw 'Cannot grant session directory access.'}
    }
    $appcmd=Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
    $rollback='Before-EIS-SessionRepair-'+[guid]::NewGuid().ToString('N')
    & $appcmd add backup $rollback
    if ($LASTEXITCODE -ne 0) {throw 'Cannot save IIS rollback; authentication settings unchanged.'}
    $configuration=$manager.GetApplicationHostConfiguration()
    foreach ($target in $targets) {
        $anonymous=$configuration.GetSection('system.webServer/security/authentication/anonymousAuthentication',$target.Location)
        if ($anonymous.GetAttributeValue('enabled')) {
            # PHP FastCGI impersonation must use this app pool identity rather than shared IUSR.
            $anonymous.SetAttributeValue('userName','')
            $anonymous.SetAttributeValue('password','')
        }
    }
    $file=Join-Path $AppDirectory ('config\session.'+$Connection+'.php')
    if (Test-Path -LiteralPath $file) {Copy-Item -LiteralPath $file -Destination ($file+'.'+[guid]::NewGuid().ToString('N')+'.bak')}
    $quoted=$sessionPath.Replace('\','\\').Replace("'","\'")
    [IO.File]::WriteAllText($file,"<?php`r`ndeclare(strict_types=1);`r`nreturn ['directory'=>'$quoted'];`r`n",(New-Object Text.UTF8Encoding($false)))
    $manager.CommitChanges()
    & $appcmd recycle apppool "/apppool.name:$($names[0])"
    if ($LASTEXITCODE -ne 0) {throw 'Session settings saved; recycle failed. Check IIS pool state.'}
    Write-Output "Session repair configured: $sessionPath; pool: $($names[0]); IIS rollback: $rollback"
    Write-Output 'Close old login tabs. Open a new private browser window and sign in with the application user account.'
    Write-Output 'No existing sessions were deleted. Existing logins may need to sign in again because the session directory changed.'
} finally {$manager.Dispose()}
