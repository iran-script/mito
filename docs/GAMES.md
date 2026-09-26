# Phase 7 games

Six multiplayer games are playable through Telegram: Rock Paper Scissors, Speed Quiz, This or That, Two Truths and a Lie, Guess Interest, and Guess Number. No game purchase, wallet debit, Gold multiplier, or paid matchmaking priority is used.

## Navigation and matchmaking

Games offers Play With Opponent, Random Opponent, Play With Contacts, Daily Challenge, Leaderboard, My Stats, My Badges, and Back. My Stats also opens My Rank. Opponent/contact selection is paginated; random selection samples eligible users without consulting Gold or balances. Active accounts and profiles, both block directions, and existing invitations are checked. Contacts selection uses the requester's current contact relations. Buttons contain internal action/session references, never Telegram usernames, Telegram user IDs, or coordinates.

Invitations have an Accept button. Both players receive an Open Game notification, and shared rounds notify both when the next round is available. Reopening or accepting again does not reset progress. `/start` and Back to Games clear local game input context without resetting the session or rewarding it. An existing game can be reopened from its notification.

## Gameplay

- **RPS:** private rock/paper/scissors moves; first to two round wins. Ties advance without a round win. Role assignment is based on session participants, not answer arrival order. Callbacks include the round to reject old moves.
- **Speed Quiz:** configured number of server-selected questions (default 10). Both players answer each question; server latency determines the existing speed bonus. Callbacks bind to the current round. Missing answers do not award a result; the overall game deadline and cleanup prevent permanent abandoned sessions.
- **This or That:** both players answer the same configured set (default 10) privately. Completion shows matching answers and compatibility. XP only.
- **Two Truths and a Lie:** each player writes three statements and privately selects one lie; the other guesses, then roles reverse. The existing contact-information guard applies. XP only.
- **Guess Interest:** normalized opponent interests, exactly one real interest plus three unrelated catalog distractors, shuffled on the server. Default five questions per player, alternating symmetrically. No usable interests or too few distractors prevents start; invalidated sessions cannot reward. XP only.
- **Guess Number:** server secret in an inclusive configured range (`GUESS_NUMBER_MIN`, `GUESS_NUMBER_MAX`, default 1-100). The creator starts. Only valid integers within range consume a turn; incorrect guesses return Higher/Lower and transfer the turn. A correct guess records winner/loser and settles once. Input identity and the authoritative turn prevent duplicate progress. The secret is excluded from pre-result presentation.

## Shared results and actions

All six games use the same final result presentation: game name, outcome, relevant score, recorded XP, current rank, and competitive rating delta where applicable. Social games have no rating-change line. Both players receive a queued result with:

- Play Again: one new invitation per previous match, preserving history and rechecking eligibility.
- Add to Contacts: existing ContactService; duplicate safe and blocked peers rejected.
- Request Chat: existing ChatRequestService; provisions an empty wallet when needed so legacy accounts cannot bypass normal paid acceptance. Sending the request does not charge or open a conversation.
- Get to Know Each Other: one-sided choice remains private; mutual choices notify both once. No automatic chat or coin charge.
- Report: existing ReportService with a chosen reason and a safe session reference, without secret/answer-state dumps.
- Back to Games.

Domain idempotency is transactional. Telegram transport remains at-least-once across an ambiguous network failure; this does not duplicate game rewards or mutual match state.

## Daily Challenge

One shared, server-snapshotted question per application calendar day, excluding This-or-That questions. Inline answers use a compact date and an option index. The server evaluates Correct/Incorrect and grants **10 completion XP for either valid outcome**, once per user/day. The first valid answer is final; retries cannot change it or earn more. Wrong/expired callback dates and invalid indices do not reward. The old Complete Challenge callback only opens the question. Gold and balances have no effect; no coins or rating events are created.

## Cleanup and production scheduling

`php artisan games:cleanup` expires waiting, accepted, and active sessions whose deadline has elapsed. Legacy sessions without a deadline expire after 24 hours without an update. It clears all `game_*` input contexts pointing at expired, cancelled, or completed sessions. Cleanup is idempotent and grants no XP or rating.

The Laravel schedule registers cleanup every minute with overlap prevention. It also registers `games:weekly-badges` on Monday at 00:05 in the application timezone. To operate in production, configure one runner such as:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Queue workers are also required for Telegram delivery. **The schedule is registered and tested locally; no production scheduler or cron deployment was performed.**

## Phase 8 operational controls

Moderators/super admins can view session type, status, participant references, round and timestamps without viewing secret/answer state. An explicit reasoned cancellation locks an unfinished session, marks it cancelled, clears its matching game interaction contexts, queues notices and appends an audit. It awards no XP/rating/coins and preserves history; completed sessions cannot be cancelled through this action.

Database settings game_enabled_<game_type> and game_enabled_daily_challenge default to enabled when absent. Disabling a multiplayer type rejects new invitations and acceptance of waiting invitations. Already active games may finish with their original mechanics/rewards; use explicit cancellation for a stuck game. Daily disable rejects both opening and answering, without awarding a reward. Re-enable restores access.

Question counts, technical timeouts and cleanup thresholds remain in their existing PHP configuration to preserve Phase 7 snapshots/behavior. Quiz and This-or-That content have separate authenticated resources. Questions require nonempty text and distinct nonempty options (2–6 for quiz, exactly 2 for This-or-That); quiz correct_option must match exactly one option. Existing round snapshots are preserved. Speed Quiz excludes social questions from its pool. No panel action edits user XP, rating or badge codes.

The existing games:cleanup production schedule recommendation is unchanged. Scheduler deployment is outside this verification; no production scheduling changes were made.

## Persian presentation

Persian is the default product language. Internal codes and domain rules remain unchanged. See [localization policy](LOCALIZATION.md) for RTL, local Vazirmatn font, translation architecture, Tehran display time, Gregorian dates and numeric conventions.
