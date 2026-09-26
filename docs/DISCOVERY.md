# Discovery schema and privacy decisions

Phase 2 adds one migration, `2026_09_17_000003_create_discovery_tables`.

`profiles` now has nullable `latitude`, `longitude`, and `location_updated_at`. Latitude and longitude have PostgreSQL range checks and a composite B-tree index. The application currently calculates Haversine distance in PostgreSQL because the supplied PostgreSQL image does not include PostGIS. `LocationService` is the boundary for storage, so a later PostGIS geography column and spatial index can replace the implementation without changing Telegram or public profile contracts.

`user_blocks` is a minimal two-column relation with a composite primary key and reverse lookup index. Discovery excludes both a requester’s blocked users and users who have blocked the requester. A full block/report workflow is deliberately deferred.

`discovery_histories` stores only requester, discovered user, discovery type and timestamp. It is indexed by requester/type/time for anonymous recent-history suppression and by discovered user/time for later analytics.

`DiscoveryService` applies the same eligibility predicate to every mode: active account, active completed profile, selected gender, not the requester, and no block relationship. City, age, new-user, interest and distance modes use database pagination with five rows per page. Age uses birth-date bounds derived at query time; new-user recency is controlled by `DISCOVERY_NEW_USER_DAYS` (default seven). Nearby ranges use server-calculated kilometers and return rounded distance only.

`PublicProfile` is an explicit DTO. It includes name, calculated age, gender, city, normalized interests, optional file IDs for media delivery, activity wording based only on `last_activity_at`, and optional rounded distance. It does not include `User`, Telegram identifiers, birth date, latitude, longitude or location timestamps. Callback data carries only a revision, mode, gender, interest/range identifier and page; webhook validation allowlists every form.

Telegram discovery is routed through `DiscoveryInteraction`; SQL remains in `DiscoveryService`. The active-user menu exposes Search People, Anonymous Search and Nearby. Placeholder profile actions return a clear “coming later” message and do not create chat, direct-message, contact, block or report side effects.
