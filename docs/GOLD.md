# Gold membership and bulk actions

Gold is represented by time-windowed `memberships` rows, not a boolean on users. A user is Gold only when an active gold row covers the current time, so expiry is correct without a cron job. The Phase 8 finance panel grants and revokes memberships through the existing `GoldMembershipService`.

Active Gold profiles are prioritized in list discovery using a database `exists` expression, then the existing activity ordering. Anonymous discovery remains random. Gold does not bypass blocks, moderation, privacy, or contact filtering.

Bulk chat requests are free to send; each later acceptance uses the normal configured chat-acceptance charge. Bulk direct messages pre-validate eligible recipients, run `ContactInformationGuard`, calculate the current per-recipient price, lock/debit the sender wallet once, then create separate direct-message rows. Insufficient balance sends none and charges nothing. Retry keys prevent duplicate requests, messages, charges, and usage records.

Gold limits and cooldowns are stored in `economy_settings`: maximum recipients per action, daily successful chat/direct recipients, cooldown seconds, priority toggle, and duration defaults. Invalid or blocked recipients do not consume quota. `gold_usage_events` records successful recipient counts and idempotency keys. The protected Gold settings page edits these values through audited finance actions.

Telegram discovery pages expose Gold bulk-selection controls. Selection is persisted in `interaction_states` rather than callback payloads. Direct-message previews recalculate eligible recipients, current price, and wallet balance at confirmation; final execution repeats membership, block, guard, quota, and price checks.

## Phase 8 administration

Finance admins and super admins can grant Gold for 1–3,650 days, use the database default duration when omitted, inspect source/start/end, and cancel a membership. Every grant/revoke requires a reason and audit. Existing overlapping memberships remain independent: revoke each active grant when all access must end. Expiry is time-aware without a cron job.

The protected Gold settings page manages gold_duration_days, gold_bulk_max_recipients_per_action, gold_bulk_chat_requests_per_day, gold_bulk_direct_messages_per_day, gold_bulk_actions_cooldown_seconds and gold_priority_enabled. Values are bounded server-side and audited. The priority toggle affects ordinary discovery ordering only; games, competitive matchmaking, XP and rating remain unchanged. Moderation restrictions override Gold bulk eligibility.
