[CmdletBinding()]
param(
    [string]$ConfigPath = 'D:\EIS-DOCS\app\public\web.config',
    [string]$BackupDirectory = 'D:\EIS-DOCS\storage\config-backups'
)
$ErrorActionPreference = 'Stop'
$resolvedConfig = (Resolve-Path -LiteralPath $ConfigPath).Path
$backupPath = [IO.Path]::GetFullPath($BackupDirectory)
$publicPath = [IO.Path]::GetDirectoryName($resolvedConfig).TrimEnd('\') + '\'
if (($backupPath.TrimEnd('\') + '\').StartsWith($publicPath, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'BackupDirectory must be outside the public web directory.'
}
$xml = New-Object System.Xml.XmlDocument
$xml.PreserveWhitespace = $true
$xml.Load($resolvedConfig)
$rules = $xml.SelectSingleNode('/configuration/system.webServer/rewrite/rules')
if ($null -eq $rules) { throw 'No application rewrite rules found; no changes made.' }
$changed = $false
foreach ($collection in @($rules, $xml.SelectSingleNode('/configuration/system.webServer/httpProtocol/customHeaders'))) {
    if ($null -eq $collection) { continue }
    $entries = @($collection.SelectNodes('rule|add'))
    foreach ($entry in $entries) {
        $name = $entry.GetAttribute('name')
        if ([string]::IsNullOrWhiteSpace($name)) { continue }
        $priorRemove = @($collection.SelectNodes('remove') | Where-Object { $_.GetAttribute('name') -eq $name })
        if ($priorRemove.Count -gt 0) { continue }
        $remove = $xml.CreateElement('remove')
        $remove.SetAttribute('name', $name)
        [void]$collection.InsertBefore($remove, $entry)
        $changed = $true
    }
}
if (-not $changed) { Write-Output 'Named inheritance overrides already present. No changes needed.'; return }
[void][IO.Directory]::CreateDirectory($backupPath)
$backup = Join-Path $backupPath ('web.config.' + [DateTime]::Now.ToString('yyyyMMdd-HHmmss-fff') + '.bak')
Copy-Item -LiteralPath $resolvedConfig -Destination $backup
$temporary = $resolvedConfig + '.' + [Guid]::NewGuid().ToString('N') + '.tmp'
try {
    $xml.Save($temporary)
    $validation = New-Object System.Xml.XmlDocument
    $validation.Load($temporary)
    Copy-Item -LiteralPath $temporary -Destination $resolvedConfig -Force
} finally {
    if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary }
}
Write-Output "Updated: $resolvedConfig"
Write-Output "Rollback backup: $backup"
Write-Output 'IIS reloads web.config automatically. Verify /eis-docs/ and login on the server.'
