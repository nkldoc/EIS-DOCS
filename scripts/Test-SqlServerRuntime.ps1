[CmdletBinding()]
param([string]$PhpPath = 'C:\php\php.exe')
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $PhpPath)) { throw "PHP not found: $PhpPath. Use the PHP path configured in IIS FastCGI." }
$probe = '$info = ["php"=>PHP_VERSION,"architecture"=>PHP_INT_SIZE*8,"thread_safe"=>(bool)PHP_ZTS,"php_ini"=>php_ini_loaded_file(),"sqlsrv"=>phpversion("sqlsrv"),"pdo_sqlsrv"=>phpversion("pdo_sqlsrv")]; echo json_encode($info);'
$output = ('<?php ' + $probe) | & $PhpPath
if ($LASTEXITCODE -ne 0) { throw 'PHP runtime check failed.' }
$info = $output | ConvertFrom-Json
$info | Format-List
$platform = if ($info.architecture -eq 64) { '64-bit' } else { '32-bit' }
$drivers = @(Get-OdbcDriver -Platform $platform | Where-Object { $_.Name -match '^ODBC Driver (17|18) for SQL Server$' })
if (-not $info.sqlsrv) { throw 'SQLSRV extension is not loaded in this PHP runtime.' }
if ($drivers.Count -eq 0) {
    throw "No ODBC Driver 17/18 for SQL Server for $platform PHP. Install a compatible Microsoft ODBC driver on THIS IIS server, then recycle the application pool."
}
$drivers | Select-Object Name,Platform | Format-Table -AutoSize
Write-Output 'Runtime prerequisites found. If IIS still reports IM002/-49, verify IIS uses this PHP executable and inspect its loaded php.ini; repair the matching ODBC installation if necessary.'
Write-Output 'Official installers: https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server'
