# Administration (Phase 8)

Filament **5.8.2** is installed for Laravel 12 with the Composer PHP 8.3 platform. Dependency resolution and installation retained Composer's advisory checks and reported no vulnerabilities. PHP `intl` is required; the Docker image already enables it. On the local Windows host use `php -d extension=intl` if the CLI ini does not load it. Do not disable Composer audit or ignore platform requirements.

The protected panel is `/admin`, with `/admin/login` provided by Filament. It uses the existing separate `admin_users` table and `admin` session guard. Telegram profiles and the ordinary web guard cannot authenticate as staff. Passwords use Laravel's hashed cast; login is rate limited by Filament, regenerates the session, and requires an active admin. Panel authorization is repeated on persistent Livewire requests. Standard cookie, session and CSRF middleware remain enabled. There is no public registration or arbitrary staff-management endpoint.

Create staff from a controlled terminal:

```sh
php artisan admin:create --role=super_admin
php artisan admin:create --role=moderator
php artisan admin:create --role=finance_admin
```

The command prompts for name, email, password and confirmation. Passwords require at least 12 characters, are never printed, and are not command-line arguments. Existing admin records retain super-admin capabilities when migrated. Deactivate staff by a controlled operational procedure; an inactive record loses access on its next request. Deploy behind HTTPS with secure session cookies and a stable application key. No production deployment or new staff account was performed during verification.

## Persistent login sessions and deployment

The login repair retains the existing `admin` guard, `admins` Eloquent provider, and `App\Domain\Admin\AdminUser`. The model implements `FilamentUser`; `canAccessPanel()` checks the panel ID, fresh active status, and permitted staff role. No password or access check was changed.

The former `.env.example` and local environment used `SESSION_DRIVER=array`. This discards sessions between HTTP requests, including the CSRF token and authenticated identity. A real HTTP regression reproduced a **419 CSRF token mismatch** on Filament login with that setting; component-only tests did not detect it. Use `SESSION_DRIVER=database` for runtime. Migration `000020` creates the Laravel sessions table with an indexed nullable identity column, without a foreign key to Telegram users. The PHPUnit component suite may still use array sessions; the dedicated HTTP tests explicitly use database sessions.

Apply `php artisan migrate --force`, then `php artisan optimize:clear` after upgrading existing installations and changing their environment. Keep `APP_KEY` stable across requests and application instances. When caching configuration, run `php artisan config:cache` in the deployment environment, not in a build containing another environment's values. Reload long-running application processes after environment changes.

Compose explicitly uses database sessions. Its loopback-only `http://127.0.0.1:8080` development endpoint defaults to `SESSION_SECURE_COOKIE=false`; for production HTTPS, explicitly set `SESSION_SECURE_COOKIE=true` and the public HTTPS `APP_URL`. The nginx `web` build target copies the published Filament assets from the application stage and serves `/css`, `/js`, and `/fonts` directly. Dynamic Livewire routes continue through Laravel. Build both services with `docker compose build app web` when upgrading assets. Do not start outbound workers merely to test admin login.

PHP must load `intl` in the **web server process**, not only the Composer or Artisan parent. With a host PHP installation that does not enable it globally, a local development server can be started from `public` using:

```sh
php -d extension=intl -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

Docker enables intl in PHP-FPM. `tests/Feature/AdminLoginHttpTest.php` exercises actual HTTP cookies, CSRF, the Livewire authentication request, dashboard access on a new request, logout, inactive accounts, wrong passwords, and published assets. It uses only synthetic staff in the disposable `bot2_test` database. `ADMIN_HTTP_TEST_URL` can target an isolated container connected to that same test database; never point it at a production panel.

## Authorization

- Super admin: all capabilities, including the audit viewer.
- Moderator: users, reports, event reports, blocks (read only), restrictions, event moderation, game operational controls and question content.
- Finance admin: wallets, immutable transactions, feature prices, coin packages, purchase orders (read only), Gold memberships and economy/Gold settings. No bans or event cancellation.

`AdminAuthorization` checks a fresh database record in each service call. Hiding buttons is not the authorization boundary. Resources permit only read operations through normal CRUD; all writes are named custom actions backed by authorized domain services. Wallet balances, transaction history, user XP, ratings, badge codes and user blocks have no raw-edit actions. There is no Mark Paid action or production payment gateway.

## Panel capabilities

User views show safe internal reference, display name, gender, age, city, status, completion date, Gold membership, wallet balance, activity and report/block counts. Search and filters cover name, city, status, Gold, recent activity and reported users. Telegram numeric IDs and usernames are omitted. Report inspection renders escaped text and verifies referenced message ownership/participants; game answer state and secrets are not displayed.

Reports support open/reviewing/resolved/dismissed, assignment to an active moderator and private notes. The panel exposes explicit suspend/ban/restore and timed restrictions. Events and games retain history after cancellation. Quiz and This-or-That content have separate resources with service-level validation. Seeded badges are read only.

The dashboard caches eleven aggregate counts for 30 seconds: total and active accounts, completed profiles, current Gold users, open/reviewing reports including event reports, active conversation records, upcoming published events, games created today, wallet coin total, direct messages today and chat requests today. These are operational snapshots, not accounting reconciliation. “Active account” means account status, not currently online; day boundaries use the application timezone.

## Audit

`admin_audit_logs` records actor, action, subject type/reference, required reason, allowlisted action metadata and creation time in the same transaction as each change. Passwords, tokens, game secrets and payment credentials are not collected in metadata. Report audits record that notes changed, not the note contents. Staff must not paste credentials into reasons or notes. IP addresses are not collected.

Both audit rows and coin transactions reject UPDATE and DELETE through PostgreSQL triggers, including query-builder updates. Refunds append compensating entries. Read-only UI is an additional boundary; database owners remain responsible for schema privileges and backups. There is no automatic audit retention deletion. Migration 000019 is additive and supports fresh migration and rollback.

## Persian presentation

Persian is the default product language. Internal codes and domain rules remain unchanged. See [localization policy](LOCALIZATION.md) for RTL, local Vazirmatn font, translation architecture, Tehran display time, Gregorian dates and numeric conventions.
