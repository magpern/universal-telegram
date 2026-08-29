# ADR-0045: Interactive-priority transport for Support Chat chat delivery

## Status

**Proposed** — documentation freeze. Extends ADR-0012 (outbound delivery architecture), ADR-0014
(queue implementation and failure semantics), ADR-0023 (expedited interactive queue dispatch),
and operates entirely within ADR-0044's transport/adapter-only boundary. One additive nullable
schema change (`db_version` 37 → 38). No new REST route, no new authentication mechanism, no
shared database, no direct Telegram API call added anywhere, no plugin version change beyond a
patch bump if the team elects one at implementation time. Implementation begins only from the
merged freeze baseline.

## Context

Since ADR-0044, Universal Telegram is Telegram transport for Support Chat. Support Chat's
`deliver_message` Contract call lands in `SupportChatAdapter\Outbound\DeliverMessageService`,
which writes an encrypted `outbound_messages` row and enqueues one Action Scheduler action
(`Queue\Dispatcher::enqueue()` → `as_enqueue_async_action( WorkerRunner::HOOK, [envelope],
'universal-telegram' )`). Action Scheduler then runs that action — in `scheduled_date ASC,
action_id ASC` order across **all** queued actions — and `Telegram\Outbound\SendMessageHandler`
performs the real Telegram send with the full reliability tree (idempotency claim/lease, per-bot
and per-destination rate limiters, per-bot and per-destination circuit breakers, retry policy,
dead-letter).

Two facts make website-chat delivery slow on a busy, multi-plugin install:

1. **No priority.** A Support Chat visitor message is one more action at the back of a shared
   queue that also carries WooCommerce notification-rule sends, operational alerts, digests, and
   admin test messages. It waits its turn.
2. **Cadence.** Without an immediate nudge the action runs on Action Scheduler's own cron/async
   cadence. ADR-0023 built `Queue\ExpeditedDispatchTrigger` for exactly this, but it is
   currently constructed nowhere — the Support Chat adapter path never fires it.

Diagnostics, generic alerts, digests, backfill, and the admin Test Message must keep their
current ordinary behaviour and cadence.

## Decision

### 1. Two fixed transport priority classes

`outbound_messages` gains one column:

```
delivery_class VARCHAR(32) NOT NULL DEFAULT 'standard'
```

Fixed vocabulary, persisted as a plain string, **never** message plaintext or any content-derived
value:

| Class | Source | Queue placement |
|---|---|---|
| `standard` | default for every existing caller (`MessageDispatcher::send()` — diagnostics, alerts, digests, admin Test Message — and `deliver_transcript_backfill`) | unchanged: `as_enqueue_async_action` at "now" |
| `interactive_chat` | set **only** by `DeliverMessageService::deliver()` when Support Chat's signed `deliver_message` request carries `delivery_class = interactive_chat` (ADR-0014 §2) | scheduled ahead of `standard`, FIFO within the class |

`delivery_class` is **additive and nullable-safe**: existing rows and existing callers are
`standard` with no backfill. `db_version` 37 → 38, one `Migrator` step (an `ALTER TABLE ... ADD
COLUMN`, matching the existing `step_34_add_prepared_binding_status` idiom), plus the column in
the `step_4` fresh-install `CREATE TABLE` and its `verify_step_4` column list.

### 2. Contract `deliver_message` acceptance — validate and fail closed

`OutboundContractController::handle_deliver()` reads an optional `delivery_class` body field:

- absent ⇒ `standard` (unchanged behaviour);
- a string in the fixed vocabulary (`standard`, `interactive_chat`) ⇒ that class;
- anything else (non-string, empty, unknown token) ⇒ **reject `400`**, stable reason
  `invalid_delivery_class`. Never coerced, never guessed.

The signature/allow-list/discovery gates are unchanged and still run first. `delivery_class` is
**not** part of the accept-idempotency key (`DeliveryIdempotencyRepository` still keys on the
Contract idempotency key alone), so a retry that repeats or corrects the class still dedupes to
the one delivery.

### 3. Priority placement — earlier `scheduled_date`, same queue, same worker

`Queue\Dispatcher` gains an `interactive_chat` branch: instead of `as_enqueue_async_action(...)`
(which schedules at "now"), it calls
`as_schedule_single_action( time() - self::INTERACTIVE_PRIORITY_LEAD_SECONDS, WorkerRunner::HOOK,
$args, WorkerRunner::GROUP )` with `INTERACTIVE_PRIORITY_LEAD_SECONDS = 86400` (24 h).

- Action Scheduler claims actions in `scheduled_date ASC, action_id ASC` order, so every
  `interactive_chat` action (scheduled at `now − 24 h`) is claimed **before** any freshly
  enqueued `standard` action (scheduled at `now`).
