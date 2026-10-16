# Production Messenger reconnect failure — 2026-10-12

## Confirmed observations

Requests from the development machine to `https://doc-eis.nmu.ac.th/eis-docs/presence`, including a WebSocket Upgrade request with an allowed Origin, return HTTP 404. `ClientWebSocket.ConnectAsync` also fails with 404. IIS returns its HTML 404 response instead of a successful HTTP 101 upgrade. The supplied `app/public/web.config` had no Messenger presence rule.

The Supplies token endpoint redirects its `.php` URL to the extensionless URL, then returns 401 without a logged-in session. The EIS-DOCS token endpoint also returns 401 without a session. These requests do not establish whether token issuance works for logged-in users, but the public WebSocket routing failure is independently reproducible without a token.

Production DNS resolves `doc-eis.nmu.ac.th` to `10.10.10.6`. The execution host is `LAPTOP-2GKUMUBT`, not the production server. Its local port 4003 serves the development HTTPS backend; this does not establish the transport or health of the production Node process.

## Prepared changes

- `app/public/web.config`: add the production-only presence proxy before generic routes. `/eis-docs/presence` and its trailing-slash variant rewrite to `http://127.0.0.1:4003/presence`. The hostname condition leaves the development HTTPS transport unaffected.
- `scripts/Repair-ProductionMessengerProxy.ps1`: verify the local production HTTP backend first; resolve the actual IIS application directory; back up web.config and IIS configuration; enable ARR proxy and application WebSocket at the server configuration level; insert the proxy rule; run the public handshake diagnostic.
- `scripts/Configure-ProductionMessenger.ps1`: retain correct ordering of an inherited-rule removal before its replacement.
- `scripts/Test-ProductionMessenger.ps1`: report failure instead of exiting successfully when local backend health or the public upgrade fails.

PowerShell syntax and XML validation passed. Rule checks passed for both presence paths, the production hostname and isolation from existing login, notifications and PDF routes. Production has not yet been repaired or verified: no remote administrative connection to `10.10.10.6` is available in this session.

## Apply on the production IIS server

Copy the updated repair and diagnostic scripts to the production checkout. In an Administrator PowerShell on the **production server**, run:

```powershell
& 'D:\EIS-DOCS\scripts\Repair-ProductionMessengerProxy.ps1'
```

This script resolves the real IIS path; it does not assume that production uses the development physical directory. It fails before modifying IIS if `http://127.0.0.1:4003/health` is unavailable. In that case, inspect the existing service configuration and task: production Node must use `transport: "http"`, the existing private storage directory and port 4003, while IIS terminates TLS. Do not create new secrets or replace existing chat/history storage.

Success requires both a healthy local backend and a public WebSocket HTTP 101 upgrade. Finally check an authenticated Supplies/EIS-DOCS browser session for `authenticated` and the disappearance of the reconnect status. The handshake diagnostic alone does not verify user-token authentication.
