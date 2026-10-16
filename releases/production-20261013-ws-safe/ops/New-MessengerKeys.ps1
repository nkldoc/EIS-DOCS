param([Parameter(Mandatory=$true)][string]$PrivateDirectory)
$ErrorActionPreference = 'Stop'
$privateTarget = [System.IO.Path]::GetFullPath($PrivateDirectory)
if (-not (Test-Path -LiteralPath $privateTarget -PathType Container)) { throw 'Create and secure the private directory outside the web root first.' }
foreach ($keyName in @('procure.secret','nmu-eis.secret')) {
    $keyPath = Join-Path $privateTarget $keyName
    if (Test-Path -LiteralPath $keyPath) { Write-Output "$keyName exists; preserved."; continue }
    $keyBytes = New-Object byte[] 32
    $keyGenerator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $keyGenerator.GetBytes($keyBytes) } finally { $keyGenerator.Dispose() }
    $keyText = [BitConverter]::ToString($keyBytes).Replace('-','').ToLowerInvariant()
    $keyStream = [System.IO.File]::Open($keyPath,[System.IO.FileMode]::CreateNew,[System.IO.FileAccess]::Write,[System.IO.FileShare]::None)
    try { $encodedKey = [System.Text.Encoding]::ASCII.GetBytes($keyText); $keyStream.Write($encodedKey,0,$encodedKey.Length) } finally { $keyStream.Dispose(); [Array]::Clear($keyBytes,0,$keyBytes.Length) }
    Write-Output "$keyName created; secret value was not printed."
}