- **FIFO within `interactive_chat`:** two interactive enqueues at real times `t1 < t2` get
  `scheduled_date = t1 − 24 h < t2 − 24 h`; `action_id` breaks any same-second tie. Order
  preserved.
- **FIFO within `standard`:** unchanged — same `as_enqueue_async_action` call, same ordering.
- The 24 h lead is deliberately far larger than any healthy queue's oldest pending `standard`
  action, so an interactive message is not starved behind ordinary backlog. A queue with
  `standard` actions older than 24 h is already in a failure state its own health alerting
  (ADR-0014) surfaces; this ADR does not try to out-prioritise that.
- Same hook, same group, same `WorkerRunner`, same `SendMessageHandler`. Nothing about
  execution, claim/lease, rate limiting, circuit breaking, retry, dead-letter, or audit changes.
- **Retries and deferrals of an `interactive_chat` message** (rate-limit wait, circuit-probe
  wait, `WorkerRunner` backoff, `SendMessageHandler` reschedules) use the **existing** timing
  (`time() + wait`), i.e. they rejoin at ordinary priority. This is deliberate: a retry is by
  definition already delayed (backoff / flood-control / breaker), and the latency-critical event
  is the first attempt. Documented trade-off.

### 4. Immediate worker kick — wire the existing ADR-0023 trigger

After a successful `interactive_chat` enqueue (and only then), `DeliverMessageService::deliver()`
calls `Queue\ExpeditedDispatchTrigger::trigger()` — the existing ADR-0023 non-blocking,
never-throwing loopback nudge to Action Scheduler's own async queue runner. This ADR does not
change `ExpeditedDispatchTrigger`; it only constructs it in the composition root and injects it
into `DeliverMessageService`. Every ADR-0023 §1 fail-safe property holds: the durable action is
already enqueued; the trigger proves nothing and blocks nothing; on any failure the message
still ships on normal cadence. `standard` sends do not fire it (unchanged).

### 5. Reliability invariants — explicitly unchanged

- `DeliveryIdempotencyRepository` accept-dedupe: unchanged, key unchanged, `delivery_class` not
  part of it.
- `RateLimiter` (per-bot / per-destination / per-group token buckets), `CircuitBreaker`
  (per-bot / per-destination), `RetryPolicy`, dead-letter, `possible_duplicate_delivery`
  marking, the `outbound_messages` claim/lease: **all unchanged**. An `interactive_chat` message
  is rate-limited, circuit-broken, retried, and dead-lettered exactly like any other.
- A failed Telegram API call for an `interactive_chat` message remains retryable under the
  normal durable transport model (§3).
- No direct Telegram API call is added anywhere, and none is added to Support Chat.

## Alternatives

- **A dedicated Action Scheduler group + a separate runner.** Rejected: Action Scheduler's
  queue runner claims across all groups by `scheduled_date`; a separate group alone gives no
  priority, and a second runner process is infrastructure this plugin does not own.
- **A real `priority` integer column driving a custom claim query.** Rejected: execution order
  is Action Scheduler's, not a table scan — `SendMessageHandler` processes exactly the
  `message_uuid` its action carries. A priority column that Action Scheduler never consults
  would be decorative.
- **A large negative `priority` in the Action Scheduler action itself.** Action Scheduler has no
  per-action priority field; the earliest-`scheduled_date` convention is the supported
  mechanism and is what other WordPress ecosystems use for the same purpose.
- **Re-apply the 24 h lead on every retry of an interactive message.** Rejected: retries carry a
  mandatory backoff / flood-control / breaker-probe delay; scheduling them 24 h in the past
  would fight that delay and risk hammering a struggling destination. First attempt only (§3).
- **Synchronous send from the Contract handler.** Rejected: ADR-0014's and this work's explicit
  exclusion — no synchronous Telegram dependency for the website response; `deliver_message`
  stays async.
- **Change `ExpeditedDispatchTrigger`.** Not needed; ADR-0023's mechanism is correct, it was
  simply never wired for the adapter path.

## Consequences

- One additive nullable column; `db_version` 37 → 38; fresh installs get it in `step_4`,
  upgrades get it via a new `ALTER` step. No backfill. Uninstall drops the table as today.
- `interactive_chat` messages are claimed by Action Scheduler ahead of `standard` work and, via
  the wired trigger, prompt an immediate queue-runner pass — so a website-chat send starts
  promptly even on a busy shared queue.
- `standard` traffic (WooCommerce rules, alerts, digests, backfill, admin Test Message) is
  byte-for-byte unchanged: same enqueue call, same ordering, same cadence, no trigger.
