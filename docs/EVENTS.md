# Events

Events are free, published records owned by a user and categorized through `event_categories`. Telegram exposes an Events menu with nearby, today, this week, newest, category, My Events, Joined Events, and Create Event screens. Lists use five-item pages and short, revision-checked callback tokens.

The creation wizard persists its title, normalized category, description, future start time, optional capacity, city, and optional event-only coordinates in `interaction_states.event_context`. The final Publish callback is the only point at which the event is created. `/start`, Back, and Cancel clear the draft so it cannot trap a user in the wizard.

The creator is the organizer and does not consume capacity. Participants are tracked in `event_participants`; leaving marks history as left and frees a slot. Joins lock the event row, validate status/time/blocks, and count joined participants transactionally so concurrent final-slot joins cannot oversubscribe capacity. User cancellation is creator-only and idempotent; participant history remains available for moderation and audit.

Event coordinates belong to the event and are separate from private user location. Nearby queries calculate Haversine distance on the server and exclude events without coordinates. Only approximate distance is presented. Event reports are idempotent and retained for moderation. Join and cancellation notifications use the encrypted social outbox and existing delivery queue.

`EventReminderService` provides a small 24-hour/1-hour foundation through `events:send-reminders`. `event_reminders` makes each reminder type idempotent and cancelled or past events are skipped. A production scheduler can invoke the command later; reminders are not required for event correctness.

## Phase 8 moderation

Moderators/super admins can inspect events, creator references, participant counts, city, dates and status in Filament; review event reports; cancel an event; or suspend its creator. Cancellation requires a reason, appends an audit record and invokes the existing transactional cancellation/outbox flow. Participant history remains intact. Financial admins cannot cancel events. Inactive users and users with an active event_creation_disabled restriction cannot create events, including through a stale Telegram draft.

## Persian presentation

Persian is the default product language. Internal codes and domain rules remain unchanged. See [localization policy](LOCALIZATION.md) for RTL, local Vazirmatn font, translation architecture, Tehran display time, Gregorian dates and numeric conventions.
