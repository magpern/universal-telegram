# ADR-0046: Support-desk notification events, native Telegram reply to a ticket, and the open-ticket digest

## Status

**Accepted / Frozen** — 2026-09-19. Architecture and implementation contract for the
Universal Telegram side of the support-desk integration. Extends ADR-0012 (outbound delivery),
ADR-0013 (inbound handling, update dedup), ADR-0015 (event registry), ADR-0016 (rule engine)
and ADR-0017 (PUBLIC-only history); operates within ADR-0044's transport/adapter boundary.
One additive schema change (`db_version` 39 → 40). Plugin version 0.20.4 → 0.21.0.
Counterpart contract in `fluent-imap-support-desk`:
`docs/telegram-lifecycle-hooks-freeze.md` (that repo, same date). DEV only; production is not
authorised.

## Context

Managers have no Telegram visibility of the site's support desk (`fluent-imap-support-desk`,
"FISD"): contact-form submissions, tickets created from imported mail, and customer replies
to existing tickets are visible only in WP-admin. FISD already owns a canonical reply
operation, `Biopentra_Contact_Inbox_Mailer::send_ticket_reply()`, which sends the threaded,
templated customer email, persists the outbound support message, moves the ticket to
`pending` and updates `last_message_at`. Universal Telegram already owns durable outbound
delivery, the rule engine, an operator identity map with capability checks, a DB-level inbound
update dedup, and Action Scheduler job patterns.

Code inspection before freeze confirmed (against current code, not earlier line numbers):

- `send_ticket_reply()` returns `true` on success and a `WP_Error` on failure; the WP-admin
  reply handler calls the same method. One operation, no parallel path needed.
- `WebhookController::process_update()` records every update through
  `UpdateRepository::record()` (`INSERT IGNORE` on `UNIQUE(bot_id, update_id)`) before any
  handler runs, for all update types including plain messages.
- Destination uniqueness is `(bot_id, chat_id, message_thread_id)`. `DestinationRepository`
  (`src/Telegram/Configuration/`) has **no** finder by that triple (`find()` by id and
  `for_bot()` only).
- `outbound_messages` has no index on `telegram_message_id` (only `bot_destination`,
  `status`, `message_uuid`, `created_at`); `OutboundMessageRepository` finders return
  `?OutboundMessage`, mutators return `bool`.
- Periodic jobs use Action Scheduler with group `WorkerRunner::GROUP`
  (`universal-telegram`) guarded by `as_has_scheduled_action()`.

## Decision

### 1. Three support events, mutually exclusive

Registered only when the FISD classes are present (`FluentContactInboxSupport::is_active()`
= `class_exists( 'Biopentra_Contact_Inbox_Ticket_Repository' )`), mirroring
`WooCommerceSupport`:

| Event type | FISD hook | Fired when |
|---|---|---|
| `fluent_contact_inbox.contact_request_submitted` | `biopentra_contact_inbox/contact_request_submitted` | live Fluent Forms contact submission creates its ticket |
| `fluent_contact_inbox.ticket_created` | `biopentra_contact_inbox/ticket_created` | imported email creates a new ticket |
| `fluent_contact_inbox.ticket_reply_received` | `biopentra_contact_inbox/message_added` | imported inbound email joins an existing ticket |

Fields (all three types): `actor.user_id` (INTERNAL, existing), `subject.ticket_id`
(INTERNAL), `payload.ticket_number` (PUBLIC), `payload.subject`, `payload.message_text`,
`payload.customer_name`, `payload.customer_email` (all INTERNAL), `payload.source` (PUBLIC,
choice `email` / `fluent`). History projection contains only the PUBLIC fields. Idempotency
keys: `ticket:{id}:created`, `ticket:{id}:contact_request`, and
`ticket:{id}:message:{message_row_id}` for replies (FISD passes the inserted message row id in
the hook metadata, so two identical customer messages are never collapsed).

New event family `support_tickets` ("Support tickets and contact requests"). The family
gating boolean `requires_woocommerce` is generalised to
`requires_integration: 'woocommerce'|'fluent_contact_inbox'|null` in `EventFamilyCatalog`,
`RuleBuilderPage` and `NotificationTesterPage`. Preset gating (`PresetCatalog`) is a separate
concept and is not changed.

`Registry::register()` gains one additive optional trailing argument
`?string $reply_correlation_field = null` (+ accessor
`reply_correlation_field_for()`). Support events pass `'subject.ticket_id'`.

### 2. Reply correlation without a mapping table

`outbound_messages` gains nullable `correlation_token VARCHAR(191)` (migration 40) and the
index `idx_destination_telegram_message (destination_id, telegram_message_id)` — the index the
reply lookup actually needs. No index on `correlation_token` (no query uses it). The token is
threaded through `NotificationDispatcher` → `MessageDispatcher::send()` (new optional trailing
parameter) → `OutboundMessageRepository::create()`. For support events the token is
`ticket:{ticket_id}`. Migration 40 is additive and repeat-safe (column and index each guarded
by `SHOW COLUMNS` / `SHOW INDEX`), with a `verify_step_40()`.

