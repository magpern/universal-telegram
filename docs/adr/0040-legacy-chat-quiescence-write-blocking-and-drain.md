# ADR-0040 — Legacy-Chat Quiescence: Write-Blocking, Drain, and Encrypted Deferred-Update Replay

## Status

Accepted

## Context

Support Chat's SC-M03 controlled migration has two phases. Phase A (repeatable
backfill) and Phase B (final reconciliation) are both implemented and merged
in the Support Chat repository (PR #9, `a61aa09`). Phase B is hard-gated on a
`QuiescenceStateProvider` interface, frozen by Support Chat ADR-0008 §6:

```php
interface QuiescenceStateProvider {
    public function is_quiescent(): bool;
    public function since(): ?DateTimeImmutable;
}
```

Today the only shipped implementation is `DefaultDenyQuiescenceStateProvider`,
permanently `false`. ADR-0039 §3 (this repository) already commits Universal
Telegram, in advance, to eventually building a real provider that satisfies
this exact interface, and explicitly defers scoping that work ("this ADR does
not schedule, scope, or authorise that future work package"). This ADR is
that future work package: Support Chat's SC-M03 Work Package 2 (WP2), owned
and implemented on the Universal Telegram side.

`docs/governance.md`'s freeze model requires this decision, and every
persistence/security-boundary/state-machine choice it makes, to be committed
here — code-free — before any implementation begins.

### What "quiescence" must mean

Support Chat's Phase B needs a boolean signal that legacy conversation data
in this plugin's tables has stopped changing, so that a final reconciliation
pass can treat what it reads as complete. Universal Telegram has no existing
mechanism for this: legacy chat state is mutated from seven independent
synchronous entry points (visitor REST endpoints, the Telegram webhook,
admin/Hub actions, Telegram bot commands, AI draft request/review, WordPress
user deletion, and — discovered during this ADR's own required source
verification, see below — administrative dead-letter requeue) plus recurring
Action Scheduler sweeps and asynchronous per-item jobs (topic creation,
topic deletion, outbound routing, outbound Telegram delivery, AI draft
generation). None of these were designed with a pause mechanism.

### Required source verification performed for this ADR

Per this repository's existing precedent (ADR-0039's `LegacyExportServiceV1`
pin was written only after direct source verification of every field and
gate it describes), every code-level claim in this ADR was verified directly
against `origin/main` at `5d16119` before being written down. Two verified
facts materially shaped the Decision below:

1. **`Queue\Dispatcher`/`Queue\WorkerRunner` (hook `universal_telegram_run_job`,
   Action Scheduler group `universal-telegram`) is shared infrastructure.**
   `SupportChatAdapter\Outbound\DeliverMessageService` dispatches through the
   same `Dispatcher` as every legacy-chat async handler. A gate at the queue
   or group level would incorrectly pause Support Chat adapter delivery,
   which this ADR must not do (ADR-0037's adapter-consumer role is
   unaffected by this work). Job-type scoping is therefore required, not
   merely convenient.

2. **Job-type scoping is necessary but, for one job type, not sufficient.**
   `MessageDispatcher::JOB_TYPE` (`telegram_send_message`) is enqueued from
   three call sites, not one: `Conversations\ConversationOutboundHandler`
   (legacy chat's own outbound routing), `SupportChatAdapter\Outbound\DeliverMessageService`
   (Support Chat's own Telegram-channel delivery), and
   `Administration\Telegram\BotManagementController::requeue_message()`
   (an operator-triggered dead-letter requeue of an already-existing
   `outbound_messages` row, of either origin). A bare
   `COUNT(*) WHERE job_type = 'telegram_send_message'` query cannot
   distinguish these origins and would either block `confirm()` forever
   while Support Chat adapter traffic is merely busy, or — if used naively
   at an origination gate — refuse Support Chat sends outright, which this
   design must not do. The Decision below resolves this by joining each
   pending job's `destination_id` argument against `conversations.destination_id`,
   which is enforced `UNIQUE` (ADR-0031, `Migrator::step_29`, "exclusive
   destination ownership") — a `destination_id` is owned by at most one
   legacy conversation at any moment, and a Support Chat channel binding's
   `destination_id` is therefore, by that same exclusivity constraint, never
   also a legacy conversation's `destination_id`. This join, not a bare
   job-type count, is the correct drain proof for this one shared job type.

## Decision

### 1. Central design rule: never fail or cancel existing work

Nothing in this design ever force-fails, force-cancels, or discards a
legacy-chat mutation already in flight when quiescence begins. Quiescence
works entirely by cutting off the **creation** of new legacy-chat work at its
synchronous entry points and pausing purely-internal recurring sweeps.
Anything already queued, leased, or mid-flight — including a handler's own
internal retry/reschedule of its own in-progress attempt — is left alone and
runs to natural completion. "Drained" means an operator has observed, by
direct query, that no such work remains; it never means Universal Telegram
tried to stop it.

### 2. Synchronous entry points that block new work (eight, all confirmed from source)

| # | Category | Confirmed entry point | Gate behavior |
|---|---|---|---|
| 1 | Visitor starts a conversation | `Conversations\Rest\ConversationsController::handle_start` | First statement in the handler → `409 Conflict`, error code `quiescence_active`. |
| 2 | Visitor posts a message | `Conversations\Rest\ConversationsController::handle_post_message` | Same treatment; highest fan-out surface in the plugin. |
| 3 | Telegram sends a webhook update | `Telegram\Inbound\WebhookController::handle_request` | Not a simple refuse — see §3, encrypted buffer-and-replay. |
| 4 | Operator acts in the Hub/admin (assign, unassign, reopen, add_note, set_availability, archive, delete_permanently, bulk_archive_and_delete_permanently) | `Administration\Conversations\ConversationActionHandler::handle_request()` — sole dispatch entry point for every sub-action | Single early-return guard covers the whole category. |
| 5 | Operator acts via Telegram command (`/claim`, `/release`, `/reopen`+`/confirm`, `/resolve`+`/confirm`, `/presence`) | `Telegram\Commands\BotCommandDispatcher::execute()` | Early-return guard at the top of `execute()`, including at confirm-time for the two-factor `/confirm` flow. |
| 6 | Operator requests/reviews an AI draft | `AI\Draft\DraftRequestHandler::request()`, `Administration\AI\ConversationDraftPanel::handle_request()` | Early-return guard, same shape as #4. |
| 7 | A WordPress user account is deleted | `Core\Plugin.php` `add_action( 'deleted_user', ... )` | Early-return guard as first line of each closure. **Named trade-off, PO-confirmed (see Context of the accompanying plan document)**: during any non-`idle` state, deleting a WP user does not clean up that user's conversation data until state returns to `idle`. |
| 8 | Operator requeues a dead-lettered outbound Telegram message | `Administration\Telegram\BotManagementController::requeue_message()` | **Discovered during this ADR's milestone-0 verification, not present in the original planning draft.** Blocked outright during any non-`idle` state, regardless of the requeued message's origin (legacy conversation or Support Chat adapter binding). Distinguishing origin at requeue time would require the same `destination_id`-join used for the drain query (§5) but delivers little practical value for a rare, already-manual, already-delayed administrative action — blocking unconditionally is the simpler and equally safe choice. An operator whose Support Chat dead-letter needs requeuing during a quiescence window waits until `idle`; this is a minor, bounded inconvenience, never data loss (the dead-lettered row itself is untouched, requeue is only deferred). |

### 3. Telegram inbound webhook — encrypted buffer, then in-order replay under continued blocking

`WebhookController::handle_request` checks the quiescence gate immediately
after Telegram-secret authentication, before the existing `inbound_updates`
dedup insert or any command/reply routing. The gate blocks in every state
except `idle` (`draining`, `quiescent`, and `replaying` all buffer new
arrivals — `replaying` buffering new arrivals rather than processing them
live is what makes the replay backlog self-draining instead of racing fresh
traffic).

**Duplicate buffering is idempotent, not an error.** Telegram redelivers
webhook updates on retry, and this plugin will be quiesced/replaying for the
span of some retries. If `(bot_id, update_id)` already exists in the deferred
table (its `UNIQUE KEY`, see §4's schema), the buffer-insert step treats this
as an already-successful capture: no second `INSERT` is attempted, the
resulting uniqueness violation is never surfaced as an error, and no second
row is created. It returns `200`, exactly as the first delivery did — the
same category of idempotency `inbound_updates`' own insert-ignore dedup
already relies on for live traffic.

**The buffer-vs-process decision and the final `replaying → idle` transition
serialize on the same row lock.** Without this, a webhook request can read
`state = 'replaying'` and then be delayed (network jitter, PHP-FPM
scheduling, a slow `CredentialVault::encrypt()` call) before its `INSERT`
into the deferred-updates table commits, while the replayer concurrently
observes zero unreplayed rows and completes the `replaying → idle` CAS —
stranding a genuinely new update in the buffer table while the plugin has
already resumed fully live, unblocked operation. Both operations take
`SELECT state, token FROM {quiescence_state} WHERE id = 1 FOR UPDATE` as
their serialization point, inside one short transaction each:

- **Webhook path** (`QuiescenceGate::decide_webhook_disposition()`): open a
  transaction, `SELECT ... FOR UPDATE` the singleton row, and — still inside
  that transaction — either (if `state === 'idle'`) commit and return
  `process`, or (otherwise) `INSERT` the encrypted row and commit, returning
  `buffer`. The insert and the state read happen under one lock.
- **Final replay transition** (last step of `replay-deferred-updates`, §6):
  open a transaction, take the *same* lock, and — still inside that
  transaction — re-run `SELECT COUNT(*) FROM {deferred_updates} WHERE replayed_at IS NULL`.
  This count is taken **inside** the lock, not before acquiring it. Only if
  the count is exactly zero and `state === 'replaying'` with the expected
  token does it perform `UPDATE ... SET state = 'idle' ...` and commit;
  otherwise it rolls back and reports the remaining count.
- Because both sides acquire the identical row lock before doing anything
  state-dependent, they cannot interleave: whichever transaction commits
  first is what the other observes once it acquires the lock. There is no
  interleaving under which a row is both invisible to the final count-check
  and left unwritten. This invariant is a required, permanent regression
  test (§7): it must never be possible for a deferred row to exist with
  `replayed_at IS NULL` while `state = 'idle'`.

If blocking, the full raw update payload is **encrypted via
`Core\Security\CredentialVault`** — the same mechanism
`Conversations\MessageRepository` already uses for message bodies, not a new
encryption scheme — and written to a new table (schema in §4). The AAD
context string passed to `CredentialVault::encrypt()`/`decrypt()` is
`"quiescence-deferred-update:{bot_id}:{update_id}"`, mirroring
`MessageRepository::context( $message_uuid )`'s per-item binding exactly: a
ciphertext row can only ever be decrypted against its own
`(bot_id, update_id)`. The controller then returns `200` — an honest
acknowledgment, since the update is durably and confidentially captured, not
discarded.

**No plaintext anywhere else.** `quiescence status`, WP-CLI output,
diagnostics, and audit-log entries referencing a deferred update may only
ever include `bot_id`, `update_id`, `update_type` (stored as its own
plaintext column, exactly as `inbound_updates` already does for its own
dedup rows — not sensitive content), counts, and timestamps. Decryption
occurs only inside the replay path, at the point the plaintext is about to
be handed to the same processing logic a live webhook call would use, and
decrypted plaintext is never itself logged or persisted a second time.

**Retention, decided now.** A `replayed_at`-stamped row is retained for
**30 days** after replay (PO-confirmed default, consistent with
`Telegram\Outbound\RetentionCleanupHandler`'s existing delivery-log
retention pattern), then deleted by a new cleanup pass folded into that same
handler's daily sweep (excluded from the drain query in §5, since it is
cleanup of already-*replayed* rows, not a live legacy-chat writer). An
**unreplayed** row (`replayed_at IS NULL`) is never deleted automatically —
only successful replay removes the need to retain it, because auto-deleting
an unreplayed encrypted payload is data loss by definition.

The processing logic currently inline in `handle_request` (topic-lifecycle
detection, `InboundAdapterBridge` refusal-first-check, `BotCommandDispatcher`
routing, plain-text reply capture) is extracted into a
`process_update( array $payload, ?DeferredReplayContext $replay_context = null ): void`
method on `WebhookController`, so both the live path and the replay path
share identical processing semantics — no second implementation to keep in
sync. `process_update()` performs the full normal pipeline for a replayed
update, including the existing `inbound_updates` dedup insert; replay is the
identical pipeline invoked later, not a shortcut around any part of it.

**`replayed_at` is set only after `process_update()` returns successfully.**
If decryption or any downstream step throws, the row is left with
`replayed_at IS NULL` (untouched — no partial mark, no separate error-status
column that could be mistaken for completion), and `replay-deferred-updates`
fails visibly: non-zero exit, an error naming the failing row's
`bot_id`/`update_id` (never its ciphertext or decrypted content), and it does
not attempt the final `replaying → idle` transition that run. **Retry is
safe by construction, not by new machinery**: Telegram's own webhook
delivery is already at-least-once, so `process_update()` — and everything it
calls — is already required to tolerate being invoked twice for the same
`update_id` in live operation; replay relies on that existing idempotency. A
failed row is retried by simply re-running `replay-deferred-updates`.

**Replay ordering is deterministic per bot**: rows are grouped by `bot_id`,
ordered by Telegram's own `update_id` ascending within each bot, with the
deferred table's own auto-increment `id` as a stable tie-breaker. No
ordering guarantee is made or needed across different bots.

**Narrow replay authority for the command-dispatch gate.**
`process_update()`'s command-routing step calls
`BotCommandDispatcher::execute()`, which — per entry point #5 above — carries
its own independent gate (defense-in-depth: a bot-command handler refuses
action outside `idle` on its own, not relying solely on its caller having
decided correctly). That gate would otherwise refuse the internal replayer's
own deferred command replay, since state is `replaying`, not `idle`. This is
resolved with a narrow, unforgeable authority object, never a global
"ignore quiescence" flag: `DeferredReplayContext` (namespace
`UniversalTelegram\Migration`), a `final` class with a **private
constructor**, instantiable only via
`QuiescenceGate::issue_replay_context(): ?DeferredReplayContext` — which
itself returns non-null only when `state === 'replaying'`, stamped with
Table 1's current `token` (§4), binding it to this specific replaying epoch.
`process_update()` accepts and forwards this optional parameter into
`BotCommandDispatcher::execute( array $update, ?DeferredReplayContext $replay_context = null )`.
The dispatcher's gate: proceed if `state === 'idle'`; else proceed **only
if** `$replay_context !== null` **and** its token matches Table 1's
*current* token (defense against a stale context surviving into a later,
different replaying episode); refuse in every other case. **The webhook's
external HTTP entry point never constructs or receives a
`DeferredReplayContext` — it always calls `process_update()` with
`$replay_context = null`**, because live processing only happens when
`state === 'idle'` (every other state causes `handle_request` to buffer
instead of calling `process_update()` at all). Only the internal replayer
(invoked exclusively from the WP-CLI `replay-deferred-updates` command)
calls `issue_replay_context()`. This is a capability object, not a flag:
there is no boolean anywhere that means "ignore quiescence," only a
narrowly-scoped, unforgeable, epoch-bound token that exactly one internal
caller can obtain, granting passage through exactly one gate, for exactly
one state.

**No automatic abandon path — a decided, PO-confirmed non-feature.**
`replaying → idle` is refused for as long as any row has
`replayed_at IS NULL`. This design deliberately ships no WP-CLI "discard
this row" or "force-idle" command — that would be exactly the kind of
bypass Support Chat ADR-0008 §6 forbids for the `is_quiescent()` signal one
layer up, letting an operator silently manufacture "drained" without
actually draining. A genuinely undecryptable row (`CredentialVault` key
rotation gone wrong, corruption — expected to be vanishingly rare given
`CredentialVault` is already production-hardened for message bodies) is a
real incident requiring manual DBA intervention with explicit PO approval
and a written record, not a designed product feature.

**Bounded-retention operational signal for unreplayed rows.** Unreplayed
rows are never auto-deleted, but `quiescence status` surfaces the age of the
oldest unreplayed row and flags a health warning once it exceeds **24
hours** unreplayed — a visible, actionable signal for an operator who ran
`exit` and forgot `replay-deferred-updates`, never a deletion trigger.

### 4. Data model — three tables

**Table 1 — canonical current state**,
`{$wpdb->prefix}universal_telegram_quiescence_state`. Exactly one row exists
for the lifetime of the install, bootstrapped with `id = 1, state = 'idle'`
at schema-migration time and never inserted into again:

```sql
CREATE TABLE IF NOT EXISTS {$table} (
  id                    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  state                 VARCHAR(16) NOT NULL,
  token                 VARCHAR(36) NOT NULL,
  entered_draining_at   DATETIME NULL,
  entered_quiescent_at  DATETIME NULL,
  entered_replaying_at  DATETIME NULL,
  exited_at             DATETIME NULL,
  updated_at            DATETIME NOT NULL
) {$charset_collate};
```

`state` is one of `'idle' | 'draining' | 'quiescent' | 'replaying'`. Every
transition is a single CAS statement —
`UPDATE ... SET state = %s, token = %s, updated_at = NOW() WHERE id = 1 AND state = %s`
— so concurrent `enter()`/`confirm()`/`exit()`/replay-progression calls
resolve safely: exactly one caller's CAS succeeds; a losing caller re-reads
current state and, per the idempotency rule in §5, treats "someone else
already made this true" as success. This CAS-via-single-UPDATE mechanic is
reused from `Persistence\MigrationLock`; its staleness/auto-reclaim policy
is deliberately **not** reused — a long-running migration window is
completely legitimate, and state must never silently revert to "chat is
live again" just because a clock elapsed. There is **no staleness, TTL, or
auto-expiry on Table 1's state**. The only way `state` ever changes is an
explicit, authenticated WP-CLI call (§6) or the replayer's own final
"backlog now empty" CAS (§5).

**Table 2 — append-only audit trail**,
`{$wpdb->prefix}universal_telegram_quiescence_transitions`:

```sql
CREATE TABLE IF NOT EXISTS {$table} (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_state     VARCHAR(16) NOT NULL,
  to_state       VARCHAR(16) NOT NULL,
  token          VARCHAR(36) NOT NULL,
  requested_by   BIGINT UNSIGNED NULL,
  requested_via  VARCHAR(32) NOT NULL,
  occurred_at    DATETIME NOT NULL
) {$charset_collate};
```

`requested_via` is `'wp-cli'` for every row in this milestone (§6). Every
successful transition on Table 1 inserts exactly one row here, in the same
DB transaction as the CAS update. Contains no payload content.

**Table 3 — deferred webhook updates**,
`{$wpdb->prefix}universal_telegram_quiescence_deferred_updates`:

```sql
CREATE TABLE IF NOT EXISTS {$table} (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bot_id              BIGINT UNSIGNED NOT NULL,
  update_id           BIGINT NOT NULL,
  update_type         VARCHAR(32) NOT NULL,
  payload_ciphertext  LONGTEXT NOT NULL,
  received_at         DATETIME NOT NULL,
  replayed_at         DATETIME NULL,
  UNIQUE KEY bot_update (bot_id, update_id)
) {$charset_collate};
```

`payload_ciphertext` is `CredentialVault::encrypt()` output, never
plaintext. Access is restricted to the replay path only, never surfaced in
any read used by `status`/CLI/diagnostics/audit.

Table 1 is the only table any write-gate check reads on its hot path
(cheap, single-row, cacheable exactly like `SchemaHealth`/`MigrationLock`
via `wp_cache_delete` on write). Tables 2 and 3 are read only by
`quiescence status`, the replayer, and forensics.

### 5. Async work and recurring sweeps — "let finish, then observe empty"

No async category below is ever paused, cancelled, or force-failed. Each is
gated only by cutting off *origination* at the §2 entry points that create
it; "drained" is proven by direct query once operator-confirmed.

| Category | Confirmed job type / hook | Drain-proof scoping |
|---|---|---|
| Topic creation | `conversation_create_topic` (`Conversations\TopicCreationHandler`) | `COUNT(*)` of pending Action Scheduler actions of this job type. Exclusively legacy-chat; no shared enqueuer exists. |
| Topic deletion | `conversation_delete_topic` (`Conversations\TopicDeletionHandler`) | Same — plus zero active `try_begin_topic_deletion()` leases (`topic_creation_state = 'delete_pending' AND topic_delete_claim_expires_at > NOW()`). |
| Outbound routing | `conversation_route_outbound` (`Conversations\ConversationOutboundHandler`) | `COUNT(*)` of pending actions of this job type. Exclusively legacy-chat. |
| **Outbound Telegram delivery** | `telegram_send_message` (`Telegram\Outbound\MessageDispatcher`) | **Not a bare job-type count** — see Context. `COUNT(*)` of pending actions of this job type whose action args' `destination_id` is present in `SELECT destination_id FROM {conversations}` (legacy-owned destinations only, by the `UNIQUE(destination_id)` exclusivity constraint). A pending action whose `destination_id` is not a legacy conversation's destination (i.e. it belongs to a Support Chat channel binding) is never counted and never blocks `confirm()`. |
| AI draft generation | `ai_draft_generate` (`AI\Draft\AIDraftGenerationHandler`) | `COUNT(*)` of pending actions of this job type, plus zero active `claim_candidate_row()`/`claim_for_generation()` leases (`status = 'generating' AND generation_lease_expires_at > NOW()`). |

**Explicitly excluded from every drain query** (verified not to mutate
legacy chat state): `operational_summary_ai_generate`,
`operational_summary_sweep`, `operational_summary_ai_lease_sweep`,
`support_chat_contract_nonce_sweep`, `diagnostics_self_test`,
`visitor_digest_evaluation_sweep`. A permanent regression test (§7) asserts
Support Chat adapter delivery (`DeliverMessageService` → `telegram_send_message`
for a binding's `destination_id`) is never counted by, or paused by, any
quiescence drain query or gate.

Three purely-internal recurring sweeps are gated directly in their own
callbacks (dedicated hooks, not shared with the Support Chat adapter),
skip-cycle behavior only, never marked failed:
`Conversations\RetentionCleanupHandler`,
`Telegram\Outbound\RetentionCleanupHandler` (excluding its new §3 deferred-row
cleanup pass, which must keep running), `AI\Draft\AiDraftLeaseSweep`.

### 6. State machine

```
idle ──enter()──▶ draining ──confirm(), drain proof holds──▶ quiescent ──exit()──▶ replaying ──(backlog empty)──▶ idle
```

- **`enter()`**: CAS `idle → draining`. Idempotent. All eight §2 entry-point
  gates and the three §5 sweep gates start blocking the instant
  `state != 'idle'` — including `replaying`, so external legacy writers are
  never unblocked while replay is still in progress.
- **`draining → quiescent`**: the result of `confirm()` re-checking every
  drain condition in §5 and, only if every condition holds, performing the
  CAS and stamping `entered_quiescent_at`. Does **not** require the deferred-
  update backlog to be empty — new webhook traffic continues arriving and
  being buffered throughout `quiescent`; that is the point of buffering.
- **`quiescent → replaying`**: triggered by `exit()`. `exit()` does not go
  straight to `idle`. It marks operator intent to conclude the window while
  the system remains write-blocking (`replaying`) until the deferred-update
  backlog is fully applied. The only thing permitted to mutate legacy-chat
  state during `replaying` is the internal replayer calling
  `WebhookController::process_update()` against buffered rows, carrying a
  `DeferredReplayContext` (§3). New webhook arrivals during `replaying` are
  still buffered, not processed live, so the backlog is self-draining.
- **`replaying → idle`**: CAS gated on zero deferred rows with
  `replayed_at IS NULL`, checked and committed atomically under the same row
  lock the webhook's buffer-vs-process decision uses (§3). Driven by
  repeatedly running `wp universal-telegram quiescence replay-deferred-updates`
  (§6.1), which processes currently-known unreplayed rows in deterministic
  per-bot order and then attempts this final locked CAS; if new rows arrived
  concurrently, the CAS condition is not met, the command reports the
  remaining count, and the operator re-runs it.
- **Abort from `draining`**: `exit()` called while still `draining` (before
  `confirm()` ever succeeded) still transitions through `replaying` rather
  than straight to `idle`, because entry-point blocking may already have
  caused webhook updates to be buffered even though the async-job drain
  never completed. Nothing is exempt from "backlog must be empty before
  idle."
- **Who can trigger transitions**: WP-CLI only (§6.1), the same OS-shell
  authority boundary ADR-0008 §4 already establishes for
  `LegacyExportServiceV1`. No REST route, no admin-UI action button.
- **Support Chat's relationship**: Phase B never touches this state machine.
  It only calls `is_quiescent()` via the cross-plugin provider (§8), which
  requires both `state === 'quiescent'` *and* an empty deferred-update
  backlog at the moment of the call.

### 6.1 Operator workflow — WP-CLI only

New command, `src/Migration/Cli/QuiescenceCommand.php` (namespace
`UniversalTelegram\Migration\Cli`, registered under the same
`defined( 'WP_CLI' ) && WP_CLI` guard `LegacyMigrateCommand` uses in the
Support Chat repository, applied identically here):

```
wp universal-telegram quiescence enter                    --assume-quiescence-authority
wp universal-telegram quiescence status
wp universal-telegram quiescence confirm
wp universal-telegram quiescence exit                     --assume-quiescence-authority
wp universal-telegram quiescence replay-deferred-updates
```

- `enter`: requires `--assume-quiescence-authority`. `idle → draining`,
  prints a warning that live visitor chat traffic and Telegram commands
  start being refused/buffered.
- `status`: read-only. Prints `state`, the per-category drain breakdown
  (§5), the deferred-update backlog count (never contents), the oldest
  unreplayed row's age and the 24-hour health flag (§3), and the
  live-computed `is_quiescent()` value (§8) so the operator sees exactly
  what Phase B would currently observe.
- `confirm`: `draining → quiescent`. Safe to re-run; non-zero exit and a
  specific "still draining: N pending outbound jobs, M active topic-creation
  leases" message when not yet drained. Does not consider the deferred-
  update backlog.
- `exit`: requires `--assume-quiescence-authority`. `quiescent → replaying`
  (or `draining → replaying` if aborting). Prints the current backlog count
  and instructs the operator to run `replay-deferred-updates`.
- `replay-deferred-updates`: decrypts and processes each unreplayed row,
  grouped by `bot_id`, ordered by `update_id` ascending (`id` tie-breaker),
  through `process_update()`; stamps `replayed_at` only on success, leaves a
  failing row untouched and exits non-zero naming the failing
  `bot_id`/`update_id`; then, only if every row succeeded, attempts the
  locked `replaying → idle` CAS. Safe to re-run repeatedly.

### 7. Test strategy (required, not exhaustive — full list in the accompanying plan document)

UT unit/integration coverage must include, at minimum: state-machine CAS
transitions across all four states and concurrent-`enter()` resolution with
audit-row insertion; a 409/blocked assertion at each of the eight §2 entry
points; webhook encryption correctness (`payload_ciphertext` never
plaintext-equal to the raw payload; a row's ciphertext fails to decrypt
under a different `update_id`'s AAD context); duplicate-delivery
idempotency at each of `draining`/`quiescent`/`replaying` (both requests
return `200`, exactly one row exists, no plaintext anywhere); the two-
transaction interleaving test proving no unreplayed deferred row can ever
coexist with `state = 'idle'`; `DeferredReplayContext` cannot be constructed
outside `QuiescenceGate::issue_replay_context()`, is `null` unless
`state === 'replaying'`, and is rejected once its epoch's token no longer
matches; replay success/failure/retry/ordering semantics; and — required by
the milestone-0 finding above — a permanent non-interference test proving
Support Chat adapter delivery (`DeliverMessageService`) is never counted by
or paused by any quiescence drain query or gate, exercised end-to-end while
`state = 'quiescent'`.

### 8. Cross-plugin exposure — in-process only, no REST, no shared secret

Following the same pattern as `legacy_export_service()` (`Core\Plugin.php`,
consumed by Support Chat's `InProcessLegacyExportClient`, the only existing
precedent in either repository for in-process, same-install, no-REST
cross-plugin exposure):

`src/Migration/QuiescenceStatus.php` (namespace `UniversalTelegram\Migration`):

```php
final class QuiescenceStatus {
    public function __construct(
        public readonly bool $is_quiescent,
        public readonly ?\DateTimeImmutable $since
    ) {}
}
```

`Core\Plugin.php` gains one accessor:

```php
public function quiescence_status(): ?QuiescenceStatus {
    if ( null === $this->quiescence_gate ) {
        return null;
    }
    $is_state_quiescent = 'quiescent' === $this->quiescence_gate->state()->value;
    $backlog_empty      = 0 === $this->quiescence_gate->deferred_update_backlog_count();
    return new QuiescenceStatus(
        $is_state_quiescent && $backlog_empty,
        $this->quiescence_gate->since()
    );
}
```

`deferred_update_backlog_count()` is a cheap `COUNT(*) WHERE replayed_at IS NULL`
against Table 3, never touching `payload_ciphertext`. **`is_quiescent` can
become `false` again without any explicit state transition**, purely because
a new webhook update arrived and was buffered while in `quiescent` state —
intentional: Support Chat's Phase B must never observe "quiescent" while an
unresolved buffered update exists, since that update is legacy-chat activity
Phase B's export snapshot cannot see. No REST route, no Ajax handler; this
accessor is not WP-CLI-restricted, since the boolean/timestamp/count it
returns is not plaintext conversation content.

## Alternatives

1. **Force-fail/cancel in-flight jobs to reach "drained" faster.** Rejected:
   discards outbound deliveries, topic work, retries, and AI jobs — real
   data loss with no compensating benefit, since the entry-point cutoff
   already bounds how much new work can ever be created.
2. **Return a bare `200`/drop Telegram webhook updates during blocking
   states.** Rejected: silently loses operator replies and commands;
   Telegram's own retry window is not bounded by migration duration.
3. **Gate at `Queue\Dispatcher`/`WorkerRunner` (queue- or group-level).**
   Rejected: confirmed to pause Support Chat adapter delivery, which shares
   the same queue infrastructure.
4. **Single current-state table also serving as the audit trail.**
   Rejected: a CAS-updated singleton row cannot simultaneously be an
   append-only history; splitting into Table 1 (state) and Table 2 (audit)
   resolves the contradiction.
5. **TTL/staleness auto-expiry on the quiescent state (mirroring
   `MigrationLock`).** Rejected: a legitimate migration window has no
   predictable upper bound; auto-reverting to "live" on a timer risks
   silently unblocking writers mid-migration.
6. **Store buffered Telegram payloads as plaintext.** Rejected: violates
   this plugin's existing encrypted-at-rest posture for message content;
   `CredentialVault` already exists and is already production-hardened for
   exactly this class of data.
7. **Replay after `exit()`, concurrently with resumed live traffic.**
   Rejected: reorders deferred commands/replies against fresh activity
   (stale `/resolve`/`/reopen` applied late, new visitor messages processed
   before older deferred Telegram updates). The `replaying` state closes
   this by keeping external writers blocked until the backlog is empty.
8. **A global "ignore quiescence" bypass flag for the internal replayer.**
   Rejected: exactly the class of escape hatch Support Chat ADR-0008 §6
   forbids one layer up; `DeferredReplayContext` grants passage through
   exactly one gate, to exactly one caller, for exactly one epoch, instead.
9. **A WP-CLI "force-idle"/"discard buffered row" command.** Rejected: would
   let an operator manufacture "drained" without actually draining,
   undermining the entire guarantee Support Chat's Phase B depends on.
   An undecryptable/unreplayable row is a manual-intervention incident, not
   a shipped feature.
10. **Bare job-type-count drain proof for `telegram_send_message`.**
    Rejected after source verification: conflates legacy-chat and Support
    Chat adapter traffic, since both enqueue the identical job type through
    the identical dispatcher. Resolved with the `destination_id`-join
    refinement in §5.

## Consequences

- Universal Telegram gains three new tables, a four-state CAS state machine,
  a new WP-CLI command surface, and one new in-process accessor. No REST
  route, Ajax handler, shared secret, or cross-plugin SQL access is
  introduced.
- Support Chat's Phase B gains a real, non-default-deny quiescence signal,
  but only once a corresponding Support Chat–side amendment (documented
  separately, see the accompanying plan document and the Support Chat WP3-4
  closure addendum) makes `PhaseBReconciliationService` re-check
  `is_quiescent()` continuously rather than once at the start of `run()`.
  Without that amendment, this provider alone is not sufficient for Phase B
  to be safe against a mid-run buffered update.
- Operationally, a genuine Phase B run can only complete while the deferred-
  update backlog remains empty for the run's entire duration — if Telegram
  traffic arrives frequently during a `quiescent` window, Phase B may need
  multiple attempts. This is deliberate: correctness over convenience.
- A future cutover work package (undesigned, out of scope here) will need
  its own handoff design for buffered updates arriving during a cutover-
  adjacent quiescence window — applying them into Support Chat's already-
  migrated data rather than replaying into a Universal Telegram legacy store
  being retired. This ADR's contribution to that future problem is only
  that a durable, encrypted, ordered backlog table already exists for such
  a design to read from.
- The `deleted_user` cleanup deferral (entry point #7) and dead-letter
  requeue block (entry point #8) are both named, PO-confirmed trade-offs,
  not oversights.

## Security and privacy impact

- Buffered Telegram payloads are encrypted at rest via `CredentialVault`
  with a per-row AAD context bound to `(bot_id, update_id)`, preventing
  cross-row ciphertext substitution. Plaintext exists only transiently in
  memory during `process_update()` and is never logged, cached, or
  persisted a second time.
- The state machine and deferred-update replay are reachable only via
  WP-CLI, matching the existing OS-shell-authority security boundary this
  repository already uses for `LegacyExportServiceV1` (ADR-0039 §2).
- No new externally reachable HTTP surface is introduced. The
  `quiescence_status()` accessor exposes only a boolean, an optional
  timestamp, and (via `status`) counts and non-sensitive metadata —
  never conversation content.
- Retention is explicit and asymmetric by design: replayed rows are
  deleted after 30 days; unreplayed rows are never auto-deleted, because
  auto-deletion of an unresolved encrypted payload would be silent data
  loss.

## Affected Documents/Milestones

- Supersedes no prior ADR. Fulfils the forward commitment made in
  ADR-0039 §3.
- Authorises the accompanying implementation plan,
  `docs/plans/wp2-legacy-chat-quiescence-plan-v1.md`.
- Cross-references Support Chat ADR-0008 §6 (interface owner) and the
  Support Chat WP3-4 closure addendum required by §5's operational
  consequence above (owned and recorded in the Support Chat repository, not
  duplicated here, per this repository's existing rule against copying
  another repository's ADR text — ADR-0037/0038/0039 precedent).

## Compatibility/Migration Impact

- Additive only: three new tables, bootstrapped with a schema migration
  step that inserts Table 1's single `id = 1, state = 'idle'` row. No
  existing table, column, or index is altered.
- No behavior change for any installation that never invokes the new
  WP-CLI command surface — every gate's default state is `idle`, under
  which every entry point behaves exactly as it does today.
- No production quiescence operation, cutover, route switch, soak,
  rollback, or migration execution is authorised by this ADR. This ADR
  covers write-blocking and drain only.
