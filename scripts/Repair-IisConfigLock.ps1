#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding(SupportsShouldProcess)]
param([string]$SiteName='doc-eis.nmu.ac.th', [string]$ApplicationPath='/eis-docs')
$ErrorActionPreference='Stop'
$appcmd=Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll')
$location=$SiteName+$ApplicationPath
$manager=New-Object Microsoft.Web.Administration.ServerManager
try {
    $site=$manager.Sites[$SiteName]
    if(!$site -or !$site.Applications[$ApplicationPath]) { throw "Application not found: $location" }
    $app=$site.Applications[$ApplicationPath]
    $configPath=Join-Path ([Environment]::ExpandEnvironmentVariables($app.VirtualDirectories['/'].PhysicalPath)) 'web.config'
    [xml]$xml=Get-Content -LiteralPath $configPath -Raw
    $serverConfig=$manager.GetApplicationHostConfiguration()
    $sections=@('handlers','webSocket','defaultDocument','rewrite/rules','httpProtocol')
    $locked=@()
    foreach($section in $sections) {
        if(!$xml.SelectSingleNode('/configuration/system.webServer/'+$section)) { continue }
        $name='system.webServer/'+$section
        $configSection=$serverConfig.GetSection($name,$location)
        $previousPreference=$ErrorActionPreference
        $ErrorActionPreference='Continue'
        $effectiveOutput = & $appcmd list config $location "/section:$name" 2>&1
        $effectiveExit = $LASTEXITCODE
        $ErrorActionPreference=$previousPreference
        $effectiveLocked = $effectiveExit -ne 0 -and (($effectiveOutput -join ' ') -match 'locked at a parent level|0x80070021')
        Write-Output "$name : ApplicationHostLocked=$($configSection.IsLocked); EffectiveConfigLocked=$effectiveLocked"
        if($configSection.IsLocked -or $effectiveLocked) { $locked+=$name }
        elseif($effectiveExit -ne 0) { throw ($effectiveOutput -join "`n") }
    }
    $pool=$app.ApplicationPoolName
} finally { $manager.Dispose() }
if($locked.Count -eq 0) {
    Write-Output 'No applicationHost section locks detected. Checking effective application configuration:'
    foreach($section in $sections) {
        Write-Output "== system.webServer/$section =="
        & $appcmd list config $location "/section:system.webServer/$section"
    }
    Write-Output '== Local IIS detailed error (same HTTPS host, loopback connection) =='
    $curl=Get-Command curl.exe -ErrorAction SilentlyContinue
    if($curl) {
        $page = & $curl.Source --silent --show-error --noproxy '*' --resolve "${SiteName}:443:127.0.0.1" --max-time 15 "https://$SiteName$ApplicationPath/api/health" 2>&1
        $text=($page -join "`n") -replace '<[^>]+>',' '
        $text=[Net.WebUtility]::HtmlDecode($text)
        $text -split "`n" | Where-Object { $_.Trim() } | Select-Object -Last 90
    } else { Write-Output 'Open the public health URL in a browser ON the IIS server and copy Detailed Error Information / Config Source.' }
    return
}
if($PSCmdlet.ShouldProcess($location,'Back up IIS configuration and unlock only detected sections for this application')) {
    $backup='EIS-DOCS-BeforeUnlock-'+(Get-Date -Format 'yyyyMMdd-HHmmss')
    & $appcmd add backup $backup
    if($LASTEXITCODE -ne 0) { throw 'Backup failed. No locks changed.' }
    foreach($section in $locked) {
        & $appcmd unlock config $location "/section:$section"
        if($LASTEXITCODE -ne 0) { throw "Cannot unlock $section. Backup: $backup" }
    }
    foreach($section in $sections) {
        & $appcmd list config $location "/section:system.webServer/$section"
        if($LASTEXITCODE -ne 0) { throw "Configuration still invalid in $section; see filename/line above. Backup: $backup" }
    }
    & $appcmd recycle apppool "/apppool.name:$pool"
    if($LASTEXITCODE -ne 0) { throw 'App Pool recycle failed.' }
    try {
        $health=Invoke-RestMethod "https://$SiteName$ApplicationPath/api/health" -TimeoutSec 15
        if($health.status -ne 'UP') { throw 'Health response is not UP' }
        Write-Output 'PASS: public API health UP. Refresh browser and test PDF.'
    } catch {
        Write-Warning "Config locks repaired, but public health has not passed: $($_.Exception.Message)"
    }
    Write-Output "IIS backup: $backup"
}
