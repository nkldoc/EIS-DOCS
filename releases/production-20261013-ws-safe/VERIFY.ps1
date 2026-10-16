$ErrorActionPreference = 'Stop'
$manifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json
foreach ($record in $manifest.files) {
    $candidate = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot $record.path))
    $boundary = [System.IO.Path]::GetFullPath($PSScriptRoot).TrimEnd('\') + '\'
    if (-not $candidate.StartsWith($boundary,[System.StringComparison]::OrdinalIgnoreCase)) { throw 'Invalid manifest path.' }
    if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { throw "Missing: $($record.path)" }
    if ((Get-FileHash -LiteralPath $candidate -Algorithm SHA256).Hash.ToLowerInvariant() -ne $record.sha256) { throw "Checksum mismatch: $($record.path)" }
}
Write-Output "PASS: $($manifest.files.Count) packaged files verified."
