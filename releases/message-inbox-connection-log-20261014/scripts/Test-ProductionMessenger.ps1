[CmdletBinding()]
param([string]$ApplicationName = 'doc-eis.nmu.ac.th/eis-docs')
$ErrorActionPreference = 'Stop'
$backendHealthy = $false
$upgradeHealthy = $false
Write-Host '1. Local Messenger backend'
try {
    $health = Invoke-RestMethod 'http://127.0.0.1:4003/health' -TimeoutSec 5
    $backendHealthy = $health.status -eq 'ok' -and $health.service -eq 'docs-supplies-presence'
    $health | Format-List
}
catch { Write-Host $_.Exception.Message }
Write-Host '2. Public WebSocket handshake (no login token sent)'
$socket = New-Object Net.WebSockets.ClientWebSocket
$cancel = New-Object Threading.CancellationTokenSource
$cancel.CancelAfter(10000)
try {
    $socket.Options.SetRequestHeader('Origin', 'https://doc-eis.nmu.ac.th')
    $socket.ConnectAsync([uri]'wss://doc-eis.nmu.ac.th/eis-docs/presence', $cancel.Token).GetAwaiter().GetResult()
    $upgradeHealthy = $socket.State -eq [Net.WebSockets.WebSocketState]::Open
    Write-Host "PASS: WebSocket upgrade succeeded; state=$($socket.State)"
} catch {
    $errorItem = $_.Exception
    while ($errorItem) { Write-Host $errorItem.Message; $errorItem = $errorItem.InnerException }
} finally { $socket.Dispose(); $cancel.Dispose() }
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
if (Test-Path -LiteralPath $appcmd) {
    Write-Host '3. IIS application rewrite rules'
    & $appcmd list config $ApplicationName /section:system.webServer/rewrite/rules
    Write-Host '4. IIS WebSocket configuration'
    & $appcmd list config $ApplicationName /section:system.webServer/webSocket
    Write-Host '5. ARR proxy configuration'
    & $appcmd list config /section:system.webServer/proxy
    Write-Host '6. IIS presence request status'
    $site = $ApplicationName.Split('/')[0]
    $siteId = & $appcmd list site $site /text:id
    if ($LASTEXITCODE -eq 0) {
        $logDirectory = Join-Path $env:SystemDrive ('inetpub\logs\LogFiles\W3SVC' + ($siteId -join '').Trim())
        $log = Get-ChildItem -LiteralPath $logDirectory -Filter '*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        if ($log) {
            Get-Content -LiteralPath $log.FullName -TotalCount 6 | Where-Object { $_ -like '#Fields:*' }
            Get-Content -LiteralPath $log.FullName -Tail 500 | Select-String '/eis-docs/presence' | Select-Object -Last 5
        }
    }
}
if (-not $backendHealthy -or -not $upgradeHealthy) {
    throw "Messenger checks failed: local HTTP backend=$backendHealthy; public WebSocket upgrade=$upgradeHealthy. Run on the production IIS server. A 404 upgrade response indicates the presence route is not reaching Node."
}
Write-Host 'PASS: local production backend and public WebSocket upgrade are available. Verify login/authentication in the browser.'
