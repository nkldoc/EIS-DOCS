# Procure Messenger / Ticket — 2026-10-13

Procure uses NMU_PROCURE_SESSID and a signed PROCURE identity. It cannot gain EIS-DOCS admin privileges. Background token renewal preserves the existing two-hour idle timeout; logout and timeout revoke the Messenger session. Users open Ticket from the existing Messenger button. Offline tickets and replies persist in the shared chat store.

## Deploy files
- Procure: index.php, conf/config.php, access/logout.php, access/presence-token.php, conf/presence/Presence.php
- Procure assets: assets/js/presence-client.js, presence-chat.js, presence-image-viewer.js; assets/css/presence-chat.css
- Shared service: app/services/presence/server.js, store.js, chat.js
- Admin online page: app/public/docMain/user-online.php, user-online.js

## Configuration
Create a cryptographically random procure.secret (64 hexadecimal characters) in the existing private presence directory. The Procure PHP process and the shared Node service must read the SAME file. Do not put it under a public web root. No secret content is included in this document.
Procure supports EIS_DOCS_PRESENCE_DIR, EIS_PRESENCE_WS_URL and EIS_PRESENCE_CONFIG (conf/presence/presence.config.php). Follow the existing Supplies production configuration and permissions. Production requires an explicit private presence directory configuration.
Deploy all files, then restart the shared Messenger service. Existing clients reconnect automatically. No SQL migration required.

## Validation
npm.cmd test --prefix D:/EIS-DOCS/app/services/presence
node D:/EIS-DOCS/app/services/presence/test-chat-websocket.js
node D:/EIS-DOCS/app/services/presence/test-procure-token.js

Tests cover Procure identity isolation, offline Ticket creation, Admin claim/reply/close, replay after reconnect, denied Admin inbox access, token signature and passive idle expiry.
Browser acceptance: log in to Procure, open Messenger > Ticket, send text/image; Admin chooses Procure in inbox, replies, and requester receives reply. Confirm logout and idle timeout end Messenger access.

Local development secret is provisioned and port 4003 service reloaded. Production has not been deployed.
