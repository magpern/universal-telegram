# Plan: Interactive-priority transport for Support Chat chat delivery (v1)

## 1. Charter and ADRs

Implements [ADR-0045](../adr/0045-interactive-priority-transport-for-support-chat-chat-delivery.md):
a fixed `delivery_class` (`standard` | `interactive_chat`) persisted on `outbound_messages`,
fail-closed acceptance on Contract `deliver_message`, `interactive_chat` placement ahead of
`standard` in the Action Scheduler queue (FIFO within class), and wiring the existing ADR-0023
`ExpeditedDispatchTrigger` into the Support Chat adapter delivery path. Extends ADR-0012,
ADR-0014, ADR-0023; within ADR-0044's transport/adapter-only boundary. Counterpart: Support Chat
ADR-0014 / `sc-interactive-telegram-dispatch-plan-v1.md`. Freeze-first: implementation begins
only from the merged freeze commit of this plan + ADR-0045.

## 2. Repository findings at drafting time

- `origin/main` @ `1af1cf3d9011060cb9244adfd93cfa916acfbdc6`. `Migrator::target_version()` = 37;
  last live step is `step_37_retire_legacy_chat`. `ALTER TABLE ... ADD COLUMN` idiom:
  `step_34_add_prepared_binding_status` (`SHOW COLUMNS ... LIKE` guard, this connection).
- `SupportChatAdapter\Outbound\DeliverMessageService::deliver( ref, key, body, attribution )` —
  accept-dedupe (`DeliveryIdempotencyRepository::find`), binding lookup, `OutboundMessageRepository::create()`,
  `JobEnvelope( MessageDispatcher::JOB_TYPE, {message_uuid,bot_id,destination_id} )`,
  `Queue\Dispatcher::enqueue()`, record idempotency + delivered key.
- `Queue\Dispatcher::enqueue()` → `schedule_action()` → `as_enqueue_async_action( WorkerRunner::HOOK,
  [args], WorkerRunner::GROUP )` (`GROUP = 'universal-telegram'`). Overridable `schedule_action()`
  seam for tests.
- `Telegram\Outbound\SendMessageHandler::handle_job()` / `try_once()` — the single execution
  path: claim/lease (`try_claim_for_sending`, 15 s), per-bot & per-destination circuit breakers,
  per-bot / per-destination / per-group rate limiters, `TelegramApiClient::send_message`,
  classify → dead-letter / reschedule / retry. Reschedules use `time() + wait`.
- `Queue\ExpeditedDispatchTrigger` (ADR-0023) — exists, fully tested
  (`ExpeditedDispatchTriggerTest`, `SpyExpeditedDispatchTrigger`), **constructed nowhere in
  `src/`**. Non-blocking, never-throwing loopback to `ActionScheduler_AsyncRequest_QueueRunner`.
- `outbound_messages` (`step_4`) columns include `status, attempt_count, claim_expires_at`; no
  class/priority column. `MessageDispatcher::send()` (diagnostics / alerts / digests / admin
  Test Message) is the only other `OutboundMessageRepository::create()` caller.
- Action Scheduler claims due actions in `scheduled_date ASC, action_id ASC`.

## 3. Assumptions and open questions

| # | Assumption | Handling |
|---|---|---|
| A1 | Action Scheduler orders claimed actions by `scheduled_date ASC, action_id ASC`. | Established Action Scheduler behaviour; the earliest-`scheduled_date` priority convention depends on it. Interop + integration tests assert interactive-before-standard ordering directly. |
| A2 | A 24 h negative lead is larger than any healthy queue's oldest pending `standard` action. | Documented in ADR-0045 §3; a queue with >24 h-old pending actions is a pre-existing failure surfaced by ADR-0014 health alerting, out of scope to out-prioritise. |
| A3 | `ExpeditedDispatchTrigger` needs only construction + injection, no behavioural change. | Verified against its source and `ExpeditedDispatchTriggerTest`. |
| A4 | `db_version` is 37; the next step is 38. | Verified. |

## 4. Architectural decisions

1. **Schema — `delivery_class VARCHAR(32) NOT NULL DEFAULT 'standard'` on `outbound_messages`.**
   - `step_4` `CREATE TABLE` gains the column; `verify_step_4` gains it in the column list.
   - New `step_38_add_outbound_message_delivery_class` — `SHOW COLUMNS ... LIKE 'delivery_class'`
     guard, then `ALTER TABLE ... ADD COLUMN delivery_class VARCHAR(32) NOT NULL DEFAULT
     'standard' AFTER status`; `verify_step_38` = `table_has_columns([... 'delivery_class'])`.
   - `target_version()` 37 → 38. `DB_VERSION_OPTION` semantics unchanged.
   - `OutboundMessage` value object + `OutboundMessageRepository::create()` gain
     `string $delivery_class = 'standard'`; a small fixed-vocabulary guard maps an unknown value
     to `'standard'` defensively (the wire is already validated in §2 below, this is
     belt-and-braces so a bad row can never poison the queue).
