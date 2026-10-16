$ErrorActionPreference = 'Stop'
$presenceDirectory = 'D:/EIS-DOCS/storage/presence'
function Test-PresencePort {
    $presenceProbe = [System.Net.Sockets.TcpClient]::new()
    try { return $presenceProbe.ConnectAsync('127.0.0.1', 4003).Wait(500) -and $presenceProbe.Connected }
    catch { return $false }
    finally { $presenceProbe.Dispose() }
}
if (Test-PresencePort) { Write-Output 'Port 4003 is already listening. Check its /health endpoint.'; return }
$presenceProcess = Start-Process -FilePath (Get-Command node.exe).Source -ArgumentList 'server.js' -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput "$presenceDirectory/service.log" -RedirectStandardError "$presenceDirectory/service-error.log" -PassThru
for ($presenceAttempt = 0; $presenceAttempt -lt 20; $presenceAttempt++) {
    if ($presenceProcess.HasExited) { throw 'Presence exited during startup. Check service-error.log.' }
    if (Test-PresencePort) { Write-Output 'Presence is listening on local port 4003.'; return }
    Start-Sleep -Milliseconds 100
}
throw 'Presence startup timed out. Check service-error.log before starting another process.'