### 3. Native Telegram reply

In `WebhookController::process_update()`, **after** the existing `updates->record()` dedup
guard and before the adapter bridge / bot commands: if a `message` update carries
`reply_to_message.message_id`, resolve the destination by `(bot_id, chat_id,
message_thread_id)` via a new `DestinationRepository::find_by_bot_chat_thread()`, then the
notification via new `OutboundMessageRepository::find_by_destination_and_telegram_message_id(
int, int ): ?OutboundMessage`. Only a match with a valid `ticket:{n}` correlation token is a
ticket reply; everything else falls through unchanged. Duplicate delivery of an `update_id`
therefore never reaches the handler twice — no second idempotency subsystem is built.

`TicketReplyHandler` (new, `src/Integrations/FluentContactInbox/Inbound/`), in order:
operator identity (`OperatorIdentityMapRepository`) → `CapabilityRegistrar::MANAGE_CONVERSATIONS`
→ existing `RateLimiter` → parse ticket id → load ticket (guarded by `class_exists`) →
reject missing/deleted ticket, missing customer email, **archived** ticket (`archived_at`
set) → **closed** ticket is allowed (the mailer moves it to `pending`) → reject non-text
replies → escape text (`nl2br( esc_html() )`) → call
`Biopentra_Contact_Inbox_Mailer::send_ticket_reply()` → success **only** when
`true === $result`; a `WP_Error` yields an in-chat failure notice, never a success
confirmation → on success confirm `Reply sent to {email} on ticket #{number}`. Every
outcome (including rejections) is answered in chat and the update is claimed.

### 4. Periodic digest

Action Scheduler, group `WorkerRunner::GROUP`, hook
`universal_telegram_fluent_contact_inbox_digest`. Never a fixed 86400/604800 s recurring
interval: each run is a one-shot `as_schedule_single_action()` for the next occurrence,
computed in the WordPress site timezone (`wp_timezone()`), recomputed fresh after every
execution, so it is correct across DST. Settings (options): bot, destination, interval
(`daily`/`weekly`), time-of-day. Saving settings unschedules the pending occurrence and
schedules a fresh one. `run()` unschedules any other pending occurrence of the hook before
scheduling the next, and the `init` guard uses `as_has_scheduled_action()`, so exactly one
future occurrence exists. Content: count of `open` tickets (the "unanswered" headline), count
of `pending` tickets ("awaiting customer"), oldest open tickets by `last_message_at`, list
capped (20), per-line truncated, total kept below Telegram's 4096-character limit, MarkdownV2
escaped with the existing escaping used by `TemplateRenderer`. The digest carries no
correlation token.

### 5. Out of scope

Inline reply buttons; an SLA/unanswered-age alert; appending a returning contact-form
submitter to an existing ticket; Telegram-side spam/delete; any PHPUnit scaffold for FISD;
production deployment; changes to `universal-support-chat` or the adapter bridge; a new
capability; a new mapping table.

## Alternatives

- Separate `ticket_reply_map` table — rejected: `outbound_messages` already stores the
  Telegram `message_id` for every delivered message.
- Inline "Reply" button + `force_reply` — rejected by the product owner in favour of native
  reply.
- Recurring fixed-interval schedule — rejected: DST drift.
- Telegram calling the mailer directly with its own persistence — rejected: the mailer is
  already the canonical operation.

## Consequences

Managers see support activity and answer customers from Telegram with the desk's own
greeting/signature template applied. The support-desk plugin gains three optional hooks; if
it is deactivated the event family disappears and the plugin is otherwise unaffected.
Old notification messages stay repliable indefinitely (subject to ticket-state rules).

## Security and privacy impact

Customer name, email and message text are INTERNAL and never enter event history (PUBLIC-only
projection). Reply authority requires a mapped Telegram operator holding
`MANAGE_CONVERSATIONS`; replies are rate-limited; callback and message updates remain
deduplicated by `(bot_id, update_id)`. Outbound reply text is HTML-escaped before
`wp_kses_post()` in the mailer. Digest content is escaped for MarkdownV2 and size-capped.

## Affected Documents/Milestones

`docs/adr/README.md` (reserved numbers, next free 0047); plugin `CHANGELOG.md`, readme.

## Compatibility/Migration Impact

`db_version` 39 → 40 (additive column + index, repeat-safe). All new parameters are optional
trailing parameters; existing callers are unaffected. With FISD inactive, behaviour is
identical to 0.20.4.

## Verification criteria

Automated: PHPUnit unit, WP-only integration and WooCommerce-present integration; PHPCS;
PHPStan; package build. Specific coverage: event registration/classification, family
visibility with integration on/off, migration 39→40 (column + index), finder match / no match
/ topic separation, correlation propagation, reply branch (unauthorised, non-text, missing
ticket, missing email, archived rejected, closed accepted, mailer `WP_Error`, strict `true`),
duplicate `update_id` → one email, unrelated reply falls through, digest counts, size cap,
one-shot rescheduling, DST, duplicate-schedule prevention. Manual DEV acceptance: see the
task's 14-item cross-plugin checklist.
