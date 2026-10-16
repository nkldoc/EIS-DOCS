param(
    [string]$PublicBase = 'https://doc-eis.nmu.ac.th/eis-docs',
    [string]$TomcatBase = 'http://127.0.0.1:8080/eis-docs'
)
$ErrorActionPreference = 'Continue'
function Test-Health([string]$Label, [string]$Base) {
    $url = $Base.TrimEnd('/') + '/api/health'
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 8
        $up = $false
        try { $up = ($response.Content | ConvertFrom-Json).status -eq 'UP' } catch {}
        [pscustomobject]@{ Test=$Label; URL=$url; HTTP=[int]$response.StatusCode; HealthUP=$up }
    } catch {
        $status = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 'UNREACHABLE' }
        [pscustomobject]@{ Test=$Label; URL=$url; HTTP=$status; HealthUP=$false }
    }
}
Write-Output '== Health checks (run ON the Production IIS/Tomcat server) =='
Test-Health 'Tomcat direct' $TomcatBase | Format-List
Test-Health 'IIS public proxy' $PublicBase | Format-List
Write-Output '== Tomcat services =='
Get-Service | Where-Object { $_.Name -match 'tomcat' -or $_.DisplayName -match 'tomcat' } | Select-Object Name,Status,DisplayName | Format-Table
Write-Output '== Port 8080 listener =='
Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue | Select-Object LocalAddress,LocalPort,OwningProcess | Format-Table
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
if (Test-Path -LiteralPath $appcmd) {
    Write-Output '== IIS applications =='
    & $appcmd list app
    Write-Output '== IIS physical paths =='
    & $appcmd list vdir
    Write-Output '== ARR proxy =='
    & $appcmd list config /section:system.webServer/proxy
}
Write-Output 'Direct UP + public 404: check IIS application path/rewrite. Direct 404: check WAR/context. Direct unreachable: check Tomcat service/port.'

Write-Output '== Recent IIS API errors (query strings and tokens omitted) =='
$logDirectory = Join-Path $env:SystemDrive 'inetpub\logs\LogFiles\W3SVC2'
$latestLog = Get-ChildItem -LiteralPath $logDirectory -Filter '*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if ($latestLog) {
    $fieldHeader = Get-Content -LiteralPath $latestLog.FullName -TotalCount 30 | Where-Object { $_ -like '#Fields:*' } | Select-Object -Last 1
    if ($fieldHeader) {
        $fields = ($fieldHeader -replace '^#Fields:\s*','') -split '\s+'
        $rows = foreach ($line in (Get-Content -LiteralPath $latestLog.FullName -Tail 3000)) {
            if ($line.StartsWith('#')) { continue }
            $values = $line -split '\s+'
            if ($values.Count -ne $fields.Count) { continue }
            $entry = @{}
            for ($i=0; $i -lt $fields.Count; $i++) { $entry[$fields[$i]]=$values[$i] }
            if ($entry['cs-uri-stem'] -like '/eis-docs/api/*') {
                [pscustomobject]@{Date=$entry['date']; UTC=$entry['time']; Path=$entry['cs-uri-stem']; Status=$entry['sc-status']; Substatus=$entry['sc-substatus']; Win32=$entry['sc-win32-status']}
            }
        }
        $rows | Select-Object -Last 12 | Format-Table -AutoSize
    }
} else { Write-Output "No log found at $logDirectory. Check the site's configured IIS log directory." }
if (Test-Path -LiteralPath $appcmd) {
    Write-Output '== Effective application rewrite rules =='
    & $appcmd list config 'doc-eis.nmu.ac.th/eis-docs' /section:system.webServer/rewrite/rules
}
