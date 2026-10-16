# Inbox connection diagnostics — 2026-10-14

## Verified observations

The production WebSocket probe from this development computer to `wss://doc-eis.nmu.ac.th/eis-docs/presence`, with Origin `https://doc-eis.nmu.ac.th`, timed out during the opening handshake after 10 seconds. An unauthenticated token request returned HTTP 401, as expected. These checks do not prove authenticated token issuance works and do not isolate the failure to IIS, network, or the Node backend. The older report of HTTP 404 is historical, not the result of this check.

The inbox previously displayed the disconnected message during `connecting` as well, and the client discarded token/connection errors. This made the screenshot insufficient to diagnose the actual cause. This connection uses Messenger Presence, not the `IPAPIBG` budget API.

## Changes

- Separate connecting, disconnected and session-ended states; replay the current connection state to newly attached views.
- Add show/hide log, download JSON and manual reconnect buttons in the administrator inbox.
- Keep the most recent 100 diagnostic events in memory: sanitized endpoint URLs, token HTTP status, timeout stage, WebSocket close code and retry delay.
- Do not record tokens, URL credentials/query/fragment, conversation messages, user identities, raw server close reasons or raw exception text. Reloading clears the log.
- Preserve automatic reconnect, token renewal, session replacement and stale callback guards. Manual retry cannot revive an ended session.
- Update asset versions to avoid cached client/UI code.

## Deploy

Back up the matching production files, then copy the five application files from the release `app/` directory to the actual EIS-DOCS application directory, preserving relative paths. Do not replace deployment configuration, secrets, or chat storage. Reload the inbox and press **แสดง log การเชื่อมต่อ**, then **ลองเชื่อมต่อใหม่** and download the log.

If the log reaches `websocket / CONNECT_ERROR` after token HTTP 200, inspect the production public proxy, TLS/Origin and Messenger backend. Run `scripts/Test-ProductionMessenger.ps1` ON the production IIS server. Its health and public upgrade checks distinguish backend failure from public routing failure. If the backend is healthy but the public upgrade fails due to IIS routing, the existing `scripts/Repair-ProductionMessengerProxy.ps1` backs up IIS and web.config and repairs the route. Do not run a production repair against the development machine.

HTTP 503 at the token stage points to server configuration or access to the private presence directory/secret; HTTP 401 requires login; close code 1008 can indicate authentication/policy rejection and needs server logs. Browsers do not expose handshake HTTP status through WebSocket error events.

## Validation

48 connection client tests passed, including diagnostic redaction, bounded log history, manual reconnect cancellation and stopped-session behavior. Inbox control tests cover state text, log toggle/update, download and retry. PHP lint and JavaScript syntax checks passed. Production configuration has not been changed and an authenticated production browser session has not been verified.
