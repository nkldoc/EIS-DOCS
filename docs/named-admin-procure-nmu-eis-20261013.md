# Named Admin identities — Procure / NMU-EIS — 13 October 2026

Verified Admin sessions (i_type_user=2) must choose an operator name before entering the main app or obtaining Messenger tokens. Example: admin-art becomes admin-art-procure / admin-art-NMU-EIS; the signed UID becomes account-id@admin-art. Ordinary users retain their database name and numeric UID. These Admin aliases do not grant EIS-DOCS Admin privileges.

The operator is preserved only when the verified login targets the same account and that account is still an Admin. Another account cannot inherit it. Choosing a different name revokes the old Messenger identity; choosing the same name is a no-op. NMU-EIS local online tracking also shows the named identity.

## Files for production
Procure:
- conf/presence/AdminIdentity.php (new)
- access/admin-identity.php (new)
- conf/presence/Presence.php
- access/presence-token.php
- index.php
- access/login.php, login_1.php, login_2.php, login_3.php
- assets/js/presence-client.js

NMU-EIS:
- conf/messenger/AdminIdentity.php (new)
- access/admin-identity.php (new)
- conf/messenger/Presence.php
- access/messenger-token.php
- index.php
- access/login.php, login_1.php
- access/presence_common.php, presence_token.php
- assets/messenger/js/presence-client.js
- js/presence-client.js

Deploy with the Messenger files/configuration documented in procure-messenger-20261013.md and NMU_EIS/docs/nmu-eis-messenger-20261013.md. No SQL migration or Node service change is required for this increment. Asset version is 20261013-admin-identity.

## Validation
- test-admin-operators.js: 32 checks passed
- test-admin-identity-pages.js: 20 checks passed, including CSRF, anonymous access, non-admin access and invalid names
- test-procure-token.js / test-nmu-eis-token.js: scoped signed identity and no Admin privilege escalation
- test-chat-websocket.js: named Procure/NMU-EIS offline Tickets, Admin reply, replay and denied inbox access
- PHP and JS syntax checks passed

Browser acceptance: sign in as a shared Admin account in two separate browsers; choose admin-art and admin-eak. Check separate online rows and separate requester identity in Tickets. Return to the same app/account and confirm the name is retained. Switch to a different account and confirm it does not inherit the previous name.
Production has not been deployed; real-account browser acceptance remains.
