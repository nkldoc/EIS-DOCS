#Requires -RunAsAdministrator
[CmdletBinding()]
param([string]$IisSite = 'Default Web Site')

$ErrorActionPreference = 'Stop'
$logPath = 'D:\EIS-DOCS\deploy-iis.log'
Start-Transcript -LiteralPath $logPath -Force
try {
    $appCmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
    $publicPath = 'D:\EIS-DOCS\app\public'
    if (-not (Test-Path -LiteralPath $publicPath)) {
        throw "Public path not found: $publicPath"
    }

    & $appCmd unlock config /section:system.webServer/handlers
    if ($LASTEXITCODE -ne 0) { throw 'Cannot unlock IIS handlers configuration.' }

    $sites = & $appCmd list site
    $sites | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'Cannot read IIS sites.' }
    if (($sites -join "`n") -notmatch [regex]::Escape("SITE `"$IisSite`"")) {
        throw "IIS site not found: $IisSite"
    }

    $applicationName = "$IisSite/eis-docs"
    $applications = & $appCmd list app
    if ($LASTEXITCODE -ne 0) { throw 'Cannot read IIS applications.' }

    if (($applications -join "`n") -match [regex]::Escape("APP `"$applicationName`"")) {
        & $appCmd set vdir "$applicationName/" /physicalPath:"$publicPath"
    } else {
        & $appCmd add app /site.name:"$IisSite" /path:/eis-docs /physicalPath:"$publicPath"
    }
    if ($LASTEXITCODE -ne 0) { throw 'Cannot create IIS application /eis-docs.' }

    & $appCmd list app /app.name:"$applicationName"
    Restart-Service W3SVC
    Write-Host 'IIS deployment completed: http://localhost/eis-docs/login'
} catch {
    Write-Error $_
    exit 1
} finally {
    Stop-Transcript
}
