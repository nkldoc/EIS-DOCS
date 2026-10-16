param([Parameter(Mandatory=$true)][string]$NodePath, [Parameter(Mandatory=$true)][string]$Directory)
$ErrorActionPreference = 'Stop'
$serviceLog = Join-Path $Directory 'control/service.log'
if ((Test-Path -LiteralPath $serviceLog) -and (Get-Item -LiteralPath $serviceLog).Length -gt 5MB) {
    Move-Item -LiteralPath $serviceLog -Destination ($serviceLog + '.1') -Force
}
Add-Content -LiteralPath $serviceLog -Value ((Get-Date -Format o) + ' Starting presence service') -Encoding UTF8
Set-Location -LiteralPath $PSScriptRoot
& $NodePath (Join-Path $PSScriptRoot 'server.js') 2>&1 | ForEach-Object {
    Add-Content -LiteralPath $serviceLog -Value ((Get-Date -Format o) + ' ' + $_) -Encoding UTF8
}
$serviceExitCode = $LASTEXITCODE
Add-Content -LiteralPath $serviceLog -Value ((Get-Date -Format o) + ' Presence exited: ' + $serviceExitCode) -Encoding UTF8
if ($serviceExitCode -eq 0) { exit 1 }
exit $serviceExitCode
