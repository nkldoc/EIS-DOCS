# Production WebSocket restart and logs

Deploy `app/public/docMain/user-online.php`, `presence-control.php`, `presence-control.js` and the three new service scripts (`install-production-control.ps1`, `production-control.ps1`, `run-production.ps1`).

On the production server, run PowerShell as Administrator from `app/services/presence`:

```powershell
.\install-production-control.ps1 -AppPool 'YOUR_EIS_DOCS_APP_POOL' -NodePath 'C:\Program Files\nodejs\node.exe'
```

Use the real IIS application pool name. The existing `server.config.json` and PHP Presence configuration must point at the same private directory. Keep scripts outside the web root and writable only by deployment administrators/SYSTEM. This installer restarts the presence task once and changes it to capture output; all Messenger clients reconnect.

The page is administrator-only and denies impersonation. Restart requires a session CSRF token, has a global 90 second cooldown, and writes only a fixed request file. A SYSTEM task polls once per minute and can restart only `EIS-DOCS-Supplies-Presence`. PHP never executes shell commands. The worker status expires after three minutes. Control task availability is separate from WebSocket availability; the existing connection banner reports the browser connection.

Logs show the last 24 KB of control and service output, plus browser diagnostics with the existing sanitized connection logs. Service output rotates on startup above 5 MB; control output rotates above 1 MB. Avoid adding tokens or secrets to server console output.

Verify as administrator: status appears, restart queues once, repeat requests are blocked, control log records stop/start, service log records listener startup, and the connection banner returns to connected. If service is running but public handshake still returns 502, inspect IIS ARR upstream address/port/protocol and WebSocket forwarding; restarting cannot repair a proxy configuration error.

These changes do not deploy themselves or establish that production has recovered.
