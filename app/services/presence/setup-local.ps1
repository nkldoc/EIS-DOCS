$ErrorActionPreference = 'Stop'
$presenceDirectory = 'D:/EIS-DOCS/storage/presence'
New-Item -ItemType Directory -Force $presenceDirectory, "$presenceDirectory/revoked", "$presenceDirectory/logins" | Out-Null
foreach ($source in @('eis-docs', 'supplies')) {
    if (!(Test-Path "$presenceDirectory/$source.secret")) {
        $bytes = New-Object byte[] 32
        [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        [IO.File]::WriteAllText("$presenceDirectory/$source.secret", ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant())
    }
}
foreach ($name in @('local.key', 'local.crt')) {
    if (!(Test-Path "$presenceDirectory/$name")) { Copy-Item -LiteralPath "C:/xampp/var/nmu-eis-presence/$name" -Destination "$presenceDirectory/$name" }
}
Push-Location $PSScriptRoot
try { & npm.cmd ci --ignore-scripts --no-audit --no-fund; if ($LASTEXITCODE -ne 0) { throw 'npm ci failed' } }
finally { Pop-Location }
Write-Output 'Local EIS-DOCS / Supplies presence configuration ready.'
