# Messenger channels/bootstrap release — 2026-10-12

The screenshot's "ระบบสนทนาทั่วไปยังไม่พร้อมใช้งาน" appears when the client has not received `chat-ready.capabilities.conversations = true`. There are two supported failure cases: an old/mixed running backend, or a chat script loaded after the connection's initial readiness/history events. The screenshot alone cannot distinguish them. A subsequent public unauthenticated WebSocket probe from the development machine timed out; production readiness is not confirmed.

This release marks late `connected` callbacks with `replay: true` and requests `chat-sync` for those subscribers only. Early subscribers still consume automatic server history without a second request. Connection errors take precedence over the channel-support message. Server health now exposes `protocolVersion: 2` and actual conversation capability, making a mixed deployment detectable.

## Deploy the bundle

The bundle contains only changed source/assets, a restart script and this guide. It contains no secrets, server.config.json, chat/history data, session data or database credentials.

1. Copy `WebSocket-Service/server.js` and `chat.js` to the existing service directory. Resolve it from `(Get-ScheduledTask -TaskName 'EIS-DOCS-Supplies-Presence').Actions.WorkingDirectory`; copying to an unused checkout does not update the running service. Back up the existing files before replacing them.
2. Copy the `EIS-DOCS/app/` and `Supplies/` files over their matching application files. These include the two JS clients, CSS and PHP asset helpers. Keep environment/local configuration files unchanged. Client/chat JS asset version is `20261012-bootstrap`.
3. Run the included `Restart-ProductionMessengerChannels.ps1` in an Administrator PowerShell on the production server. It reads the actual task directory, validates both JS files before stopping the task, restarts it and verifies live HTTP health plus channel capability. This changes no secrets or chat/history storage.
4. Refresh the browser and try a general team message and a Ticket separately.

Do not remove the client capability guard to work around an old backend: that could silently create a legacy private Ticket. Once new conversation data exists, the service must remain compatible with that format.

Validated locally: 14 client tests (including late-script loading), real isolated WebSocket integration with capability health and both message types, PHP syntax and PowerShell syntax. Not deployed from this session: production administrative access is unavailable.
