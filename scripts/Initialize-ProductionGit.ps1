#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Path = 'D:\EIS-DOCS',
    [string]$Remote = 'https://github.com/nkldoc/EIS-DOCS.git',
    [string]$Branch = 'main'
)
$ErrorActionPreference = 'Stop'
$target = (Resolve-Path -LiteralPath $Path).Path
if (Test-Path -LiteralPath (Join-Path $target '.git')) {
    throw 'Git metadata already exists. Run git status and git remote -v in this directory instead.'
}
function Invoke-GitChecked {
    param([string[]]$GitArguments)
    & git -C $target @GitArguments
    if ($LASTEXITCODE -ne 0) { throw "Git failed: $($GitArguments[0]). Existing application files have not been overwritten." }
}
# Initialise metadata only. A mixed reset updates HEAD/index, NEVER the working files.
Invoke-GitChecked -GitArguments @('init', '--initial-branch', $Branch)
Invoke-GitChecked -GitArguments @('remote', 'add', 'origin', $Remote)
Invoke-GitChecked -GitArguments @('fetch', 'origin', $Branch)
Invoke-GitChecked -GitArguments @('reset', '--mixed', "origin/$Branch")
Invoke-GitChecked -GitArguments @('branch', '--set-upstream-to', "origin/$Branch", $Branch)
Invoke-GitChecked -GitArguments @('config', 'pull.ff', 'only')
Write-Host 'Git is attached. All existing application/config/storage files were preserved.'
Write-Host 'IMPORTANT: this establishes a baseline; it does NOT deploy the fetched version.'
Write-Host 'Review the differences below before updating production files. Do not run reset --hard or clean.'
Invoke-GitChecked -GitArguments @('status', '--short')
Write-Host "Future update command: git -C `"$target`" pull --ff-only origin $Branch"
Write-Host 'Git will stop if an incoming change conflicts with a local production file. Back up and reconcile that file individually.'
