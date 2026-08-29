# Closure — ADR-0045: Interactive-priority transport for Support Chat chat delivery

## Status

**Complete and merged.** Documentation-only closure record. No runtime, DEV, production,
Telegram, credential, pairing, release, or tag action.

## Implementation PRs and merge SHAs

| Repo | Freeze PR | Freeze merge SHA | Implementation PR | Implementation merge SHA |
|---|---|---|---|---|
| universal-telegram | [#63](https://github.com/magpern/universal-telegram/pull/63) | `6d02aae2fab2648b78e78fdc55cc4a4572550cf1` | [#64](https://github.com/magpern/universal-telegram/pull/64) | **`9b4a6ef2bfc56b4bb514567c797d41c8a285727a`** |
| universal-support-chat (counterpart, ADR-0014) | [#38](https://github.com/magpern/universal-support-chat/pull/38) | `530e84ad94593d00444921173315b11ee5870201` | [#39](https://github.com/magpern/universal-support-chat/pull/39) | `4bf012a0edba96d1fd66aa187b908154f867b624` |

Merge order followed: Universal Telegram #64 first, then Support Chat #39 (after its interop CI
was re-pinned to this repo's merged `main` `9b4a6ef…` and re-run green).

## What shipped (Universal Telegram)

- **`Queue\DeliveryClass`** — the fixed transport vocabulary `{standard, interactive_chat}`;
  `from_wire()` (absent ⇒ `standard`, unknown ⇒ reject), `from_storage()` (poison-safe).
- **Schema** — `outbound_messages.delivery_class VARCHAR(32) NOT NULL DEFAULT 'standard'`,
  additive and nullable-safe, no backfill. `Migrator` step 38 (`ALTER` idiom of `step_34`) plus
  the column in `step_4`'s fresh-install `CREATE` / `verify_step_4`. `target_version()` 37 → 38;
  `LegacyChatPurge` sets `db_version` 38. Package-acceptance checks assert the column and the
  version.
- **Contract acceptance** — `OutboundContractController::handle_deliver()` reads an optional
  `delivery_class` body field: absent ⇒ `standard`; a fixed-vocabulary string ⇒ that class;
  anything else ⇒ **`400 invalid_delivery_class`** (fail closed, never coerced). The
  signature / allow-list / discovery gates are unchanged and still run first. `delivery_class`
  is **not** part of the accept-idempotency key.
- **Queue priority** — `Queue\Dispatcher::enqueue()` routes an `interactive_chat` envelope
  through a new overridable `schedule_interactive_action()` =
  `as_schedule_single_action( time() − 86400, WorkerRunner::HOOK, …, WorkerRunner::GROUP )`.
  Action Scheduler claims `scheduled_date ASC, action_id ASC`, so every `interactive_chat`
  action is claimed **ahead of** freshly-enqueued `standard` work. **FIFO is preserved within
  each class** (monotonic `scheduled_date`, `action_id` tiebreak). The `standard` path is
  byte-for-byte unchanged (`as_enqueue_async_action` at "now").
- **Immediate worker kick** — `DeliverMessageService::deliver()` fires the **existing** ADR-0023
  `Queue\ExpeditedDispatchTrigger` (previously constructed nowhere) **only** after a successful
  `interactive_chat` enqueue. The trigger itself is unchanged and keeps every ADR-0023 fail-safe
  (non-blocking, never-throwing, durable action already enqueued). `standard` sends never fire
  it.
- **`Core\Plugin`** constructs and injects `ExpeditedDispatchTrigger` into
  `DeliverMessageService` (optional constructor arg).

## Explicitly unchanged for both classes

`DeliveryIdempotencyRepository` accept-dedupe (key unchanged, `delivery_class` not part of it),
`RateLimiter`, `CircuitBreaker`, `RetryPolicy`, dead-letter, `possible_duplicate_delivery`
marking, the `outbound_messages` claim/lease, `SendMessageHandler`, `WorkerRunner`. An
`interactive_chat` message is rate-limited, circuit-broken, retried, and dead-lettered exactly
like any other; a failed Telegram API call remains retryable under the normal durable transport
model. `MessageDispatcher::send()` (diagnostics, alerts, digests, admin Test Message) and
`deliver_transcript_backfill` are untouched — every such send is `standard`. Retries and
deferrals of an `interactive_chat` message use the existing timing (they rejoin at ordinary
priority; the latency-critical event is the first attempt — documented in ADR-0045 §3).

## The final invariant (shared with Support Chat ADR-0014 Amendment 1)

- Every Contract v1 call this plugin receives from Support Chat — `ensure_channel_case`
  (including new-conversation `createForumTopic`), `notify_operators`,
  `deliver_transcript_backfill`, `deliver_message` — is made **only** from Support Chat's
  asynchronous WP-Cron dispatch worker, never from a visitor / Hub HTTP request. A Support Chat
  visitor or Hub request only atomically persists its message + content-free outbox row and
  requests a non-blocking WP-Cron run; **all** Telegram-facing work happens in the worker.
- `EnsureChannelCaseService::ensure()`'s synchronous `createForumTopic` call is unchanged and
  acceptable: it now only ever runs inside Support Chat's async worker request.
- `deliver_message` is asynchronous here (encrypted row + Action Scheduler enqueue, no Bot API
  call in the handler). `interactive_chat` places that job ahead of `standard` work and fires
  the ADR-0023 trigger — the expedited path is fully asynchronous on both sides.

## CI and real dual-plugin interop evidence

**Universal Telegram PR #64 CI** — all 24 checks green (PHPCS, PHPStan L5, unit ×3 PHP,
integration WordPress-only floor + current, integration WooCommerce-present, build-zip,
package-acceptance ×3).

**Real dual-plugin interop against this repo's actual merged `main` (`9b4a6ef…`)** — run from
the Support Chat suite (`TelegramDispatchInteropTest`), real two-way Ed25519 pairing, real
signed Contract v1, both supported variants:

| Variant | Result |
|---|---|
| WordPress 6.9 / PHP 8.1 | `OK (10 tests, 126 assertions)` |
| WordPress 7.1 / PHP 8.3 | `OK (10 tests, 126 assertions)` |

Interop coverage: a new-conversation visitor message, a Hub reply, and a message on an
already-bound conversation each make **zero** `api.telegram.org` calls and create **no**
Universal Telegram binding during the originating Support Chat request; the worker then creates
the topic / binding and delivers exactly **one** `interactive_chat` transport row each; a failed
first sweep converges on the next with **no duplicate**; message + outbox commit survive a
failing async kick; an ordinary `standard` Universal Telegram delivery is **not** promoted; a
Telegram-originated reply is never echoed back.

## Compatibility

Additive nullable schema (`db_version` 37 → 38), forward-only, idempotent, no backfill.
Wire-compatible both directions: a Support Chat build without ADR-0014 never sends
`delivery_class` (every delivery `standard`); a Universal Telegram build without ADR-0045
ignores the field.

## Documents

- [ADR-0045](../adr/0045-interactive-priority-transport-for-support-chat-chat-delivery.md)
  (Proposed + **Amendment 1 Accepted** — the Support Chat counterpart is fully asynchronous).
- `docs/plans/ut-interactive-chat-delivery-priority-plan-v2.md` (supersedes v1, retained).
- Counterpart: Support Chat ADR-0014 + Amendment 1;
  `docs/closure/adr-0014-interactive-dispatch-closure.md` in `universal-support-chat`.

## Non-authorization

This closure authorizes nothing operational. No DEV or production deployment or test; no real
Telegram message, webhook, bot, group, topic, destination, pairing, or credential change; no
route switch, migration/cutover, release, tag, or database purge.
