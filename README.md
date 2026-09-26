# Telegram social bot

Laravel 12 / PHP 8.3+, PostgreSQL 17, Redis 7, Laravel Queue. Phases 1–7 are implemented. Phase 8 adds a protected Filament administration panel, audited moderation/economy controls and abuse restrictions. No production payment gateway is included.

## Local development

1. Install PHP 8.3+ with intl, pdo_pgsql, mbstring, openssl and the normal Laravel extensions; install Composer and Docker.
2. Copy `.env.example` to `.env`; run `composer install` and `php artisan key:generate`. Replace the `DB_PASSWORD` placeholder and configure local secrets in `.env` before connecting to services; never commit `.env`. CI generates its own temporary database password.
3. Start dependencies: `docker compose up -d postgres redis`.
4. Run `php artisan migrate` and `php artisan db:seed`. Seeds are repeatable: 4 provinces, 6 cities, 10 interests.
5. Run `php artisan serve` and two worker processes:

```sh
php artisan queue:work database --queue=telegram --timeout=60
php artisan queue:work database --queue=telegram-outbound --timeout=30
```

Admin login requires persistent sessions: use `SESSION_DRIVER=database` and run the migrations, including the sessions table. Do not use the array session driver for the web panel. See [admin deployment and login verification](docs/ADMIN.md#persistent-login-sessions-and-deployment) for Docker assets, cookies, configuration caches, and PHP intl setup.

PostgreSQL uses localhost:55439; Redis uses localhost:56380. The example database credentials are development-only. No Telegram request can authenticate until `TELEGRAM_WEBHOOK_SECRET` is set. Set `TELEGRAM_BOT_TOKEN` to enable outbound delivery. Never commit `.env`.

The implementation session used isolated containers named `bot2-postgres` and `bot2-redis` on those ports. If they are still running, use them directly, or stop them before starting Compose dependencies. Do not remove existing database volumes to resolve a port conflict.

## Telegram setup

Expose `/api/telegram/webhook` on a public HTTPS endpoint. Configure Telegram `setWebhook` with:

- `url`: your HTTPS URL followed by `/api/telegram/webhook`
- `secret_token`: exactly `TELEGRAM_WEBHOOK_SECRET` (use a random 32+ character value from A-Z, a-z, 0-9, underscore and hyphen)
- `allowed_updates`: `["message", "callback_query"]`
- `max_connections`: `1` for ordered receipt in this first phase

See the [official Telegram webhook contract](https://core.telegram.org/bots/api#setwebhook). The API compares `X-Telegram-Bot-Api-Secret-Token` in constant time. Only human senders in their own private chats are accepted. Unknown update types are acknowledged without side effects. Invalid supported payloads return 422; missing/wrong secrets return 403. Database failures return non-2xx so Telegram can retry. No live webhook was registered during development.

## Discovery

An active profile receives a compact menu with Search People, Anonymous Search and Nearby. Search People supports same city, exact same age, profiles completed within the configurable seven-day new-user window, normalized interest matching and distance ranges of 0-5, 5-10, 10-15, 15-20 and 0-20 km. Gender is selected before each search. List results are five per page and sorted by bot activity (`last_activity_at`); anonymous search returns one candidate and records recent history so alternatives are preferred. Activity labels describe only interaction with this bot and never Telegram presence.

Discovery filters require an active account, an active completed profile, a valid gender and the requester relationship. The requester is excluded, as are both directions of `user_blocks`. Public cards include display name, calculated age, city, normalized interests, optional Telegram file IDs sent as media, approximate distance and bot activity. Telegram IDs, usernames, birth dates and exact coordinates never enter the public DTO or callback data. Chat, direct message, contacts, block and report buttons use their existing domain services.

Locations are stored privately as nullable decimal latitude/longitude with `location_updated_at`, range checks and a composite index. A Telegram location update stores the point and returns a privacy notice; users without a point are excluded from nearby search. The current PostgreSQL image does not provide PostGIS, so nearby queries use a server-side Haversine expression. A future PostGIS migration can replace these columns with a geography point and GiST index without changing the `LocationService` or discovery result contract. Distances are rounded to one decimal place for presentation and are never accepted from the client.

`discovery_histories` stores only requester, discovered user, type and timestamp. `user_blocks` now also drives transactional blocking for requests, direct messages and conversations. Chat requests, canonical two-person conversations, bot-level read state, direct messages, reports, interaction state and encrypted social notifications are documented in [docs/SOCIAL.md](docs/SOCIAL.md). Callback payloads use the existing revision scheme, have an allowlist in webhook validation, and carry only short action tokens—not Telegram identity or exact coordinates.

Send `/start`, then name, Gregorian birth date (`YYYY-MM-DD`), gender and city. A birth date replaces a bare age so the displayed age stays accurate. The minimum age is 18, including birthday/leap-day boundaries; maximum is 120. Photos, voice introductions (up to 120 seconds) and interests are optional. Use buttons or `/skip` for optional steps, and Back or `/back` to revisit a step. Preview offers Edit buttons for each field. Editing an earlier field proceeds through subsequent steps again; existing values remain until replaced or explicitly skipped. Only Confirm activates a complete profile. `/start` resumes a draft and welcomes an active profile without resetting it. Prompts are English in this phase; names support Unicode.

## Architecture

- `app/Domain/Users`: Telegram identity, account status and bot activity. Account status is independent of profile completion.
- `app/Domain/Profiles`: normalized geography/interests, profile/state models, enums, registration input DTO, ownership policy, registration action and an explicit public profile DTO.
- `app/Domain/Telegram`: request validation, sanitized update DTO, inbox acceptance action, processing/delivery jobs, Telegram-specific presentation and one injectable HTTP client.

The controller only accepts validated input and delegates. Registration business rules live in `AdvanceRegistration`; no Telegram HTTP calls occur there. `ProfilePolicy` enforces active-account ownership. Filament administration uses a separate admin_users identity and admin guard, with role-checked domain actions. See [ADMIN](docs/ADMIN.md) and [MODERATION](docs/MODERATION.md).

Telegram registration state and revision are persisted in PostgreSQL; the admin panel uses separate authenticated web sessions. Every button is allowlisted and revision-checked, then validated against the current step and existing city/interest rows. The database enforces unique Telegram IDs, one profile and registration state per user, unique interest pivots, foreign keys, status/gender checks and completeness of active profiles. Useful indexes cover activity, geography and pending transport records.

Incoming updates have unique `update_id` keys. Acceptance and database-queue insertion share one transaction. A per-sender PostgreSQL advisory lock plus a user row lock serializes processing; pending received updates are processed in update-ID order. Registration changes, outbox records, their delivery jobs and the processed marker commit together. Jobs contain only record IDs. Redis is configured as the cache backend; the durable Telegram queues intentionally use PostgreSQL to avoid a PostgreSQL/Redis dual-write gap. Keep the queue connection on the same PostgreSQL database (`DB_QUEUE_CONNECTION` unset); changing to Redis requires an explicit durable dispatcher.

A separate outbound queue serializes each sender's messages and retries failures. Successful outbox rows are not sent again. **Telegram send methods offer no application idempotency key:** if Telegram accepts a message and the process dies before committing its sent marker, retry can duplicate that response. Domain effects still remain idempotent. Network calls are never retried inside the registration transaction. Keep worker timeouts below the queue's 90-second retry-after value.

Incoming processing keeps only fields required by registration, encrypts pending inbox/outbox payloads using `APP_KEY`, and clears payloads after processing/delivery. Raw update/message bodies and token-bearing HTTP errors are not logged. Keep the encryption key stable and backed up. Unsucceeded payloads remain encrypted for recovery; establish an operational retention policy before production. Deduplication keys remain retained. Public presentation explicitly includes name, calculated age, gender, city and interest names; it never serializes the user model, Telegram identity, birth date or coordinates. Media uses Telegram file IDs without local downloads.

For later location support, add a private one-to-one `profile_locations` table keyed by `profile_id` with a PostGIS geography point, consent/precision and recorded timestamp. Keep it outside the public DTO; future queries should return distance buckets only. Exact coordinates, when provided, remain private. Wallet pricing and membership entitlements use their existing domain modules with database-backed, audited admin configuration.

## Verification

Create a **separate disposable** `bot2_test` PostgreSQL database (for Compose: `docker compose exec postgres createdb -U bot2 bot2_test`). Tests force PostgreSQL and that database name; the host, port and credentials come from the environment. Do not point tests at real data.

```sh
php artisan test
php vendor/bin/pint
php vendor/bin/pint --test
composer validate --strict
```

Tests use fake Telegram transport and fake HTTP responses; they never contact the live Bot API. The persistence test commits requests and boots a new application instance. Queue tests execute serialized jobs through the real database queue. `.github/workflows/tests.yml` defines the PHP 8.3/PostgreSQL check; local execution evidence is recorded in `docs/VERIFICATION.md` (the hosted workflow has not been run).

## Docker and operations

`docker compose up -d --build` provides PHP-FPM, Nginx on localhost:8080, PostgreSQL, Redis and separate workers. Run `docker compose exec app php artisan migrate --force` and `docker compose exec app php artisan db:seed --force` after startup. Terminate HTTPS at a trusted reverse proxy; replace development credentials, set a stable APP_KEY and Telegram secrets, and keep APP_DEBUG=false. The image installs production dependencies only. Standard output/error collection and database backups belong to the deployment environment.

Inspect `php artisan queue:failed`; correct credentials/connectivity or malformed data and use `php artisan queue:retry <uuid>`. Five real delivery exceptions fail a job; waiting for earlier messages does not exhaust retries. A failed earlier outbox delivery holds later messages for that sender, preserving order; alert on aged pending outbox rows and failed jobs, then recover the earliest failure. Expired callback acknowledgements (HTTP 400) are treated as obsolete so they do not block messages. Telegram 429/5xx errors use bounded job backoff. Do not manually mark an unsent profile response as sent without reviewing the failure.

Remaining deployment work: provision HTTPS/secrets/backups/monitoring and configure the real bot. Later product work includes localization, larger paginated city catalogs, Filament staff administration UI, retention tooling and all explicitly deferred modules. The Phase 4 economy domain and charging hooks are implemented; external payment integration and authenticated Filament resources remain deployment work. `RTK.md` was referenced by the supplied instructions but was absent from the empty workspace.

See `docs/FILES.md` for the complete source-file inventory.
See `docs/WALLET.md` and `docs/ECONOMY.md` for the Phase 4 coin ledger, pricing and package boundaries.
See `docs/ADMIN.md` for the separate staff authentication foundation and Filament installation blocker.
See `docs/GOLD.md` and `docs/CONTACTS.md` for Phase 5 membership, bulk-action, and private-contact behavior.
See `docs/EVENTS.md` for the Phase 6 event lifecycle, capacity, location, membership, and notification rules.
See `docs/GAMES.md` and `docs/RANKING.md` for the free game-session lifecycle, XP, competitive score, badges, and anti-pay-to-win rules.
The Events Telegram integration includes a persisted creation wizard, five-item filtered listings, nearby ranges, participant and organizer actions, idempotent reports/cancellation, and the `events:send-reminders` queue foundation.


## Final Phase 7 closure

All six existing multiplayer games have Telegram invitation, gameplay, and shared post-game result flows. The Games hub includes paginated opponent/contact selection, random eligible opponents, a daily question with server-validated answers, six leaderboard views, rank progress, full stats, and earned badges. Post-game controls reuse contacts, paid chat requests, and reports; mutual interest remains private until both opt in. Games and Daily Challenge consume no coins, and Gold/balances give no competitive advantage.

See [GAMES](docs/GAMES.md) for flows and cleanup, [RANKING](docs/RANKING.md) for UTC/Monday period semantics, thresholds and ledger representation, [SOCIAL](docs/SOCIAL.md) for mutual-interest privacy and normal paid chat acceptance, and [VERIFICATION](docs/VERIFICATION.md) for the final local quality gate.

`games:cleanup` is registered every minute; `games:weekly-badges` is registered Mondays at 00:05 UTC. Registration is not deployment: no production scheduler/cron was installed or started in this closure. Configure a single `schedule:run` runner and the existing Telegram queues in production. No Phase 8 features were added.

## Phase 8 administration

Open `/admin` after creating staff with `php artisan admin:create --role=super_admin` in a controlled terminal. There is no public signup. Filament 5.8.2 is installed with security checks enabled. See [administration](docs/ADMIN.md), [moderation](docs/MODERATION.md), [economy](docs/ECONOMY.md), and [verification](docs/VERIFICATION.md).

The host PHP CLI must load intl. If it is disabled in the local ini, use `php -d extension=intl vendor/bin/phpunit` for tests; `php -d extension=intl artisan test` starts a child PHP process which may not inherit that setting. Docker already enables intl. No live Telegram or payment-provider calls are needed for tests.

## Persian presentation

Persian is the default product language. Internal codes and domain rules remain unchanged. See [localization policy](docs/LOCALIZATION.md) for RTL, local Vazirmatn font, translation architecture, Tehran display time, Gregorian dates and numeric conventions.
