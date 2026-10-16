#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string]$HostName = 'doc-eis.nmu.ac.th',
    [string]$ApplicationPath = '/eis-docs'
)
$ErrorActionPreference = 'Stop'
Import-Module WebAdministration
$sites = @(Get-Website | Where-Object {
    @($_.Bindings.Collection | Where-Object { $_.bindingInformation -like "*:$HostName" }).Count -gt 0
})
if ($sites.Count -ne 1) { throw "Expected one IIS site for $HostName. Found $($sites.Count)." }
$site = $sites[0]
$applications = @(Get-WebApplication -Site $site.Name | Where-Object { $_.Path -eq $ApplicationPath })
if ($applications.Count -ne 1) { throw "Cannot identify application $ApplicationPath on $($site.Name)." }
$application = $applications[0]
$publicPath = (Resolve-Path -LiteralPath ([Environment]::ExpandEnvironmentVariables($application.PhysicalPath))).ProviderPath
$projectRoot = Split-Path -Parent (Split-Path -Parent $publicPath)
if (!(Test-Path -LiteralPath (Join-Path $publicPath 'docMain\procurement-templates.php')) -or
    !(Test-Path -LiteralPath (Join-Path $projectRoot 'app\src\ProcurementTemplateStore.php'))) {
    throw 'The IIS physical path does not match app/public, or ProcurementTemplateStore.php has not been deployed.'
}
$poolName = [string]$application.ApplicationPool
$pool = Get-Item -LiteralPath "IIS:\AppPools\$poolName"
$identity = switch ([string]$pool.ProcessModel.IdentityType) {
    { $_ -in 'ApplicationPoolIdentity', '4' } { "IIS AppPool\$poolName" }
    { $_ -in 'SpecificUser', '3' } { [string]$pool.ProcessModel.UserName }
    { $_ -in 'LocalSystem', '0' } { 'NT AUTHORITY\SYSTEM' }
    { $_ -in 'LocalService', '1' } { 'NT AUTHORITY\LOCAL SERVICE' }
    { $_ -in 'NetworkService', '2' } { 'NT AUTHORITY\NETWORK SERVICE' }
    default { throw 'Unsupported application pool identity.' }
}
if ([string]::IsNullOrWhiteSpace($identity)) { throw 'Cannot identify the application pool account.' }
$accounts = @($identity)
# FastCGI may impersonate the configured anonymous user instead of the pool.
$location = "$($site.Name)$ApplicationPath"
$anonymous = Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $location -Filter 'system.webServer/security/authentication/anonymousAuthentication'
if ($anonymous.Enabled -and ![string]::IsNullOrWhiteSpace([string]$anonymous.UserName)) {
    $accounts += [string]$anonymous.UserName
}
$accounts = @($accounts | Select-Object -Unique)
foreach ($relative in @('storage\procurement-templates', 'storage\logs')) {
    $target = Join-Path $projectRoot $relative
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    foreach ($account in $accounts) {
        & icacls.exe $target /grant "${account}:(OI)(CI)M"
        if ($LASTEXITCODE -ne 0) { throw "Failed to grant Modify on $target to $account." }
    }
    Write-Host "Configured: $target"
}
Write-Host "Site: $($site.Name); Application pool: $poolName; Accounts: $($accounts -join ', ')"
Write-Host 'Retry saving the template. If it still fails, read storage/logs/php-error.log on this server.'
