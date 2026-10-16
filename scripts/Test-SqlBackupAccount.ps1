$ErrorActionPreference='Stop'
$script:created=$false;$script:existing=$null;$script:setupCalls=@();$script:group='';$script:committed=$false
$password=ConvertTo-SecureString 'test-fixture-only' -AsPlainText -Force
function Test-Path { $true }
function Get-LocalUser { if ($script:existing) { return $script:existing }; if ($script:created) { return [pscustomobject]@{SID='S-1-5-21-1';Description='EIS-DOCS SQL backup service account'} } }
function New-LocalUser { param($Name,$Password,$Description,[switch]$AccountNeverExpires,[switch]$PasswordNeverExpires,[switch]$UserMayNotChangePassword) if ($Password -isnot [Security.SecureString]) {throw 'Plaintext password passed'};$script:created=$true }
function Set-LocalUser { param($Name,$Password,$PasswordNeverExpires) if ($Password -isnot [Security.SecureString]) {throw 'Plaintext reset'};$script:reset=$true }
function Enable-LocalUser {}
function Get-LocalGroup { param($SID) [pscustomobject]@{Name='IIS_IUSRS';SID=$SID} }
function Get-LocalGroupMember { @() }
function Add-LocalGroupMember {param($Group,$Member) $script:group=$Group.SID }
function icacls.exe { $global:LASTEXITCODE=0 }
function Invoke-TestSetup {param($Mode,$Connection,$SqlDirectory,$WebServiceAccount,$SqlInstanceService,$WebDirectory,$AppDirectory,$StorageCredential) $script:setupCalls+=@{Mode=$Mode;Account=$WebServiceAccount;Credential=$StorageCredential} }
function Invoke-TestAppcmd { $global:LASTEXITCODE=0;if ($args[0] -eq 'list') { return 'Stopped' };if ($args[0] -eq 'start') {$script:started=$true};if ($args[0] -eq 'recycle') {throw 'Stopped pool must be started, not recycled'} }
Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll')
$source=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Setup-SqlBackupAccount.ps1')) -replace '(?m)^#Requires[^\r\n]*',''
$source=$source.Replace("`$setupScript = Join-Path `$PSScriptRoot 'Setup-SqlBackup.ps1'","`$setupScript = 'Invoke-TestSetup'").Replace('New-Object Microsoft.Web.Administration.ServerManager','$script:fakeManager').Replace("`$appcmd = Join-Path `$env:windir 'System32\inetsrv\appcmd.exe'","`$appcmd = 'Invoke-TestAppcmd'")
$source=$source.Replace("`$copyScript = Join-Path `$PSScriptRoot 'Copy-SqlBackupIisPool.ps1'", "`$copyScript = '" + (Join-Path $PSScriptRoot 'Copy-SqlBackupIisPool.ps1') + "'")
$source=$source.Replace("`$iisLoader = Join-Path `$PSScriptRoot 'Import-SqlBackupIisAdministration.ps1'", "`$iisLoader = '" + (Join-Path $PSScriptRoot 'Import-SqlBackupIisAdministration.ps1') + "'")
$run=[scriptblock]::Create($source)
& $run -Mode SqlServer -Password $password | Out-Null
if (!$script:created -or $script:setupCalls[0].Mode -ne 'SqlServer' -or $script:setupCalls[0].Account -ne ($env:COMPUTERNAME+'\eis_backup')) {throw 'SQL account/share mode failed'}
$script:created=$false;$script:setupCalls=@()
$pool=[pscustomobject]@{ProcessModel=[pscustomobject]@{IdentityType=$null;UserName='';Password=''}}
$script:fakeManager=[pscustomobject]@{ApplicationPools=@{'EIS-DOCS'=$pool};Sites=@([pscustomobject]@{Applications=@([pscustomobject]@{ApplicationPoolName='EIS-DOCS';VirtualDirectories=@{'/'=[pscustomobject]@{PhysicalPath='D:\EIS-DOCS\app\public'}}})})}
$script:fakeManager | Add-Member ScriptMethod Dispose {}
$script:fakeManager | Add-Member ScriptMethod CommitChanges {$script:committed=$true}
& $run -Mode WebServer -Password $password -SessionDirectory 'D:\php-sessions' | Out-Null
if (!$script:committed -or $pool.ProcessModel.UserName -ne ($env:COMPUTERNAME+'\eis_backup')) {throw 'IIS identity setup failed'}
if (!$script:started) {throw 'Stopped pool was not started'}
if ($script:group -ne 'S-1-5-32-568') {throw 'Account not scoped to IIS group'}
if ($script:setupCalls[0].Credential -isnot [PSCredential]) {throw 'Share not tested with service credentials'}
$script:created=$false;$script:committed=$false
$script:fakeManager.ApplicationPools=@{'ActualProductionPool'=$pool}
$script:fakeManager.Sites[0].Applications[0].ApplicationPoolName='ActualProductionPool'
& $run -Mode WebServer -Password $password -SessionDirectory 'D:\php-sessions' | Out-Null
if (!$script:committed) {throw 'Actual production pool autodetection failed'}
$script:fakeManager.Sites[0].Applications+=@([pscustomobject]@{ApplicationPoolName='ActualProductionPool';VirtualDirectories=@{'/'=[pscustomobject]@{PhysicalPath='D:\OtherApp'}}})
$script:committed=$false;$script:created=$false;$denied=$false
try {& $run -Mode WebServer -Password $password -SessionDirectory 'D:\php-sessions' | Out-Null} catch {$denied=$true}
if (!$denied -or $script:committed -or $script:created) {throw 'Shared pool was changed without isolation'}
# Fake configuration elements exercise effective pool settings copying without touching IIS.
$pool | Add-Member NoteProperty Attributes @([pscustomobject]@{Name='name';Value='ActualProductionPool'},[pscustomobject]@{Name='enable32BitAppOnWin64';Value=$true})
$pool | Add-Member NoteProperty ChildElements @()
$pool | Add-Member NoteProperty Schema ([pscustomobject]@{CollectionSchema=$null})
$pool | Add-Member NoteProperty ElementTagName 'add'
$script:fakeManager.ApplicationPools | Add-Member ScriptMethod Add {
    param($name)
    $new=[pscustomobject]@{ProcessModel=[pscustomobject]@{IdentityType=$null;UserName='';Password=''};Settings=@{name=$name}}
    $new | Add-Member ScriptMethod SetAttributeValue {param($name,$value) $this.Settings[$name]=$value}
    $this[$name]=$new
    return $new
} -Force
$script:committed=$false
& $run -Mode WebServer -Password $password -SessionDirectory 'D:\php-sessions' -CreateDedicatedPool | Out-Null
if (!$script:committed -or $script:fakeManager.Sites[0].Applications[0].ApplicationPoolName -ne 'EIS-DOCS-Backup') {throw 'Dedicated app reassignment failed'}
if ($script:fakeManager.Sites[0].Applications[1].ApplicationPoolName -ne 'ActualProductionPool') {throw 'Other application moved unexpectedly'}
if ($script:fakeManager.ApplicationPools['EIS-DOCS-Backup'].Settings.enable32BitAppOnWin64 -ne $true) {throw 'Original pool settings lost'}
if ($script:fakeManager.ApplicationPools['EIS-DOCS-Backup'].Settings.name -ne 'EIS-DOCS-Backup') {throw 'Cloned pool name overwritten'}
$script:existing=[pscustomobject]@{Description='Unrelated user'};$blocked=$false
try {& $run -Mode SqlServer -Password $password -UpdateExistingAccount | Out-Null} catch {$blocked=$true}
if (!$blocked) {throw 'Unrelated account reset allowed'}
Write-Output 'PASS SQL/web account setup, SecureString handling, service probe, pool detection, dedicated pool settings/reassignment, shared-pool isolation and account protection (mocked; no account/IIS changes)'
