#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Path = 'D:\EIS-DOCS',
    [string]$Remote = 'https://github.com/nkldoc/EIS-DOCS.git',
    [string]$Branch = 'main'
)
$ErrorActionPreference = 'Stop'
$target = (Resolve-Path -LiteralPath $Path).Path
function Invoke-GitChecked {
    param([string[]]$GitArguments)
    # Windows PowerShell 5.1 wraps native stderr as NativeCommandError.
    # Git also writes successful fetch progress there; only its exit code is authoritative.
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & git -C $target @GitArguments 2>&1 | ForEach-Object { Write-Host $_.ToString() }
        $gitExitCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    if ($gitExitCode -ne 0) { throw "Git failed: $($GitArguments[0]) (exit $gitExitCode). Existing application files have not been overwritten." }
}
# Initialise metadata only. A mixed reset updates HEAD/index, NEVER the working files.
if (Test-Path -LiteralPath (Join-Path $target '.git')) {
    & git -C $target rev-parse --verify --quiet HEAD *> $null
    if ($LASTEXITCODE -eq 0) { throw 'Repository already has a HEAD. Use git status; initialisation will not reset an existing repository.' }
    $existingRemote = & git -C $target config --get remote.origin.url
    if ($existingRemote -and $existingRemote -ne $Remote) { throw 'Existing origin differs from the requested remote. No changes made.' }
    $existingBranch = & git -C $target symbolic-ref --short HEAD
    if ($LASTEXITCODE -ne 0 -or $existingBranch -ne $Branch) { throw 'Existing initial branch differs from the requested branch. No changes made.' }
    Write-Host 'Resuming initialisation of repository without a HEAD.'
} else {
    Invoke-GitChecked -GitArguments @('init', '--initial-branch', $Branch)
    $existingRemote = $null
}
if (-not $existingRemote) { Invoke-GitChecked -GitArguments @('remote', 'add', 'origin', $Remote) }
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
