function Get-SqlBackupIisAssemblyPath {
    param(
        [string]$WindowsDirectory=$env:windir,
        [bool]$Is64BitOS=[Environment]::Is64BitOperatingSystem,
        [bool]$Is64BitProcess=[Environment]::Is64BitProcess
    )
    if ($Is64BitOS -and !$Is64BitProcess) {
        throw "PowerShell is 32-bit on $env:COMPUTERNAME. Open 64-bit Administrator PowerShell on doc-eis (10.10.10.6). The 64-bit executable from this process is $WindowsDirectory\Sysnative\WindowsPowerShell\v1.0\powershell.exe."
    }
    $direct=Join-Path $WindowsDirectory 'System32\inetsrv\Microsoft.Web.Administration.dll'
    if (Test-Path -LiteralPath $direct) { return $direct }
    foreach ($root in @('Microsoft.NET\assembly\GAC_MSIL\Microsoft.Web.Administration','assembly\GAC_MSIL\Microsoft.Web.Administration')) {
        $directory=Join-Path $WindowsDirectory $root
        if (Test-Path -LiteralPath $directory) {
            $dll=Get-ChildItem -LiteralPath $directory -Filter Microsoft.Web.Administration.dll -Recurse -File | Select-Object -First 1
            if ($dll) { return $dll.FullName }
        }
    }
    throw "IIS administration DLL not found on $env:COMPUTERNAME. WebServer mode must run on doc-eis (10.10.10.6), not NMU-EIS-DB (10.10.10.3). If already on doc-eis with 64-bit PowerShell, check that IIS Management Scripts and Tools is installed."
}
function Import-SqlBackupIisAdministration {
    $path=Get-SqlBackupIisAssemblyPath
    if (-not ('Microsoft.Web.Administration.ServerManager' -as [type])) { Add-Type -Path $path }
}