- Exactly-once-on-accept is unchanged (idempotency key untouched). All rate-limit / circuit /
  retry / dead-letter behaviour is unchanged for both classes.
- Diagnostics may surface a per-class count; no plaintext is added to any log, audit row, queue
  payload, or diagnostic.

## Security and privacy impact

- `delivery_class` is a fixed 2-value string, server-set from a signed Contract request field
  that Support Chat itself derives (never from a visitor, an operator, or message text). It
  reveals nothing about the message.
- The `JobEnvelope` payload gains only `delivery_class` (`Classification::INTERNAL`), alongside
  the existing opaque identifiers — never text, never a token (ADR-0012).
- The signature / allow-list / discovery gates on `deliver_message` are unchanged and still run
  before any acceptor. The new field is validated and fail-closed.
- `ExpeditedDispatchTrigger` is unchanged and remains an unauthenticated-trigger-free,
  in-process loopback with all ADR-0023 fail-safes.

## Affected Documents/Milestones

- ADR-0012 (outbound delivery) — extended with a transport priority class.
- ADR-0014 (queue) — extended: `Dispatcher` gains an `interactive_chat` scheduling branch.
- ADR-0023 (expedited dispatch) — its trigger is now wired into the Support Chat adapter
  delivery path; the mechanism itself is unchanged.
- ADR-0044 — this operates within the transport/adapter-only boundary; no legacy-chat surface
  is revived.
- Support Chat ADR-0014 — the counterpart that produces `delivery_class = interactive_chat`.
- `docs/plans/ut-interactive-chat-delivery-priority-plan-v1.md`.

## Compatibility/Migration Impact

- Additive nullable schema (`db_version` 37 → 38), no backfill, forward-only, idempotent.
- Wire-compatible in both directions: a Support Chat build without its ADR-0014 never sends
  `delivery_class`, so every delivery is `standard` (today's behaviour); a Universal Telegram
  build without this ADR ignores the field and delivers at ordinary priority.
- Downgrade leaves an unused column; re-upgrade is a no-op.

## Exact exclusions

- No DEV or production deployment or test; no real Telegram message, webhook, bot, group, topic,
  destination, pairing, or credential change; no route switch, migration/cutover, release, tag,
  or database purge.
- No new REST route; no new authentication mechanism; no shared database; no direct cross-plugin
  SQL; no copied code; no direct Telegram API call from Support Chat; no bypass of idempotency,
  rate limiting, circuit breaking, retry policy, or audit controls.
- No visitor-facing or operator-facing priority selector; no new settings page; no removal or
  reclassification of existing diagnostics or alerts (they stay `standard`).

---

## Amendment 1 — the Support Chat counterpart is fully asynchronous (2026-08-29, review correction)

**Status: Accepted.** No Universal Telegram runtime change. Clarifies the interaction with
Support Chat ADR-0014, whose own Amendment 1 removed its in-request "bounded immediate delivery
attempt".

### Context

Support Chat ADR-0014's first draft ran a synchronous delivery attempt inside the visitor / Hub
request. For a new conversation that attempt reached this plugin's `ensure_channel_case` →
`EnsureChannelCaseService::ensure()` → `ForumTopicService::create()` →
`TelegramApiClient::create_forum_topic()`, i.e. a **synchronous** `createForumTopic` Bot API
call on the website's request thread. Support Chat has removed that in-request attempt.

### What this means here

- Every Contract v1 call this plugin receives from Support Chat — `ensure_channel_case`,
  `notify_operators`, `deliver_transcript_backfill`, `deliver_message` — is now made **only**
  from Support Chat's own asynchronous WP-Cron dispatch worker, never from a visitor / Hub HTTP
  request. This plugin already treated those calls as ordinary signed Contract requests and
  imposes no new constraint.
- `EnsureChannelCaseService::ensure()` making a synchronous `createForumTopic` call is
  **unchanged and acceptable**: it now only ever runs inside Support Chat's async worker
  request, exactly as it does on Support Chat's recurring sweep.
- `deliver_message` remains asynchronous here (encrypted row + Action Scheduler enqueue, no Bot
  API call in the handler). The `interactive_chat` class still places that job ahead of
  `standard` work (§3) and still fires the ADR-0023 `ExpeditedDispatchTrigger` (§4) — the
  expedited path is entirely asynchronous on both sides.
- No change to the schema (`db_version` 38), the `delivery_class` vocabulary, validation, queue
  placement, `ExpeditedDispatchTrigger` wiring, or any reliability control.

### Companion plan

`docs/plans/ut-interactive-chat-delivery-priority-plan-v2.md` records this clarification; the
v1 plan is retained unchanged.
