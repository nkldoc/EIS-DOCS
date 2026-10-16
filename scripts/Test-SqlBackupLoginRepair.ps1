$ErrorActionPreference='Stop'
$script:grants=@();$script:locations=@();$script:committed=$false;$script:configSaved=$false
function Test-Path {$true}
function New-Item {}
function Copy-Item {}
function icacls.exe {$script:grants+=($args -join ' ');$global:LASTEXITCODE=0}
function Invoke-TestAppcmd {$global:LASTEXITCODE=0}
$section=[pscustomobject]@{Values=@{enabled=$true;userName='IUSR'}}
$section | Add-Member ScriptMethod GetAttributeValue {param($name) return $this.Values[$name]}
$section | Add-Member ScriptMethod SetAttributeValue {param($name,$value) $this.Values[$name]=$value}
$configuration=[pscustomobject]@{}
$configuration | Add-Member ScriptMethod GetSection {param($name,$location) $script:locations+=$location;return $section}
$script:manager=[pscustomobject]@{Sites=@([pscustomobject]@{Name='doc-eis.nmu.ac.th';Applications=@([pscustomobject]@{Path='/eis-docs';ApplicationPoolName='EIS-DOCS-Backup';VirtualDirectories=@{'/'=[pscustomobject]@{PhysicalPath='D:\EIS-DOCS\app\public'}}},[pscustomobject]@{Path='/other';ApplicationPoolName='SharedPool';VirtualDirectories=@{'/'=[pscustomobject]@{PhysicalPath='D:\Other'}}})});ApplicationPools=@{'EIS-DOCS-Backup'=[pscustomobject]@{ProcessModel=[pscustomobject]@{IdentityType='SpecificUser';UserName='SERVER\eis_backup'}}}}
$script:manager | Add-Member ScriptMethod Dispose {}
$script:manager | Add-Member ScriptMethod GetApplicationHostConfiguration {return $configuration}
$script:manager | Add-Member ScriptMethod CommitChanges {$script:committed=$true}
$source=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Repair-SqlBackupLogin.ps1')) -replace '(?m)^#Requires[^\r\n]*',''
$source=$source.Replace("`$loader=Join-Path `$PSScriptRoot 'Import-SqlBackupIisAdministration.ps1'","`$loader='"+(Join-Path $PSScriptRoot 'Import-SqlBackupIisAdministration.ps1')+"'").Replace('New-Object Microsoft.Web.Administration.ServerManager','$script:manager').Replace("`$appcmd=Join-Path `$env:windir 'System32\inetsrv\appcmd.exe'","`$appcmd='Invoke-TestAppcmd'")
$source=$source -replace '(?m)^\s*\[IO.File\]::WriteAllText\(\$file,.*$', '$script:configSaved=$true'
& ([scriptblock]::Create($source)) | Out-Null
if (!$script:committed -or !$script:configSaved) {throw 'Repair configuration not saved'}
if ($script:locations.Count -ne 1 -or $script:locations[0] -ne 'doc-eis.nmu.ac.th/eis-docs') {throw 'Authentication changes not isolated to EIS-DOCS'}
if ($section.Values.userName -ne '') {throw 'PHP anonymous identity not aligned with pool'}
if (($script:grants -join '') -notmatch 'SERVER\\eis_backup' -or ($script:grants -join '') -notmatch 'storage\\php-sessions') {throw 'Session ACL target/account incorrect'}
Write-Output 'PASS private session grants/config, app-only anonymous identity, IIS backup/commit/recycle (mocked; no production changes)'