2. **`DeliveryClass` — a tiny fixed enum/const holder** (`Queue\DeliveryClass` or
   `SupportChatAdapter\DeliveryClass`): `STANDARD = 'standard'`, `INTERACTIVE_CHAT =
   'interactive_chat'`, `is_valid( string ): bool`, `from_wire( mixed ): ?string` (null ⇒ invalid).
3. **Contract acceptance — `OutboundContractController::handle_deliver()`**: read
   `delivery_class`; absent ⇒ `standard`; `DeliveryClass::from_wire()` null ⇒ `400 { ok:false,
   reason:'invalid_delivery_class' }` (before calling the service). Pass the resolved class to
   `DeliverMessageService::deliver( ref, key, body, attribution, $delivery_class = 'standard' )`.
4. **`DeliverMessageService::deliver()`** — trailing optional `string $delivery_class =
   'standard'`; pass to `OutboundMessageRepository::create()`; add `delivery_class` to the
   `JobEnvelope` payload (`Classification::INTERNAL`). After a `SCHEDULED` enqueue, if
   `interactive_chat`: `$this->expedited?->trigger()`. `$expedited` is an optional constructor
   dependency (`?ExpeditedDispatchTrigger`) so existing tests/wiring stay valid.
5. **`Queue\Dispatcher`** — `enqueue()` inspects `$envelope` for `delivery_class ===
   'interactive_chat'`; if so, `schedule_interactive_action()` (new overridable seam, mirrors
   `schedule_action()`) → `as_schedule_single_action( time() -
   self::INTERACTIVE_PRIORITY_LEAD_SECONDS, WorkerRunner::HOOK, $args, WorkerRunner::GROUP )`,
   `INTERACTIVE_PRIORITY_LEAD_SECONDS = 86400`. Same `DispatchResult` contract; `action_id <= 0`
   ⇒ `FailureCode::DISPATCH_INVALID_ACTION_ID` as today. `JobEnvelope::to_action_args()` carries
   `delivery_class` through so retries/reschedules see it (they still use `time() + wait`, §6).
6. **Composition root** — construct `ExpeditedDispatchTrigger( $audit )` once; inject into
   `DeliverMessageService`. No other wiring change.
7. **`MessageDispatcher::send()`** — unchanged signature and behaviour; every call is
   `standard`. Diagnostics, alerts, digests, admin Test Message, backfill: untouched.

## 5. Directory / namespace / schema / API impact

- Schema: `outbound_messages.delivery_class` (additive, `db_version` 37 → 38). No other table.
- New: `src/Queue/DeliveryClass.php` (fixed vocabulary), `Migrator::step_38_*` + `verify_step_38`.
- Edited: `src/Queue/Dispatcher.php`, `src/Queue/JobEnvelope.php` (carry `delivery_class`),
  `src/Telegram/Outbound/{OutboundMessage,OutboundMessageRepository,MessageDispatcher}.php`
  (optional class param / accessor — `MessageDispatcher` default only),
  `src/SupportChatAdapter/Outbound/{DeliverMessageService,OutboundContractController}.php`,
  `src/Persistence/Migrator.php`, composition root.
- API: `deliver_message` request body gains optional `delivery_class` (validated, fail-closed).
  No route change, no auth change.

## 6. Security and privacy impact

Per ADR-0045 §"Security and privacy impact": `delivery_class` is a fixed 2-value server-set
string, not content-derived, not user-supplied; `JobEnvelope` payload gains only that one
`Classification::INTERNAL` field; signature/allow-list/discovery gates unchanged and run first;
`ExpeditedDispatchTrigger` unchanged (no unauthenticated trigger); no plaintext added to any
log / audit / payload / diagnostic.

## 7. Test and CI impact

