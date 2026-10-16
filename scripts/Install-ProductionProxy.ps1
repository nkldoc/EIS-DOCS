#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$SiteName = 'doc-eis.nmu.ac.th',
    [string]$ProjectPath = 'D:\EIS-DOCS'
)
$ErrorActionPreference = 'Stop'
if (![Environment]::Is64BitProcess) { throw 'Run Windows PowerShell 64-bit as Administrator.' }
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
$repair = Join-Path $PSScriptRoot 'Repair-ProductionProxy.ps1'
if (!(Test-Path -LiteralPath $appcmd) -or !(Test-Path -LiteralPath $repair)) { throw 'IIS and Repair-ProductionProxy.ps1 beside this script are required.' }
$public = (Resolve-Path -LiteralPath (Join-Path $ProjectPath 'app\public')).Path
$work = Join-Path $ProjectPath 'storage\iis-install'
New-Item -ItemType Directory -Path $work -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
& $appcmd add backup "EIS-DOCS-BeforeInstall-$stamp"
if ($LASTEXITCODE -ne 0) { throw 'IIS configuration backup failed.' }
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
function Has-Section([string]$Section) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $null = & $appcmd list config "/section:$Section" 2>&1
    $ok = $LASTEXITCODE -eq 0
    $ErrorActionPreference = $previous
    return $ok
}
function Install-MicrosoftMsi([string]$Name, [string]$Url) {
    $file = Join-Path $work "$Name.msi"
    if (!(Test-Path -LiteralPath $file)) { Invoke-WebRequest -UseBasicParsing -Uri $Url -OutFile $file -TimeoutSec 180 }
    $signature = Get-AuthenticodeSignature -LiteralPath $file
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
        throw "Microsoft signature verification failed: $file. Do not run this installer."
    }
    $log = Join-Path $work "$Name-$stamp.log"
    $process = Start-Process msiexec.exe -ArgumentList "/i `"$file`" /qn /norestart /L*v `"$log`"" -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -notin @(0,3010)) { throw "$Name failed ($($process.ExitCode)). See $log" }
    if ($process.ExitCode -eq 3010) { throw "$Name installed but Windows requires a restart. Restart during your maintenance window, then rerun this script." }
}
if (!(Has-Section 'system.webServer/rewrite/rules')) {
    Install-MicrosoftMsi 'URL-Rewrite' 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi'
}
if (!(Has-Section 'system.webServer/proxy')) {
    Install-MicrosoftMsi 'ARR' 'https://download.microsoft.com/download/E/9/8/E9849D6A-020E-47E4-9FD0-A023E99B54EB/requestRouter_amd64.msi'
}
if (!(Has-Section 'system.webServer/proxy')) { throw 'ARR proxy section is still missing. Inspect the MSI log.' }
Import-Module ServerManager
$feature = Get-WindowsFeature Web-WebSockets
if (!$feature.Installed) {
    $result = Install-WindowsFeature Web-WebSockets
    if (!$result.Success) { throw 'WebSocket Protocol installation failed.' }
    if ([string]$result.RestartNeeded -eq 'Yes') { throw 'WebSocket installed. Restart Windows during your maintenance window, then rerun this script.' }
}

# The reported site root and child app share the same public folder.
# Separate the root to avoid applying the same web.config at two levels.
Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll')
$manager = New-Object Microsoft.Web.Administration.ServerManager
try {
    $site = $manager.Sites[$SiteName]
    if (!$site) { throw "IIS site not found: $SiteName" }
    $app = $site.Applications['/eis-docs']
    if (!$app) { throw "IIS Application /eis-docs does not exist under $SiteName." }
    $appPath = [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($app.VirtualDirectories['/'].PhysicalPath)).TrimEnd('\')
    if ($appPath -ne $public.TrimEnd('\')) { throw "Application path does not match $public. Check IIS before continuing." }
    $root = $site.Applications['/'].VirtualDirectories['/']
    $rootPath = [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($root.PhysicalPath)).TrimEnd('\')
    if ($rootPath -eq $appPath) {
        $newRoot = Join-Path $ProjectPath 'iis-site-root'
        New-Item -ItemType Directory -Path $newRoot -Force | Out-Null
        $rootConfig = Join-Path $newRoot 'web.config'
        if (!(Test-Path -LiteralPath $rootConfig)) {
            [IO.File]::WriteAllText($rootConfig,'<configuration><system.webServer><rewrite><rules><rule name="EIS-DOCS site landing" stopProcessing="true"><match url="^$"/><action type="Redirect" url="/eis-docs/" redirectType="Found"/></rule></rules></rewrite></system.webServer></configuration>')
        }
        $root.PhysicalPath = $newRoot
        $manager.CommitChanges()
        Write-Output "Site root moved to $newRoot; /eis-docs still uses $public."
    }
    $poolName = $app.ApplicationPoolName
} finally { $manager.Dispose() }
& $repair -ConfigPath (Join-Path $public 'web.config') -BackupDirectory (Join-Path $ProjectPath 'storage\config-backups')
& $appcmd recycle apppool "/apppool.name:$poolName"
if ($LASTEXITCODE -ne 0) { throw 'App Pool recycle failed.' }
$health = Invoke-RestMethod "https://$SiteName/eis-docs/api/health" -TimeoutSec 15
if ($health.status -ne 'UP') { throw 'Public health is not UP. Run Test-ProductionWebSocket.ps1 for diagnostics.' }
Write-Output 'PASS: public health UP. Refresh the browser, test PDF, and verify WS status 101.'
Write-Output "IIS rollback backup: EIS-DOCS-BeforeInstall-$stamp (server-wide config snapshot)."
