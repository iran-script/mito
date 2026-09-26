# Ranking, leaderboards, progression

## Rating and XP

`game_player_stats` stores operational totals: games played, wins, losses, draws, current/best competitive win streak, XP, and competitive rating. Rating starts at 1000. Competitive games are RPS, Speed Quiz, and Guess Number. Wins add 20; losses subtract up to 10 with a zero floor; draws are neutral. The ledger and result snapshot store the **actual applied delta**, including the floor. Wins normally earn 25 XP and other competitive results 10; Guess Number uses its existing XP configuration. Gold and wallet balances never enter these calculations.

This or That awards 15 XP. Two Truths awards its configured completion/correct-guess XP (15 + 5 per correct guess by default). Guess Interest defaults to 15 + 2 per correct guess. These social games increment games played and XP but leave competitive wins, losses, draws, streaks, rating, and rating events unchanged. Daily Challenge grants 10 completion XP once/day and does not count as a multiplayer game.

Settlement snapshots preserve each participant's outcome, XP, and rating change for post-game screens. A session lock and `rewards_settled` marker prevent replayed or concurrent final actions from awarding twice.

## Leaderboard semantics

Application timezone is `config('app.timezone')`, currently **UTC**. The machine's local timezone is not used. Calendar weeks start **Monday at 00:00**. Intervals include the beginning and exclude the next period boundary; timezone-aware boundaries are converted to UTC for ledger queries.

- All Time: current competitive rating.
- Daily: sum of applied rating deltas in the current application day.
- Weekly: sum of applied rating deltas in the current Monday-based week.
- Monthly: sum of applied rating deltas in the current calendar month.
- City: current competitive rating among active players in the requester's profile city.
- Contacts: current competitive rating among the requester's current contacts only. The requester is not implicitly added to their own contacts.

Active accounts with active profiles and a stats record participate. Peers blocked in either direction are hidden from the requester's board. Period boards include eligible players with no changes at zero, ahead of negative totals. Ties use ascending internal user ID as a stable ordering key; IDs are never displayed. Positions are sequential, not shared competition ranks. Gold has no ordering or tie-break effect.

Each page displays position, safe display name, current rank tier, and the view's score. Default page size is 10, bounded at 25. The user's own scoped position is queried separately and shown even outside the page. If the user is outside that scope (for example their own contacts), the screen explicitly says not ranked in that view. Queries paginate in SQL; they do not load all players into PHP.

## Rating-event representation and legacy data

Existing `game_rating_events` remains authoritative. Ordinary events record one player's delta. Guess Number preserves **one settlement event** whose `participant_deltas` JSON records both players' deltas. Period queries expand compound events and union them with ordinary events, without double counting the winner.

The closure migration enriches legacy Guess Number events by replaying the existing local ledger chronologically from the initial 1000 rating and applying the loss floor. This reconstruction assumes the repository's existing rule that game rating changes come from the game ledger; out-of-band manual rating changes are not historical ledger events. Current ratings and rewards are not rewritten by migration.

## Centralized rank thresholds

`config/ranking.php` is the single threshold source; Telegram handlers call `RankingService`:

- Bronze: 0
- Silver: 1000
- Gold III: 1400
- Gold II: 1700
- Gold I: 2000
- Platinum: 2200
- Diamond: 2400

Progress is the floored percentage from the current threshold to the next. The screen shows next tier, threshold and points remaining. Diamond reports highest rank reached, 100% progress and no next threshold.

## Statistics and badges

My Stats reads cached totals and calculates win rate as wins / (wins + losses + draws), with zero for no competitive games. Social games do not dilute that percentage. Per-game played counts use a grouped database aggregation through the indexed participant relation, cached for one hour with the completed-games counter in its key. Completion changes that key immediately. Repeated screen requests reuse the cached breakdown; no session histories are hydrated.

Earned badges show icon, name, description, and earned date in the application timezone. Unique `(user_id, badge_id)` rows preserve original earned dates and prevent duplicates:

- First Win: at least one competitive win.
- On Fire: best win streak at least five.
- 50 Wins: at least 50 competitive wins.
- 100 Games: at least 100 completed multiplayer games.
- Quiz Master: at least ten completed Speed Quiz sessions.
- RPS Champion: ten RPS wins with a stored winner result.
- Top 10 Weekly: a top-ten positive net gain in the previous completed application week, evaluated by `games:weekly-badges`. This is a lifetime badge, awarded once. The award uses a global active-player board, not a viewer's block-filtered board.

Weekly awarding is idempotent. It does not alter XP, rating, or wallets. Its production schedule has not been deployed.
