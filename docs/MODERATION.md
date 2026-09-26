# Moderation operations

Authorized moderators and super admins use `ModerationService`; all actions require a nonblank reason (maximum 2,000 characters) and commit with an audit record. Actions set the existing active/suspended/banned account enum, never delete a normal user. Deleted accounts cannot be restored through this workflow. Repeating the same status is a no-op.

Status checks use fresh database state for discovery, request creation/acceptance, direct sends, conversation access and writes, events, bulk actions and games. The Telegram update handler rejects inactive users before entering any wizard. Suspension clears the user's chat/direct/game/event/bulk interaction context. Existing conversations remain recorded but become unavailable while a participant is inactive; restoring status restores eligibility without deleting history. Existing game expiry/block/status rules still apply. Domain denials become Telegram messages and processed updates rather than retrying poison jobs.

Account suspension/ban/restoration queues a generic social-outbox notice. Internal reasons and notes are never included. Delivery uses the existing durable queue; no new direct Telegram transport was introduced.

## Reports

Both `reports` and `event_reports` support open, reviewing, resolved and dismissed. A moderator can update status, assign an active moderator/super-admin and replace an internal note (maximum 5,000 characters). Every change is audited. Reports can lead to a separate audited user suspension or ban. Notes and assignment are excluded from model serialization and normal Telegram presentation.

Text inspection is limited to the report's referenced message and validated sender/recipient or conversation membership. Media is indicated without embedding raw Telegram file identifiers. Game reports retain existing safe references in the report description; no secret number, private answer snapshot or one-sided social intent is added to the panel. Blocks are visible as internal references in a read-only resource; staff cannot override the user's privacy control.

## Restrictions

`user_restrictions` supports `bulk_messaging_disabled`, `direct_messaging_disabled`, `chat_requests_disabled`, `game_invites_disabled` and `event_creation_disabled`. Each record has user, moderator, reason, starts_at and optional ends_at. It applies for starts_at <= now < ends_at, or indefinitely when ends_at is absent. Expiry needs no scheduler. Lifting a restriction sets its end time and is audited.

Restrictions are checked by the actual authorization/services, including Gold bulk operations. A direct restriction also prevents bulk direct sends, and a request restriction prevents bulk requests. Game-invite restrictions affect new invitations; active-game mechanics are unchanged. Restriction reasons remain private; the user receives a generic restricted-action response.

Event cancellation invokes the existing creator cancellation service on behalf of the authorized moderator, preserves participant history and queues the existing participant notices exactly once by outbox key. No event history is hard deleted.
