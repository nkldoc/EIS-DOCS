param([Parameter(Mandatory=$true)][string]$StorageDirectory)
$ErrorActionPreference = 'Stop'
# Run on the production server. Never copy local secrets or local history.
New-Item -ItemType Directory -Force -Path $StorageDirectory,(Join-Path $StorageDirectory 'logins'),(Join-Path $StorageDirectory 'revoked') | Out-Null
foreach ($name in @('eis-docs.secret','supplies.secret')) {
    $file = Join-Path $StorageDirectory $name
    if (-not (Test-Path -LiteralPath $file)) {
        $bytes = New-Object byte[] 32
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
        $secret = ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
        [IO.File]::WriteAllText($file, $secret, [Text.UTF8Encoding]::new($false))
    }
}
Push-Location $PSScriptRoot
try { npm.cmd ci --omit=dev; if ($LASTEXITCODE -ne 0) { throw 'npm ci failed' } } finally { Pop-Location }
Write-Output 'Storage and dependencies ready. Set filesystem permissions, server.config.json and PHP presence.config.php before starting.'
