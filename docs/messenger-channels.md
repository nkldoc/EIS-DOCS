# Messenger: Tickets and general conversations

Implemented on 2026-10-12 in EIS-DOCS and Supplies. Local changes have not been deployed to production.

The Messenger dialog has two tabs with separate conversation selectors and unread counts:

- **Ticket แจ้งปัญหา**: users create support tickets, admins reply, claim, transfer and close them in the support inbox. Existing ticket/private-job records keep their messages, status, assignee, audit and reporting behavior. These legacy private jobs are shown under Tickets.
- **สนทนาทั่วไป**: shared chat, private conversations initiated by an admin, and user conversations with the administrator team. General chat has no ticket status, assignment or close action and is excluded from the support inbox and monthly support reports. Clicking a user name opens their general private conversation, rather than their older support job.

Users can start a general team conversation even if no admin is online. All admins can reply to that team conversation. Each user's team conversation remains separate from other users. A general private conversation is visible only to its two participants; other admins cannot read it. General messages, images, read markers and retractions survive restart, and history uses the existing pagination protocol.

The server advertises `chat-ready.capabilities.conversations`. The client checks this before creating a general conversation, preventing an older server from treating it as a legacy private job. The legacy `target` command without `conversation: true` still creates a private job for compatibility with existing clients. Updated clients explicitly send `conversation: true`; user-to-team creation additionally sends `generalTeam: true`. Ordinary users cannot start arbitrary private conversations with other users.

## Deployment

Deploy the shared service's `app/services/presence/chat.js` and `server.js`, and restart that service through the existing deployment process. Deploy the following client files together:

- EIS-DOCS: `app/public/assets/js/presence-chat.js`, `app/public/assets/css/presence-chat.css`, `app/src/Presence.php`.
- Supplies: `public/supplies/assets/js/presence-chat.js`, `public/supplies/assets/css/presence-chat.css`, `public/supplies/conf/presence/Presence.php`.

Chat JS/CSS version is `20261012-channels`. No manual migration of existing tickets is needed. Back up the chat storage before deployment as part of the normal service release. Once new conversations exist, do not run the previous service against the updated storage: it does not understand the new conversation type. A rollback must use a compatible service or the corresponding storage snapshot.

## Validation

- `npm test` in `D:/EIS-DOCS/app/services/presence`: presence history, permissions, legacy tickets, assignment/closure, separate general rooms, team privacy, persistence, pagination, retraction and report exclusion.
- `node test-chat-websocket.js` in the same directory: real isolated test WebSocket service, offline general team messages, private general rooms, forbidden job actions and existing ticket behavior.
- `node --test tests/presence-messenger.test.cjs` in `D:/EIS-ERP`: UI modes, distinct send commands, capability gating and reconnect/rendering regressions, using a simulated DOM.

These checks do not verify production browser layout or production network latency.
