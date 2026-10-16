# WebSocket review ? 13 October 2026

## Findings and fixes
- Presence clients used global socket references in asynchronous callbacks. Each connection now has a captured socket and generation; stale callbacks cannot close or overwrite another connection.
- Token renewal used async intervals. It now runs serially, with one in-flight fetch, an 8-second request timeout and a 10-second connection/authentication deadline.
- Retry delay was fixed. Reconnect now backs off from 3 to 30 seconds plus up to 20% jitter, and pauses offline / during pagehide. BFCache restores one connection. Logout cancels pending authentication and renewal.
- NMU-EIS opened a new Socket.IO connection whenever the notification panel expanded. It now reuses the one notification socket. The local notification adapter escapes text fields; the old NMU app file is unchanged.
- Supplies considered only OPEN connections when guarding connect clicks. It now also guards CONNECTING sockets and ignores stale callbacks.
- Procure legacy user-online initialization now retains its socket and guards repeated connect calls.
- EIS-DOCS notification fetches now share in-flight requests, enforce timeouts and ignore obsolete socket callbacks. Healthy WebSocket connections have a 60-second safety poll rather than a 10-second poll; fallback checks run every 30 seconds.
- Presence snapshots count connections once per snapshot, and the server builds identical filtered snapshots once per broadcast. Queued stale snapshots/inbox summaries are coalesced.
- Output frames are pumped in order with a bounded 16 MiB queue. Chat frames and acknowledgements are preserved. Oversized queues close with 1013; this avoids both unbounded memory use and rejecting legitimate image history prematurely.
- Shared server validates token size and identity format, enforces Origin/signature/session checks, and limits requests to 120 / 10 seconds and expensive queries to 10 / 10 seconds per connection.
- Both Presence services bind their port BEFORE recovering history. A duplicate process exits without modifying the active service's history. Startup scripts probe local ports and report startup failure.
- NMU-EIS socket-script availability checks have a 1-second timeout.

## Expected connections
One connection per service per page is intentional. Different browser tabs remain independent.
EIS-DOCS Messenger and workflow notifications are separate services. NMU-EIS central Messenger, local online tracking, and the optional old Socket.IO notification service are also separate. They are not duplicate connections to the same endpoint.

## Deploy files
Shared EIS-DOCS:
- app/public/assets/js/presence-client.js
- app/src/Presence.php
- app/public/docMain/app.js, index.html
- app/services/presence/server.js, store.js, start-local.ps1

Supplies:
- assets/js/presence-client.js
- conf/presence/Presence.php
- websocket.js

Procure:
- assets/js/presence-client.js
- conf/presence/Presence.php
- js/show_nat.js

NMU-EIS:
- js/presence-client.js, show.js, notification-socket.js (new local adapter)
- assets/messenger/js/presence-client.js
- conf/messenger/Presence.php
- index.php
- services/presence/server.js, store.js, start-local.ps1

Asset version: 20261013-ws-safe. Deploy PHP/HTML and client assets together, then restart the corresponding Presence services. Keep the existing private secrets, history and chat data. No SQL migration required.

## Validation
Automated tests cover duplicate script loads, slow requests, successful renewal, stale callbacks, logout, BFCache, offline recovery, timeout/backoff, same-origin name redirects, duplicate panel expansion, escaped text, image queues, stale snapshot coalescing, token signatures, Origin checks, request limits, duplicate service startup and account isolation.
Real-account multi-tab browser acceptance and production load testing remain. JSON persistence is still synchronous; this patch improves connection/request behavior and snapshot processing, without guaranteeing latency at arbitrary production scale. The backend authentication of the separate older notification services is outside the inspected Presence server implementations.
Production has not been deployed.
