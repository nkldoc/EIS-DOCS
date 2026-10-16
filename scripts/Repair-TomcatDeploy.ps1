#Requires -RunAsAdministrator
$ErrorActionPreference = 'Stop'
$serviceName = 'tomcat9'
$tomcatHome = (Resolve-Path -LiteralPath 'D:\tomcat9').Path
$webappsPath = (Resolve-Path -LiteralPath (Join-Path $tomcatHome 'webapps')).Path
$explodedPath = Join-Path $webappsPath 'eis-docs'
$warPath = Join-Path $webappsPath 'eis-docs.war'

if ($explodedPath -ne 'D:\tomcat9\webapps\eis-docs') {
    throw "Unexpected deployment path: $explodedPath"
}
if (-not (Test-Path -LiteralPath $warPath -PathType Leaf)) {
    throw "WAR not found: $warPath"
}

Stop-Service -Name $serviceName -Force
$service = Get-Service -Name $serviceName
$service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))

if (Test-Path -LiteralPath $explodedPath) {
    Remove-Item -LiteralPath $explodedPath -Recurse -Force
}

Start-Service -Name $serviceName
Write-Host 'Tomcat clean deployment started.'
