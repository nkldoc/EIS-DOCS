#Requires -RunAsAdministrator
$ErrorActionPreference = 'Stop'
$appCmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
$poolName = 'EIS-DOCS'
$applicationName = 'Default Web Site/eis-docs'

$pools = & $appCmd list apppool
if (($pools -join "`n") -notmatch [regex]::Escape("APPPOOL `"$poolName`"")) {
    & $appCmd add apppool /name:"$poolName"
    if ($LASTEXITCODE -ne 0) { throw "Cannot create application pool $poolName." }
}

& $appCmd set apppool "$poolName" /managedRuntimeVersion:"" /managedPipelineMode:Integrated /processModel.identityType:ApplicationPoolIdentity
if ($LASTEXITCODE -ne 0) { throw "Cannot configure application pool $poolName." }

& $appCmd set app "$applicationName" /applicationPool:"$poolName"
if ($LASTEXITCODE -ne 0) { throw "Cannot assign $applicationName to $poolName." }

& $appCmd start apppool /apppool.name:"$poolName"
Write-Host "Application pool configured: $poolName"
