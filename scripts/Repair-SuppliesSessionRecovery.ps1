[CmdletBinding()]
param([string]$SuppliesPath = 'D:\EIS-ERP\public\supplies', [string]$PhpPath = 'C:\php\php.exe')
$ErrorActionPreference = 'Stop'
$target = Join-Path $SuppliesPath 'conf\config.php'
$payload = Join-Path $PSScriptRoot 'supplies-session-guard.php.txt'
if (-not (Test-Path -LiteralPath $PhpPath)) { throw 'Specify -PhpPath with the PHP executable used by IIS.' }
$utf8 = New-Object Text.UTF8Encoding($false)
$content = [IO.File]::ReadAllText($target, $utf8)
$guard = [IO.File]::ReadAllText($payload, $utf8)
$start = $content.IndexOf('$loginScript =')
if ($start -lt 0) { $start = $content.IndexOf('if (!SuppliesLogin::validate()') }
$end = $content.IndexOf('if (rand(1, 50)', [Math]::Max(0, $start))
if ($start -lt 0 -or $end -le $start) { throw 'Expected session guard not found; no changes made.' }
$backupDir = Join-Path $env:ProgramData 'EIS-Messenger\config-backups'
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
$backup = Join-Path $backupDir ('supplies-config-' + [guid]::NewGuid().ToString('N') + '.php.bak')
Copy-Item -LiteralPath $target -Destination $backup
try {
    [IO.File]::WriteAllText($target, $content.Substring(0, $start) + $guard + $content.Substring($end), $utf8)
    & $PhpPath -n -l $target
    if ($LASTEXITCODE -ne 0) { throw 'PHP syntax check failed.' }
} catch { Copy-Item -LiteralPath $backup -Destination $target -Force; throw }
Write-Host "Session recovery guard updated; backup: $backup"
Write-Host 'Open /NMU_permission/launch.php?system=supplies after portal login and finish the Supplies confirmation form.'
