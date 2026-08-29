# Plan: Interactive-priority transport for Support Chat chat delivery (v2)

Supersedes `ut-interactive-chat-delivery-priority-plan-v1.md` (retained unchanged). Realises
[ADR-0045](../adr/0045-interactive-priority-transport-for-support-chat-chat-delivery.md)
**including its Amendment 1** (the Support Chat counterpart is fully asynchronous — review
correction).

## Why v2

Support Chat ADR-0014's first draft ran a synchronous delivery attempt in the visitor / Hub
request, which for a new conversation reached this plugin's `ensure_channel_case` →
`ForumTopicService::create()` → synchronous `createForumTopic` Bot API call. Support Chat has
removed that in-request attempt (its ADR-0014 Amendment 1 / plan v2). **No Universal Telegram
runtime change results from that correction** — this plan v2 exists only to record the
clarification and keep the plan set aligned with the amended ADR.

## What is unchanged from v1

Every runtime decision in v1 stands as implemented:

- `Queue\DeliveryClass` fixed `{standard, interactive_chat}` vocabulary; `from_wire` /
  `from_storage`.
- `Migrator` step 38: `outbound_messages.delivery_class VARCHAR(32) NOT NULL DEFAULT 'standard'`
  (additive, `db_version` 37 → 38); `step_4` + `verify_step_4`; `LegacyChatPurge` → 38.
- `OutboundMessage` / `OutboundMessageRepository::create()` optional trailing class + accessor,
  persisted + hydrated, `from_storage` guard.
- `Queue\Dispatcher::enqueue()` `interactive_chat` branch → `schedule_interactive_action()` =
  `as_schedule_single_action( time() − 86400, … )`; FIFO within class; `standard` path
  byte-for-byte unchanged.
- `DeliverMessageService::deliver()` optional class → row + `JobEnvelope` payload
  (`Classification::INTERNAL`); fires the injected ADR-0023 `ExpeditedDispatchTrigger` only
  after a successful `interactive_chat` enqueue.
- `OutboundContractController::handle_deliver()` fail-closed validation → `400
  invalid_delivery_class`; absent ⇒ `standard`; signature / allow-list / discovery gates first;
  not part of the accept-idempotency key.
- `Core\Plugin` constructs + injects `ExpeditedDispatchTrigger`.
- `DeliveryIdempotencyRepository`, `RateLimiter`, `CircuitBreaker`, `RetryPolicy`, dead-letter,
  the `outbound_messages` claim/lease, `SendMessageHandler`, `WorkerRunner`: all unchanged for
  both classes. `MessageDispatcher::send()` and `deliver_transcript_backfill`: untouched ⇒ all
  `standard`.

## What v2 clarifies (ADR-0045 Amendment 1)

- Every Contract v1 call this plugin receives from Support Chat (`ensure_channel_case`,
  `notify_operators`, `deliver_transcript_backfill`, `deliver_message`) is now made **only**
  from Support Chat's asynchronous WP-Cron dispatch worker — never from a visitor / Hub HTTP
  request.
- `EnsureChannelCaseService::ensure()`'s synchronous `createForumTopic` call is unchanged and
  acceptable: it only ever runs inside Support Chat's async worker request now, as it already
  does on Support Chat's recurring sweep. Universal Telegram imposes no new constraint and needs
  no code change.
- The expedited path is asynchronous on both sides: `deliver_message` stays async here (row +
  Action Scheduler enqueue), the `interactive_chat` class still front-loads the queue, and the
  ADR-0023 trigger still fires.

## Tests

The v1 test set stands. The dual-plugin proof of "no Telegram I/O in the Support Chat visitor /
Hub request" lives in the **Support Chat** interop suite (`TelegramDispatchInteropTest`, both
WP/PHP variants), exercised against this plugin's ADR-0045 branch.

Full gate: PHPCS, PHPStan, unit, integration (WordPress-only + WooCommerce-present, both WP/PHP
variants), full interop suite, package checks, `check-doc-links`, GitHub Actions — all as v1.

## Out of scope

As v1 §10.

## Definition of done

ADR-0045 Amendment 1 + this plan v2 on the implementation branch; the v1 full gate stays green
(no runtime change); the Support Chat interop suite (updated for the async correction) green on
both variants against this branch.
