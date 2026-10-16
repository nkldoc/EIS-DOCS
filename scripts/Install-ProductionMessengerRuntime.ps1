#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param([string]$ProjectPath = 'D:\EIS-DOCS')
$ErrorActionPreference = 'Stop'
$service = Join-Path $ProjectPath 'app\services\presence'
foreach ($file in @('server.js','server.config.json','package-lock.json','install-production-task.ps1')) {
    if (-not (Test-Path -LiteralPath (Join-Path $service $file))) { throw "Missing $file. Configure production Messenger first." }
}
$node = Get-Command node.exe -ErrorAction SilentlyContinue
$nodePath = if ($node) { $node.Source } else { 'C:\Program Files\nodejs\node.exe' }
if (-not (Test-Path -LiteralPath $nodePath)) {
    if (-not [Environment]::Is64BitOperatingSystem) { throw 'This installer requires Windows x64.' }
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $download = Join-Path $env:TEMP ('eis-node-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $download | Out-Null
    $base = 'https://nodejs.org/dist/latest-v22.x/'
    $hashFile = Join-Path $download 'SHASUMS256.txt'
    Invoke-WebRequest -UseBasicParsing -Uri ($base + 'SHASUMS256.txt') -OutFile $hashFile
    $entries = @(Get-Content $hashFile | Where-Object { $_ -match '^[a-f0-9]{64}\s+node-v22\.[0-9]+\.[0-9]+-x64\.msi$' })
    if ($entries.Count -ne 1) { throw 'Could not identify the official Node 22 x64 installer.' }
    $parts = $entries[0] -split '\s+'
    $msi = Join-Path $download $parts[1]
    Invoke-WebRequest -UseBasicParsing -Uri ($base + $parts[1]) -OutFile $msi
    if ((Get-FileHash -LiteralPath $msi -Algorithm SHA256).Hash -ne $parts[0]) { throw 'Node installer checksum mismatch.' }
    $log = Join-Path $download 'install.log'
    $process = Start-Process msiexec.exe -ArgumentList "/i `"$msi`" /qn /norestart /L*v `"$log`"" -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -notin @(0,3010)) { throw "Node installation failed ($($process.ExitCode)). See $log" }
    if ($process.ExitCode -eq 3010) { throw 'Node installed; restart Windows during the maintenance window, then rerun this script.' }
}
if (-not (Test-Path -LiteralPath $nodePath)) { throw 'Node still missing after installation.' }
& $nodePath --version
if ($LASTEXITCODE -ne 0) { throw 'Node cannot run on this server.' }
$env:Path = (Split-Path $nodePath -Parent) + ';' + $env:Path
$npm = Join-Path (Split-Path $nodePath -Parent) 'npm.cmd'
if (-not (Test-Path -LiteralPath $npm)) { throw 'npm.cmd missing beside node.exe.' }
Push-Location $service
try {
    # Windows PowerShell/ISE can treat npm notices on stderr as terminating
    # errors. Collect output with Continue and use npm's exit code instead.
    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $npmOutput = & $npm ci --omit=dev 2>&1
        $npmExitCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previousPreference }
    foreach ($line in $npmOutput) { Write-Host ([string]$line) }
    if ($npmExitCode -ne 0) { throw "npm dependency installation failed (exit $npmExitCode)." }
    & .\install-production-task.ps1 -NodePath $nodePath
} finally { Pop-Location }
$healthy = $false
for ($attempt = 0; $attempt -lt 15; $attempt++) {
    try {
        $health = Invoke-RestMethod 'http://127.0.0.1:4003/health' -TimeoutSec 2
        if ($health.status -eq 'ok' -and $health.service -eq 'docs-supplies-presence') { $healthy = $true; break }
    } catch { }
    Start-Sleep -Seconds 1
}
if (-not $healthy) { throw 'Task installed but presence health is unavailable. Inspect task LastTaskResult and run node server.js in the service directory to see the error.' }
Write-Host 'PASS: Messenger backend health is ok. Public WebSocket and Supplies shared storage still require verification.'
