#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$ProjectPath = 'D:\EIS-DOCS',
    [string]$ApplicationName,
    [string]$PhpIdentity
)
$ErrorActionPreference = 'Stop'
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
if (-not (Test-Path -LiteralPath $appcmd)) { throw 'IIS appcmd is missing.' }
$nodeConfig = Get-Content -LiteralPath (Join-Path $ProjectPath 'app\services\presence\server.config.json') -Raw | ConvertFrom-Json
$storage = (Resolve-Path -LiteralPath $nodeConfig.directory).Path
$public = [IO.Path]::GetFullPath((Join-Path $ProjectPath 'app\public')).TrimEnd('\') + '\'
if (($storage.TrimEnd('\') + '\').StartsWith($public, [StringComparison]::OrdinalIgnoreCase)) { throw 'Storage must be outside public web directory.' }
$rawApps = & $appcmd list app /xml
if ($LASTEXITCODE -ne 0) { throw 'Cannot read IIS applications.' }
[xml]$apps = $rawApps -join "`n"
$candidates = @($apps.appcmd.APP | Where-Object {
    if ($ApplicationName) { $_.GetAttribute('APP.NAME') -eq $ApplicationName }
    else { $_.GetAttribute('path') -eq '/eis-docs' }
})
if ($candidates.Count -ne 1) {
    & $appcmd list app
    throw 'Specify -ApplicationName with the exact IIS application name shown above.'
}
$application = $candidates[0].GetAttribute('APP.NAME')
$pool = $candidates[0].GetAttribute('APPPOOL.NAME')
$identityType = & $appcmd list apppool $pool /text:processModel.identityType
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect application pool identity.' }
$identity = switch (($identityType -join '').Trim()) {
    'ApplicationPoolIdentity' { "IIS AppPool\$pool" }
    'LocalSystem' { '*S-1-5-18' }
    'LocalService' { '*S-1-5-19' }
    'NetworkService' { '*S-1-5-20' }
    'SpecificUser' {
        $account = & $appcmd list apppool $pool /text:processModel.userName
        if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect custom pool account.' }
        ($account -join '').Trim()
    }
    default { throw "Unsupported pool identity: $identityType" }
}
if (-not $identity) { throw 'Empty pool identity.' }
# PHP FastCGI impersonation may use the anonymous user instead of the pool.
if (-not $PhpIdentity) {
    # /xml wraps section data in appcmd's reporting format. Read the actual
    # configuration XML so anonymousAuthentication can be located directly.
    $anonymousXml = & $appcmd list config $application /section:system.webServer/security/authentication/anonymousAuthentication
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect anonymous identity; specify -PhpIdentity.' }
    [xml]$anonymousDoc = $anonymousXml -join "`n"
    $anonymous = $anonymousDoc.SelectSingleNode('//anonymousAuthentication')
    if (-not $anonymous) { throw 'Anonymous configuration missing. Inspect IIS Anonymous Authentication > Edit and specify its account with -PhpIdentity.' }
    if ($anonymous.GetAttribute('enabled') -ne 'false') {
        $PhpIdentity = $anonymous.GetAttribute('userName')
        if (-not $anonymous.HasAttribute('userName')) { $PhpIdentity = '*S-1-5-17' }
    }
}
$aclBackup = Join-Path $ProjectPath ('storage\config-backups\presence-acl-' + [guid]::NewGuid().ToString('N') + '.txt')
New-Item -ItemType Directory -Path (Split-Path $aclBackup -Parent) -Force | Out-Null
& icacls.exe $storage /save $aclBackup /T /C
if ($LASTEXITCODE -ne 0) { throw 'ACL backup failed; no permissions changed.' }
foreach ($dir in @('logins','revoked')) { New-Item -ItemType Directory -Path (Join-Path $storage $dir) -Force | Out-Null }
foreach ($account in (@($identity, $PhpIdentity) | Where-Object { $_ } | Select-Object -Unique)) {
    & icacls.exe $storage /grant "${account}:(OI)(CI)M" /T /C
    if ($LASTEXITCODE -ne 0) { throw "Failed to grant Modify to $account" }
}
Write-Host "Application: $application; pool: $pool"
Write-Host "Storage: $storage; ACL backup: $aclBackup"
Write-Host 'Permissions updated. Retry login; if the same error remains, inspect PHP fopen/flock warnings and confirm PHP uses this storage directory.'
