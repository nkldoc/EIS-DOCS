#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string]$TomcatHome = 'D:\tomcat9',
    [string]$IisSite = 'Default Web Site'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$publicPath = Join-Path $projectRoot 'app\public'
$warPath = Join-Path $projectRoot 'backend\target\eis-docs.war'
$appCmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'

Push-Location (Join-Path $projectRoot 'backend')
try {
    & mvn -q clean package
    if ($LASTEXITCODE -ne 0) { throw 'Maven build failed.' }
} finally {
    Pop-Location
}

$tomcatWebapps = Join-Path $TomcatHome 'webapps'
if (-not (Test-Path -LiteralPath $tomcatWebapps)) {
    throw "Tomcat webapps not found: $tomcatWebapps"
}
Copy-Item -LiteralPath $warPath -Destination (Join-Path $tomcatWebapps 'eis-docs.war') -Force

$iisAppName = "$IisSite/eis-docs"
& $appCmd list app /app.name:"$iisAppName" | Out-Null
if ($LASTEXITCODE -ne 0) {
    & $appCmd add app /site.name:"$IisSite" /path:/eis-docs /physicalPath:"$publicPath"
} else {
    & $appCmd set app "$iisAppName" /physicalPath:"$publicPath"
}
if ($LASTEXITCODE -ne 0) { throw 'IIS application configuration failed.' }

Restart-Service -Name W3SVC
$tomcatService = Get-CimInstance Win32_Service | Where-Object { $_.PathName -like "$TomcatHome*" } | Select-Object -First 1
if ($null -eq $tomcatService) { throw "Tomcat service for $TomcatHome was not found." }
Restart-Service -Name $tomcatService.Name

Write-Host 'PHP:  http://localhost/eis-docs/'
Write-Host 'Java: http://localhost:8080/eis-docs/api/health'
