# Phase 3 social workflows

Chat requests are persisted in `chat_requests` with pending, seen, accepted, rejected, cancelled and expired states. Creation rejects self, inactive, blocked, reverse-blocked and already-conversing users. Active requests expire after `CHAT_REQUEST_EXPIRY_HOURS` (72 by default). Opening the recipient’s Chats view marks pending requests seen idempotently. Acceptance locks the request, checks expiration and ownership, calls `PaidActionGate`, and creates the canonical two-user conversation in the same transaction. The application binds `PaidActionGate` to the existing `WalletPaidActionGate`; configured chat acceptance pricing remains authoritative. Rejection, acceptance and expiry are terminal state transitions.

Conversations are identified by the sorted pair `(user_low_id, user_high_id)` with a unique constraint, so there is one canonical history for a pair. Participants are exactly two rows. A conversation can be active, closed or blocked. Chat messages support text, Telegram photo file IDs and Telegram voice file IDs. Application persistence is the sent state. The social outbox is queued after persistence; `delivered_at` is set only after the Telegram client successfully sends the relay. Delivery failures leave encrypted outbox payloads recoverable. Opening a conversation marks only incoming messages read and updates the participant cursor; it does not claim that Telegram reported a human read the message.

The active bot state is stored in `interaction_states`, separate from registration state. Chat mode relays ordinary text/photo/voice input through `ConversationService`; Back, Close Chat, `/start`, system callbacks and menus are handled as controls and are never relayed. The Chats view orders conversations by recent update and displays unread counts plus public display names. Direct messages are text-only, have their own sent/seen state and idempotency key, and never create a conversation. Recipients can open pending direct messages from Chats; opening is idempotent.

`BlockService` uses the existing `user_blocks` relation. Blocking immediately cancels pending/seen requests and marks an existing conversation blocked in a transaction. Discovery, requests, direct messages and conversation access all check both block directions. Unblock only deletes the relation; it never restores a conversation or resends a request.

Reports are immutable user submissions with enum reasons (harassment, inappropriate content, fake profile, spam, scam, rule evasion and other), optional bounded description, open/reviewing/resolved/dismissed status, and optional safe references to a chat or direct message. Normal users have no status mutation service. Report and block actions remain separate.

Events use the same encrypted social outbox for participant and cancellation notifications; event membership and capacity changes commit before delivery is attempted.

`ContactInformationGuard` is shared by chat and direct-message services. It catches obvious `@username`, `t.me/`, `telegram.me/`, `tg://` and explicit sharing phrases with username-like content. It deliberately does not reject ordinary uses of “idea” or generic “telegram” text. Blocked content is rejected before the paid-action gate and is never queued or delivered. The Share Telegram ID control is visible as a placeholder only; no identity, username, phone number or exact location is revealed.

`PaidActionGate` is the charging seam. Chat acceptance invokes `chat_request_accept`; direct-message send invokes `direct_message_send`. Telegram identity sharing uses a recipient approval request and charges only after both current usernames and an active conversation are verified. Prices are resolved from the economy database and debits occur atomically before activation/reveal.

Telegram notifications use the encrypted `social_outbox` and the existing outbound queue. Notifications cover new requests, acceptance/rejection, new direct messages and new chat messages. They contain no internal database IDs or Telegram identity in human-facing text. Callback data is allowlisted by the webhook request and revision-checked against persisted interaction state.


## Phase 7 post-game social actions

Only participants in a completed game with eligible accounts/profiles and no block in either direction can use post-game actions. Contacts call ContactService and preserve its unique directional relation. Reports call ReportService, preserve the selected enum reason, and store only a safe game-session reference; callback retries do not create another report for that actor/session.

Request Chat calls the normal ChatRequestService and queues its normal request notification. The post-game route provisions an empty wallet through WalletService if the requester has none, preventing the legacy missing-wallet compatibility path from bypassing paid acceptance. Sending is free; acceptance uses configured pricing and the existing atomic debit/conversation transaction. No special free game conversation is created.

Get to Know Each Other stores a private row in `game_social_intents`. One user's press sends **no opponent notification** and changes no opponent/public profile, leaderboard, contact list, or result screen. When both participants opt in, a session-row lock records `mutual_match_at` once and queues one encrypted `game_mutual` outbox notification to each. It says both want to get to know each other and provides Request Chat. It creates neither a chat request nor a conversation and charges no coins. Repeated presses are idempotent.

Game result, turn, invitation, and mutual notifications recheck session status, account status, and both block directions before delivery. Expired/cancelled game notices are suppressed. Telegram delivery can repeat across an ambiguous external send failure; private intent, match state, and rewards remain transactionally idempotent.