Configurations: WordPress-only and WooCommerce-present both apply (queue + adapter are
WC-independent, but the shared queue also carries WC rule sends — the "ordinary diagnostics /
alerts retain normal behaviour" assertions run in both).

New / extended (UT):

- `MigratorTest` / a schema test — fresh install has `outbound_messages.delivery_class` default
  `standard`; an upgrade from 37 adds it without touching existing rows; `db_version` = 38.
- `DeliveryClassTest` (unit) — vocabulary, `from_wire` null on unknown/empty/non-string.
- `DeliverMessageServiceTest` — `interactive_chat` validates and persists (`delivery_class` on
  the row and in the enqueued envelope); an unknown class from the wire is `400
  invalid_delivery_class` and nothing is enqueued; `standard` / absent behave exactly as today;
  the expedited trigger fires for `interactive_chat` and not for `standard`; idempotent replay
  still dedupes regardless of class.
- `DispatcherTest` — an `interactive_chat` envelope is scheduled via `schedule_interactive_action`
  at a past timestamp; a `standard` envelope via `schedule_action` at now; both map failures to
  the existing `DispatchResult` codes.
- Queue-ordering integration test — with a `standard` action and an `interactive_chat` action
  enqueued (interactive second), the Action Scheduler store returns the interactive action
  first; two interactive actions preserve FIFO; two standard actions preserve FIFO.
- `SendMessageHandler` regression — an `interactive_chat` message runs the identical
  claim/lease + rate-limit + circuit-breaker + retry + dead-letter path; a failed API call
  reschedules under the normal model; idempotency unaffected.
- `OutboundContractControllerTest` — signed `deliver_message` with `delivery_class` valid /
  absent / invalid; signature gate still runs first.

Interop (dual-plugin, both WP/PHP variants): see the Support Chat plan §7 — a visitor message
and a Hub reply arrive as `interactive_chat`, create exactly one UT delivery each, are ordered
ahead of a concurrently-enqueued `standard` delivery, converge with no duplicate when the
immediate path fails, are never echoed back from Telegram, and do not promote ordinary traffic.

Full gate: PHPCS, PHPStan, unit, integration (WordPress-only + WooCommerce-present), full
interop suite, package checks, `check-doc-links`, GitHub Actions.

## 8. Work packages (execution order, from the merged freeze)

1. `Queue\DeliveryClass` + unit test.
2. `Migrator` step 38 + `step_4`/`verify_step_4` column + `target_version` 38 + schema test.
3. `OutboundMessage` / `OutboundMessageRepository::create()` optional class + accessor + tests.
4. `JobEnvelope` carries `delivery_class`; `Queue\Dispatcher` `interactive_chat` branch +
   `schedule_interactive_action` seam + `DispatcherTest`.
5. `DeliverMessageService` optional class + envelope + expedited trigger; `OutboundContractController`
   validation + fail-closed; composition-root wiring; controller + service tests.
6. Queue-ordering + `SendMessageHandler` regression tests.
7. Interop extension; full gate (both configs, both variants); PR (no merge).

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Negative `scheduled_date` rejected / mishandled by Action Scheduler | `as_schedule_single_action` accepts any past timestamp (runs immediately at claim); `DispatchResult` still guards `action_id <= 0`. Integration test asserts ordering against the real store. |
| Interactive starved behind >24 h `standard` backlog | ADR-0045 §3 / A2: that queue is already failing; not this ADR's job. Lead is a constant, easy to raise. |
| Retry of interactive loses priority | Deliberate & documented (ADR-0045 §3): retries carry mandatory backoff; first attempt is the latency-critical one. |
| `ExpeditedDispatchTrigger` misfires on a busy install | Unchanged ADR-0023 mechanism: never throws, never blocks, durable action already enqueued; only ever fired for `interactive_chat`. |
| A poisoned `delivery_class` row breaks the queue | Wire validated + fail-closed at acceptance; repository maps an unknown stored value defensively to `standard`. |

## 10. Out of scope

- Support Chat's immediate-attempt / `deliver_message` client change (its ADR-0014 / plan).
- Any change to `ExpeditedDispatchTrigger` itself, the retry policy, rate limiter, circuit
  breaker, idempotency model, or dead-letter behaviour.
- Priority for `standard` traffic; removing / reclassifying diagnostics or alerts.
- Per-action Action Scheduler priority fields; a second queue runner; a custom claim query.
- Any new route, auth mechanism, settings page, or UI priority control.
- DEV / production deployment or test; any real Telegram resource change; release / tag.

## 11. Definition of done

ADR-0045 + this plan merged as a code-free freeze; then from that baseline: all §7 tests green;
full gate green for WordPress-only and WooCommerce-present, both WP/PHP variants; real
dual-plugin interop green both variants against the pinned Support Chat implementation branch;
`db_version` = 38 with the additive column proven on fresh and upgraded installs; implementation
PR open (not merged); no excluded change made.
