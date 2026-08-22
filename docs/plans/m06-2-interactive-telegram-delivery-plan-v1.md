# M06.2 — Interactive Telegram Delivery — Implementation Plan v1

## 0. Baseline

- Repository: `/opt/biopentra/dev/universal-telegram`.
- **M06.1 has merged.** Re-verified immediately before this freeze pass: `origin/main` is now at
  `388615670f3854584dc7ed76c91285f400d42249` (`docs(m06-1): bot setup wizard closure record`,
  2026-08-22 13:31:27), which includes merge commit `e3d329a` (PR #14, `feature/m06-1-bot-setup-wizard`
  into `main`). M06.1's own closure record
  (`docs/closure/m06-1-bot-setup-wizard-closure.md`) records its starting baseline as `b37cc13` (the
  SHA this plan first drafted against) and status **PASS**, with Product Owner acceptance of the
  wizard itself separately pending — unrelated to, and not blocking, this plan. All source excerpts
  in this plan were re-read via `git show origin/main:<path>` against this current SHA, never from
  the local working tree (which remains on `feature/m06-1-bot-setup-wizard`, now stale relative to
  `main`; no file from that branch was read or relied on).
- **Version confirmed:** `universal-telegram.php` line 6 (`Version:` header) and line 21
  (`define( 'UNIVERSAL_TELEGRAM_VERSION', '0.6.0' )`) both read `0.6.0`, added by M06.1 commit
  `e3e9314`. This plan's version work (§8) targets `0.6.0 → 0.6.1`.
- **Pre-existing baseline defect, noted not fixed here:** `readme.txt`'s `Stable tag` still reads
  `0.5.0` — M06.1's version-bump commit updated the plugin file but not `readme.txt`. This is not
  introduced by this plan, but WP5 (§7) must correct it directly to `0.6.1` (not merely bump it by
  one patch level from its stale value), since WP5's own documentation-consistency check would
  otherwise fail against a pre-existing inconsistency it did not cause.
- **No new ADR from M06.1** (confirmed: `docs/adr/` still ends at `0022`; M06.1 was UI-only,
  consistent with its closure record) — the ADR number this plan uses (`0023`) is confirmed correct,
  not merely provisional.
- **`db_version` confirmed unchanged at `13`** (`src/Persistence/Migrator.php::target_version()`) —
  M06.1 added no migration step.
- Installed dependency verified directly from source: `woocommerce/action-scheduler` 4.1.0 at
  `vendor/woocommerce/action-scheduler` (composer.json pins `^4.1`), unchanged by M06.1.

## 1. Context

M05/M06 deliberately keep every visitor-facing REST request free of synchronous Telegram I/O
(ADR-0021, ADR-0012): a chat message is persisted, then a job is enqueued via the plugin's one
generic `Queue\Dispatcher` (ADR-0006, built on Action Scheduler). In practice this durable enqueue
is not the same thing as prompt execution, and today nothing in the interactive path causes prompt
execution — so an ordinary visitor chat message can sit for minutes before a human sees it in
Telegram, and an administrator's "Test message" button gives no immediate feedback at all. This
plan removes that avoidable delay using only mechanisms Action Scheduler and the plugin already
ship, without adding a second send path, a public execution endpoint, a fairness regression for
ordinary queue traffic, or any change to durability, retry, or dead-letter semantics.

## 2. Root-cause model

**Verified facts** (read directly from `vendor/woocommerce/action-scheduler` 4.1.0 and this
plugin's own source at the baseline SHA):

- `Queue\Dispatcher::enqueue()` (`src/Queue/Dispatcher.php:47`) calls
  `as_enqueue_async_action( WorkerRunner::HOOK, ..., WorkerRunner::GROUP )` — every job in this
  plugin (Telegram sends, topic creation, conversation routing, diagnostics, notifications) goes
  through this one call. This schedules the action as *due now* in Action Scheduler's own store;
  it does not run it.
- Action Scheduler has exactly two triggers that turn a due action into an executed one:
  1. **WP-Cron.** `ActionScheduler_QueueRunner::init()` schedules its own recurring `wp_schedule_event()`
     entry on hook `action_scheduler_run_queue`, interval `every_minute`
     (`ActionScheduler_QueueRunner::WP_CRON_SCHEDULE`,
     `vendor/.../classes/ActionScheduler_QueueRunner.php:7-8,83-95`). This is itself gated by
     ordinary WP-Cron mechanics: WP-Cron only fires from an incoming page request whose scheduled
     time has passed, and — per this project's own `CLAUDE.md` — this VPS's WordPress installs run
     with `DISABLE_WP_CRON` set and a real cron script (`scripts/wp-cron.sh`) invoked from
     `magpern`'s crontab **every 5 minutes**. A visitor mid-conversation, with no other page load
     hitting the site, can wait up to that full external interval.
  2. **The async loopback request.** `ActionScheduler_QueueRunner::hook_dispatch_async_request()`
     hooks `maybe_dispatch_async_request()` onto WordPress's `shutdown` action
     (`ActionScheduler_QueueRunner.php:104,132-147`). That method's *first* condition is
     `is_admin()` — **it only ever fires the loopback dispatch when the current request is a
     wp-admin request.** A visitor's public REST request (`is_admin()` false) never reaches this
     branch at all, regardless of load or timing. When it does fire, it is further throttled to at
     most once per 60 seconds by `ActionScheduler::lock()`, and then only dispatches a non-blocking
     (`timeout => 0.01, blocking => false`) `wp_remote_post()` to the pre-registered,
     nonce-verified `wp_ajax(_nopriv)_as_async_request_queue_runner` handler
     (`vendor/.../lib/WP_Async_Request.php:65-91`, `ActionScheduler_AsyncRequest_QueueRunner.php`).

Consequence, stated as verified fact, not hypothesis: **every interactive job enqueued from a
visitor (non-admin) REST request today depends entirely on the ~5-minute external cron cadence for
this VPS** (or a coincidental unrelated wp-admin page view by staff), because the one faster path
Action Scheduler ships is unconditionally gated on `is_admin()`.

- `Conversations\Rest\ConversationsController` (`src/Conversations/Rest/ConversationsController.php:311-312`)
  calls, in order, `TopicCreationDispatcher::maybe_create()` then `ConversationOutboundDispatcher::route()`
  — both are thin wrappers that build a `JobEnvelope` and call the same `Dispatcher::enqueue()`. No
  additional dependency-ordering delay is introduced here beyond the shared enqueue-trigger gap
  above: `ConversationOutboundHandler::handle_job()` (`src/Conversations/ConversationOutboundHandler.php`)
  already re-checks `topic_creation_state` on every run and, if not yet `created`, self-reschedules
  via `as_schedule_single_action( time() + 5, ... )` (`POLL_DELAY_SECONDS = 5`,
  `MAX_WAIT_SECONDS = 300`) — a 5-second, not 5-minute, internal cadence once *any* execution of
  that job has actually started. The 5-minute ceiling is a safety bound on total abandonment, not
  the steady-state polling interval.
- `Administration\Telegram\BotManagementController::send_test_message()`
  (`src/Administration/Telegram/BotManagementController.php:265-273`) calls
  `MessageDispatcher::send()` — the identical durable-queue path used for every other Telegram send
  — then immediately redirects. It gives the administrator no send result at all, immediate or
  otherwise. **By contrast, the adjacent `test_connection()` method in the same class already calls
  `TelegramApiClient::get_me()` synchronously**, bounded by `TelegramApiClient`'s own
  constructor-configurable HTTP timeout (`$timeout_seconds = 10` default,
  `src/Telegram/Client/TelegramApiClient.php:34`) — the established, already-shipped precedent for a
  bounded synchronous diagnostic call against this same client.
- Webhook → widget: `WebhookController`'s conversation-scoped capture (M05 plan §6) is already fully
  synchronous, in-request persistence — no queue involved on the inbound side. The chat widget's
  active poll interval is `POLL_INTERVAL_MS = 3000` with exponential backoff capped at 30s
  (`assets/js/chat-widget.js:113-114`). **Finding: this is not a source of avoidable delay** — a
  3-second worst-case poll latency is already within the 2–5 second target band. No change proposed
  for this leg.
- `Queue\RetryPolicy` defaults (`src/Queue/RetryPolicy.php:21-23`): `MAX_ATTEMPTS = 5`,
  `BASE_DELAY_SECONDS = 30`, `MAX_DELAY_SECONDS = 900` — unrelated to the root cause (these only
  govern retry backoff *after* a job has actually run and failed), confirmed unchanged by this plan.

**Stated hypothesis, clearly labeled as such:** on a stock WordPress installation with WP-Cron left
enabled, the same `is_admin()` gate still applies — the fix below removes the dependency on both
cron paths for interactive jobs, rather than accepting a hypothesis-dependent fix, because the exact
cron cadence a given install runs under cannot be relied upon.

## 3. Delivery design

### 3.1 Expedited dispatch mechanism

Add one new, small collaborator, `Queue\ExpeditedDispatchTrigger`, owned by the `Queue` boundary (no
new boundary — this is queue-dispatch plumbing, not a new product concern).

**Fairness policy — resolved.** Action Scheduler's claim query
(`ActionScheduler_Store::stake_claim()`) orders due actions by priority across *every* claim, not
merely within a single already-mixed batch. Differentiating priority for interactive jobs (a
non-default value below Action Scheduler's default `10`) would therefore let a sustained stream of
chat messages keep winning every claim ahead of priority-`10` notification/event jobs indefinitely —
genuine starvation, not a bounded cost. **This plan resolves the fairness question by choosing
identical priority for every job type and relying solely on expedited triggering (§3.1) for
interactive latency.** No `JobEnvelope`/`Dispatcher` priority parameter is introduced. This is a
deliberate rejection of the cap/coalesce alternative (bounding how much differentiated-priority work
runs per window): that approach would still require priority differentiation plus new
capping/coalescing state and its own tests, solving the same problem with materially more code for
no latency benefit over triggering alone — the trigger already causes a run *sooner*, which is the
actual requirement; it does not need to also cut in line once that run happens. A sustained-burst
fairness test (§6) proves normal queued work still executes under continuous chat load using this
design, precisely because every job shares one FIFO-by-due-date, equal-priority ordering.

**Truthful diagnostic states — resolved, and scoped to what the audit log can safely hold.** A
non-blocking loopback (`wp_remote_post()` with `blocking => false, timeout => 0.01`) cannot prove the
remote runner started or finished; the calling code returns before any response is possible.
`ExpeditedDispatchTrigger` therefore never claims "dispatched" or "started." A further, operational
constraint narrows this beyond the previous draft: `Audit\AuditLogger` writes to
`universal_telegram_audit_log`, a table with **no retention/cleanup handler of its own** — verified
by grep across every `RetentionCleanupHandler`-pattern registration in `src/Core/Plugin.php`, which
covers outbound messages, conversations, and event fatal-error markers, but not the audit log table
itself. Writing one audit row per successful visitor chat message (the routine, high-frequency case)
would therefore grow that table without bound, for a state that the previous draft itself already
conceded is not meaningful delivery evidence — "asking was attempted" proves nothing about outcome.
**Resolution: only the two abnormal, inherently rare states are audited.** The routine-success case
is not recorded at all — a healthy site issues the loopback call silently, exactly once per accepted
message, with zero added persistent state:

- `expedited_dispatch_declined_concurrency` — computed *before* issuing the request, from the exact
  same public conditions Action Scheduler's own async runner checks
  (`ActionScheduler::runner()->has_maximum_concurrent_batches()`,
  `ActionScheduler::store()->has_pending_actions_due()` — both public methods, verified at
  `vendor/.../classes/abstracts/ActionScheduler_Abstract_QueueRunner.php:306` and
  `.../ActionScheduler_Store.php:468`). Fires only under genuine contention or an already-empty due
  queue — bounded by actual degraded/idle conditions, not by ordinary chat volume.
- `expedited_dispatch_unavailable` — the dependency guard failed, or any step inside the guarded
  block (below) threw. Fires only on a genuinely broken or incompatible Action Scheduler
  installation — likewise bounded by an abnormal condition, not routine traffic.
- **Queue age and the job's own eventual recorded outcome (`outbound_messages.status`,
  `conversation_messages.delivery_state`) remain the only truthful evidence of whether expediting
  actually helped** — surfaced separately in diagnostics (§5), never conflated with the two
  request-time states above.

**Hardened internal-dependency guard — resolved, with the entire path inside one guarded block, and
a genuinely testable seam.** `ActionScheduler_AsyncRequest_QueueRunner` is Action Scheduler's own
internal implementation class (not part of `functions.php`'s documented public surface, per that
project's own `AGENTS.md`). The previous draft left `ActionScheduler::runner()`/`store()` and both
pre-check calls *outside* the `try` block — any exception there (e.g., a future Action Scheduler
version changing `runner()`'s own behavior) would have escaped uncaught. Corrected so that every
step — dependency check, pre-check, runner creation, and invocation — runs inside one `try/catch`,
and so that the compatibility seam is genuinely exercisable by a test (a `: ActionScheduler_AsyncRequest_QueueRunner`
return-type declaration, as previously drafted, cannot be overridden to return an object PHP itself
would reject before the trigger's own logic ever runs — the corrected seam has no such type
constraint, and the trigger explicitly checks `method_exists()` on the concrete returned object,
never on the class name alone, so a stand-in without that method is only ever detected by the
trigger's own logic, exactly as a real incompatible library version would be):

```php
final class ExpeditedDispatchTrigger {
    public function __construct( private readonly AuditLogger $audit ) {}

    public function trigger(): void {
        try {
            if ( ! $this->dependency_available() ) {
                $this->audit->log( 'expedited_dispatch_unavailable' );
                return;
            }

            if ( $this->declined_for_concurrency() ) {
                $this->audit->log( 'expedited_dispatch_declined_concurrency' );
                return;
            }

            $runner = $this->create_runner();

            if ( ! is_object( $runner ) || ! method_exists( $runner, 'maybe_dispatch' ) ) {
                $this->audit->log( 'expedited_dispatch_unavailable' );
                return;
            }

            $runner->maybe_dispatch();
            // No audit entry on success — see the diagnostic-states note above:
            // a fire-and-forget request proves nothing about outcome, and the
            // audit_log table has no retention policy to absorb per-message rows.
        } catch ( \Throwable $exception ) {
            $this->audit->log( 'expedited_dispatch_unavailable' );
        }
    }

    // Overridable for tests to simulate a missing ActionScheduler install.
    protected function dependency_available(): bool {
        return class_exists( \ActionScheduler::class )
            && class_exists( \ActionScheduler_AsyncRequest_QueueRunner::class );
    }

    // Overridable for tests to force the declined-concurrency branch without
    // needing a real second in-flight batch.
    protected function declined_for_concurrency(): bool {
        return \ActionScheduler::runner()->has_maximum_concurrent_batches()
            || ! \ActionScheduler::store()->has_pending_actions_due();
    }

    /**
     * Deliberately untyped return: production returns a real
     * ActionScheduler_AsyncRequest_QueueRunner, but tests override this to
     * return a stub missing maybe_dispatch(), or one that throws from it, or
     * to throw during construction itself — none of which a declared return
     * type would permit PHP to accept, defeating the point of the seam.
     * Mirrors Queue\Dispatcher::schedule_action()'s own documented
     * test-override precedent (production code never overrides either).
     *
     * @return object
     */
    protected function create_runner() {
        return new \ActionScheduler_AsyncRequest_QueueRunner( \ActionScheduler::store() );
    }
}
```

Every failure mode — missing class, a declined concurrency pre-check, a runner object missing the
expected method, or a thrown exception from *any* of the steps above, including ones the previous
draft left unguarded — is caught by the single outer `try/catch`, audited under one of exactly two
fixed codes (never the exception's own message, which could vary), and returns normally. The durable
job Action Scheduler already holds is completely untouched by any of these branches; normal cron
cadence always remains the fallback, exactly as if this trigger did not exist.

- **Call site, exact branch — resolved.** Read directly from
  `ConversationsController.php`'s message-post handler: the idempotent-replay branch
  (`$existing_message = $this->messages->find_by_idempotency_key(...); if ( null !== $existing_message ) { return ...200; }`)
  and the storage-failure branch (`if ( null === $message ) { return ...503; }`) both `return`
  *before* the code ever reaches `$this->topic_creation->maybe_create( $conversation )` /
  `$this->outbound->route( $message->id(), $conversation->id() )` at lines 311–312. The new call —
  `ExpeditedDispatchTrigger::trigger()` — is added immediately after that existing `route()` call and
  before the handler's final `return $this->respond( array( 'ok' => true ), 200 )`. Because of the
  two early returns above, this placement is *only* reachable when `$message = $this->messages->create(...)`
  has just returned a non-null, newly-inserted message row — i.e., a newly accepted, newly enqueued
  visitor message, never an idempotent replay and never a storage failure. No new conditional is
  needed to enforce this; it falls out of the handler's existing control flow, and is asserted
  directly by the "not called again for a duplicate/idempotent-replay" case in §6 item 3.
- **Nowhere else.** Notification-rule dispatch (M02), digests (M11-future), diagnostics self-test,
  and retry/dead-letter paths are untouched — they keep depending on normal cron cadence exactly as
  today, satisfying "do not make ordinary rule/event notification traffic high priority."

### 3.2 Fallback path

If `dependency_available()` is false, if the local concurrency pre-check declines, or if
construction/invocation throws, the job remains exactly where `Dispatcher::enqueue()` already left
it: a durable, `pending`, due Action Scheduler action at the plugin's ordinary, unmodified priority.
Normal scheduled processing (WP-Cron / any wp-admin-triggered async dispatch) picks it up exactly as
it does today. No code path treats expedited dispatch as required for correctness — only as a
latency optimization layered strictly on top of the existing durable mechanism.

### 3.3 Topic creation → first message ordering, concurrency, duplicate prevention

Unchanged from M05 (ADR-0021, plan §5) and re-verified as unaffected by this plan:

- `ConversationRepository::try_begin_topic_creation()`'s atomic compare-and-set remains the sole
  gate on enqueuing a `TopicCreationHandler` job — expediting dispatch does not add a second path
  that could enqueue a second topic-creation job; it only asks Action Scheduler to run sooner
  whatever was already, uniquely, enqueued.
- `ConversationOutboundHandler`'s existing self-reschedule (5-second poll, 300-second ceiling,
  `src/Conversations/ConversationOutboundHandler.php:154-163`) is untouched.
- No new claim/lock is introduced. Action Scheduler's own `stake_claim()` batching remains the only
  concurrency guard, exactly as for every other job type today — calling `maybe_dispatch()` an
  unbounded number of times cannot cause double-execution of the same due action.

### 3.4 Administrator "Test message" — bounded synchronous diagnostic send

Reuses `test_connection()`'s already-shipped pattern in the same class
(`BotManagementController.php:240-257`), not a new architecture:

- Replace `send_test_message()`'s call to `MessageDispatcher::send()` (which queues) with a direct,
  synchronous call to the existing `TelegramApiClient::send_message()`, using the bot's existing
  decrypted token (`BotProfileRepository::decrypt_token()`, identical to `test_connection()`) and the
  same `TelegramFailureClassifier` (ADR-0014) already used by the queue's own send handler.
- **Bounded timeout:** inject a second `TelegramApiClient` instance constructed with an explicit
  shorter timeout (e.g. `new TelegramApiClient( 8 )`) for this one diagnostic call — a
  constructor-parameter difference, not a new client class.
- **Result semantics:** on `TelegramApiResult::ok()`, show a message-sent confirmation; on failure,
  classify via `TelegramFailureClassifier` and show the fixed, non-raw failure category
  (`RATE_LIMITED`/`TERMINAL`/`TOKEN_INVALID`/`RETRYABLE`) — never Telegram's raw `description` text.
  No retry — one bounded attempt only, since retry belongs to the durable queue path this action
  explicitly bypasses.
- **No parallel sender.** This is the second and only other caller of
  `Telegram\Client\TelegramApiClient::send_message()` (the first being
  `Telegram\Outbound\SendMessageHandler`) — same client, same credential decryption, same failure
  classifier. An audit log entry is written for both outcomes.

### 3.5 Schema/version

**No schema or database-version change.** Expedited-dispatch state is transient, per-request
information recorded via the existing `AuditLogger` (existing `audit_log` table), not a new column
or table. The bounded Test Message send needs no new persistence: it deliberately does not create an
`outbound_messages` row. `db_version` is carried forward unchanged from whatever value M06.1's merge
leaves in place (last independently confirmed at `13` at the M06-core baseline; WP0 re-confirms the
actual value after M06.1 merges, per §0).

## 4. Security and performance

- No new endpoint of any kind. The loopback call lands on Action Scheduler's own pre-existing
  `wp_ajax(_nopriv)_as_async_request_queue_runner` hook, protected by its own `check_ajax_referer()`
  nonce check — the same call already made today from any wp-admin page load.
- No raw token exposure: `ExpeditedDispatchTrigger` never touches a bot token, message content, or
  any `SENSITIVE`/`SECRET`-classified value. The bounded Test Message path reuses
  `TelegramApiClient`'s existing decrypted in-memory token handling — no new credential surface.
- Bounded work per web request: the interactive REST response does at most one additional
  synchronous public-API check (`has_maximum_concurrent_batches()`, `has_pending_actions_due()` —
  both single, indexed-count queries, mirroring `Queue\QueueHealth`'s existing cost) plus, at most,
  one non-blocking `wp_remote_post()` call (`timeout => 0.01`) — it still performs zero Telegram
  network I/O and zero topic creation synchronously, preserving the M05 frozen constraint exactly.
  The admin Test Message action is the one explicitly-authorized exception, bounded to ≤8s.
- **Fairness.** All jobs share Action Scheduler's default priority (`10`); the fairness
  guarantee is structural (FIFO-by-due-date, no differentiated priority to game), not a bound on how
  much differentiated work is allowed to run. A sustained burst of chat-originated jobs cannot push a
  normal notification job's due-date ordering backward, because nothing about expedited triggering
  changes any job's scheduled date or priority — it only causes an extra, ordinary queue run to
  happen sooner, which processes whatever is *actually* due, interactive or not, in the same
  unmodified order the cron path would have used anyway. §6 adds a sustained-burst test proving this.
- Concurrency: `has_maximum_concurrent_batches()`'s existing cap (default 1 concurrent batch,
  filterable) applies identically regardless of which code path requested a run — a burst of chat
  traffic cannot spawn unbounded concurrent queue runners.
- Honest guarantees: nowhere does this plan promise a fixed end-to-end delivery time. Diagnostics
  (§5) and documentation state "typically a few seconds under healthy conditions, cron fallback
  otherwise" — never a numeric SLA.

## 5. Diagnostics

Extend the existing `Administration\Diagnostics\DiagnosticsReport` (no new admin surface):

- **Interactive job enqueue time** — already implicit in each `outbound_messages`/
  `conversation_messages` row's existing `created_at`; no new column.
- **Expedited-dispatch abnormal-outcome counts** — one `AuditLogger` entry per call to
  `ExpeditedDispatchTrigger::trigger()` that takes either non-routine branch, using exactly the two
  fixed codes defined in §3.1 (`expedited_dispatch_declined_concurrency` /
  `expedited_dispatch_unavailable`). The routine-success path writes nothing (§3.1 — the audit_log
  table has no retention policy, so a per-message row for the expected, high-frequency case is not
  written; absence of these two codes over a given window is itself the healthy signal). Never
  "dispatched"/"started", which this plugin cannot actually observe. Mirrors the existing
  fixed-reason-code convention (`dead_letter_reason`, `conversation_topic_*` codes) — never free
  text, never message content.
- **Queue age** — `Queue\QueueHealth` already exposes `pending_count()`/`failed_count()` scoped to
  the plugin's own Action Scheduler group; extend it with one additional read-only method,
  `oldest_pending_age_seconds()`, using the same `ActionScheduler::store()->query_actions()` pattern
  already established in that class.
- **Final delivery outcome** — already recorded by the existing pipeline
  (`outbound_messages.status`, `conversation_messages.delivery_state`); no new outcome states.
  Combined with queue age, this — not the request-time audit code — is the actual evidence of
  whether expediting worked for a given message.
- No analytics, no new persistent sensitive content: every new diagnostic value is a count, an age
  in seconds, or a fixed non-content reason code.

## 6. Exact test plan

All against `origin/main` baseline conventions (PHPUnit + WP test harness, existing HTTP
interception fixtures — no live Telegram calls, no live bot):

1. **Admin Test Message — immediate success.** `BotManagementControllerTest`: mock
   `TelegramApiClient::send_message()` to return an `ok()` result; assert the controller's response
   reflects success without any `Queue\Dispatcher::enqueue()` call and without creating an
   `outbound_messages` row.
2. **Admin Test Message — controlled timeout/failure.** Mock each `FailureClassification` case;
   assert the admin-visible message is the fixed classified code, never the mocked raw `description`
   string — regression-asserts no credential/raw-error leakage.
3. **Visitor message — expedited dispatch call placement.** `ConversationsControllerTest`: inject a
   test double for `ExpeditedDispatchTrigger` (mirroring `DispatcherTest`'s existing
   `schedule_action()`-override pattern); assert `trigger()` is called exactly once for a newly
   accepted message (the branch specified in §3.1, reached only when `create()` returns non-null),
   and asserted **not** called at all for an idempotent-replay message post (the early `200` return
   before line 311) or a storage-failure post (the early `503` return) — proving the placement itself
   enforces the condition, not a separate check.
4. **Cron fallback.** With the trigger double forced to record `expedited_dispatch_unavailable`,
   assert `Dispatcher::enqueue()`'s result and stored rows are byte-for-byte identical to pre-M06.2
   behavior — the durable path is unconditionally unaffected.
5. **Dependency-hardening / compatibility fallback.** New `ExpeditedDispatchTriggerTest` cases: (a)
   `ActionScheduler` class absent → `expedited_dispatch_unavailable`, no exception escapes; (b)
   `create_runner()` overridden to throw → `expedited_dispatch_unavailable`, no exception escapes;
   (c) `create_runner()` overridden to return an object missing `maybe_dispatch` (simulating a future
   incompatible Action Scheduler version) → `expedited_dispatch_unavailable`; (d) concurrency
   pre-check forced true → `expedited_dispatch_declined_concurrency`, and the loopback call is never
   issued (asserted via a call-count spy on the overridden `create_runner()`).
6. **Sustained-chat-burst fairness.** New integration test: enqueue N interactive jobs (chat routing)
   interleaved with M ordinary jobs (e.g. a notification-rule dispatch) at increasing due timestamps,
   run the existing `WorkerRunner`/queue-processing path to completion, and assert every ordinary job
   still executes and none is starved — proving the identical-priority, FIFO-by-due-date design in
   §3.1/§4 holds under load, not merely by inspection.
7. **Topic creation → first message, no duplicate under concurrency.** Re-run the existing
   `TopicCreationDispatcherTest` / `TopicCreationHandlerTest` concurrency assertions unmodified, plus
   one new case: two overlapping `ExpeditedDispatchTrigger::trigger()` calls for the same
   conversation's first message never result in more than one `TopicCreationHandler` job existing.
8. **Retries/dead-letter unchanged.** Existing `DeadLetterLifecycleTest`,
   `DuplicateDeliverySignalTest`, `RetryPolicyTest` re-run with no expected diff.
9. **Normal event traffic stays ordinary priority.** Unit assertion that notification-rule dispatch
   and every job type other than the one named call site in §3.1 never calls
   `ExpeditedDispatchTrigger` and is enqueued at Action Scheduler's unmodified default — this plan
   introduces no priority parameter at all (§3.1), so this is a straightforward absence check.
10. **Webhook-to-widget timing / active polling.** No code change proposed (§2); add one explicit
    regression assertion in `WebhookControllerConversationRoutingTest` confirming inbound capture
    remains synchronous, in-request — documenting the "no change needed" finding as an enforced
    invariant.
11. **WooCommerce-absent mode.** Re-run `DiagnosticsReportWooCommerceTest` unmodified.
12. **No live calls.** Every test above uses the existing intercepted-HTTP fixture pattern already
    established in `SendMessageHandlerTest`/`TopicCreationHandlerTest`.

**What automated tests cannot prove, stated explicitly:** items 1–12 verify correctness, fallback
safety, and fairness with mocked/intercepted HTTP and a real but request-scoped Action Scheduler
store — they do not, and cannot, prove that a real Telegram Bot API loopback dispatch actually
delivers a message in "roughly 2–5 seconds" against the real network. That evidence is §7's WP6.

## 7. Work packages

**WP0 — Baseline re-verification and plan freeze.** Standalone, documentation-only commit. Baseline
re-verification is already complete as of this draft (§0): M06.1 merged at `3886156...`,
`UNIVERSAL_TELEGRAM_VERSION` confirmed `0.6.0`, ADR-0023 confirmed the next unused number, `db_version`
confirmed unchanged at `13`. If further time elapses between this draft and the actual freeze commit,
re-run the same `git fetch origin main` check immediately beforehand to catch any intervening merge.
Commit: this plan file plus the ADR text (§8), no code. Commit message:
`docs: freeze M06.2 interactive Telegram delivery plan and ADR-0023`.

**WP1 — `Queue\ExpeditedDispatchTrigger`.**
- Files: `src/Queue/ExpeditedDispatchTrigger.php` (new, per §3.1's guarded design), `src/Core/Plugin.php`
  (composition-root wiring, mirroring the existing `$this->dispatcher` wiring pattern).
- DB impact: none.
- Tests: new `tests/integration/Queue/ExpeditedDispatchTriggerTest.php` covering §6 items 3–5.
- Acceptance evidence: green tests; no `JobEnvelope`/`Dispatcher` signature change (fairness
  resolved by *not* introducing priority — §3.1).
- Commit message: `feat(queue): add guarded expedited dispatch trigger`.

**WP2 — Wire expedited dispatch into the conversation message REST path.**
- Files: `src/Conversations/Rest/ConversationsController.php` (one call to
  `ExpeditedDispatchTrigger::trigger()` inserted immediately after the existing `route()` call at
  line 312 and before the handler's final `200` response — the exact placement specified in §3.1,
  which is unreachable from either the idempotent-replay or storage-failure early-return branches),
  `src/Core/Plugin.php` (inject the trigger into the controller's constructor).
- DB impact: none.
- Tests: §6 items 3, 4, 6, 7, 9.
- Acceptance evidence: green tests; trace (via existing HTTP interception, no live bot) confirming a
  first visitor message causes exactly one trigger call.
- Commit message: `feat(conversations): expedite queue dispatch for visitor chat messages`.

**WP3 — Bounded synchronous admin Test Message.**
- Files: `src/Administration/Telegram/BotManagementController.php` (`send_test_message()` rewritten
  per §3.4), `src/Core/Plugin.php` (inject the short-timeout `TelegramApiClient` instance).
- DB impact: none.
- Tests: §6 items 1, 2, plus `BotManagementControllerTest` regression for the removed
  `MessageDispatcher::send()` call path.
- Acceptance evidence: green tests; confirmed no `outbound_messages` row created by this action.
- Commit message: `feat(admin): make Telegram test message a bounded synchronous send`.

**WP4 — Diagnostics signals.**
- Files: `src/Queue/QueueHealth.php` (add `oldest_pending_age_seconds()`),
  `src/Administration/Diagnostics/DiagnosticsReport.php` (surface counts of the two fixed
  abnormal-outcome audit codes and the new queue-age value).
- DB impact: none (existing `audit_log` table, no new column).
- Tests: new `tests/integration/Queue/QueueHealthTest.php`; `DiagnosticsReportTest` extension.
- Acceptance evidence: green tests; diagnostics page renders the new fields in both
  WooCommerce-present and WooCommerce-absent modes (§6 item 11).
- Commit message: `feat(diagnostics): surface expedited-dispatch and queue-age signals`.

**WP5 — Documentation and version bump (no closure record).**
- Files, all canonical version sources, none omitted: `universal-telegram.php` (both the `Version:`
  plugin-header comment on line 6 and the `define( 'UNIVERSAL_TELEGRAM_VERSION', ... )` constant on
  line 21 — the actual production version source, verified present in this same file at this
  baseline, `0.6.0 → 0.6.1` for both); `readme.txt` (`Stable tag` corrected from its currently-stale
  `0.5.0`, per §0, directly to `0.6.1` — not a one-step bump from the stale value — plus a changelog
  entry); `docs/ARCHITECTURE.md` (append the `0.6.1` sentence to the versioning-conventions
  paragraph, following the exact prose pattern used for M04.1/M05/M06); `docs/testing/` note on the
  new intercepted-HTTP test doubles.
- DB impact: none.
- **Does not draft or commit a closure record.** Per correction: a closure record is written only
  after this work has actually merged, citing the real merge SHA(s) and real CI run(s) — not
  authored speculatively alongside implementation.
- Tests: none new — this package runs the lean final gate only (§9).
- Commit message: `docs: version bump to 0.6.1 for M06.2`.

**WP6 — Manual dev-bot latency smoke check (post-merge, pre-acceptance; not a code package).**
- Performed once, after WP1–WP5 have merged to `main` and CI is green, against the existing dev bot
  already configured in this environment (per `CLAUDE.md` — no new bot, no new credential).
- Procedure: (a) trigger "Test message" from the admin screen, record observed wall-clock time to
  visible success/failure and the exact result shown; (b) submit a visitor chat message through the
  live widget on `dev.biopentra.eu`, record observed wall-clock time until it appears in the
  Telegram topic, under normal (non-degraded) conditions.
- Evidence recorded: timestamps/short screen capture or terminal log excerpt for both checks,
  attached to the actual closure record (written after this step, per WP5's correction) — not
  fabricated or estimated from mocked test timings.
- This step requires a live bot deliberately, as a human acceptance check distinct from the
  no-live-bot automated validation required everywhere else in this plan (§6 item 12).

## 8. ADR, version, database, documentation, closure

**ADR required: yes** — number `0023`, confirmed the next unused number against `origin/main` at
`388615670f3854584dc7ed76c91285f400d42249` (§0). The expedited-dispatch mechanism changes an accepted
architecture contract — a WordPress-request code path deliberately reaching past
`Queue\Dispatcher`'s "never blocks, always async" boundary to request faster execution of an
already-enqueued job, and the first caller of an Action Scheduler internal implementation class
outside Action Scheduler's own admin-only trigger. Per governance's scope-change rule, this requires
an ADR.

### ADR-0023 — Expedited Interactive Queue Dispatch and Bounded Synchronous Diagnostic Send

**Status.** Proposed (pending Master Architect review and Product Owner approval, per
`docs/governance.md`).

**Context.** ADR-0006 established that `Queue\Dispatcher::enqueue()` never blocks the originating
request and is deliberately unaware of any provider-specific urgency. ADR-0012 and ADR-0021
established that visitor-facing REST requests must never call Telegram directly or create a topic
synchronously. In practice, nothing in the shipped system causes a durably-enqueued interactive job
to run promptly: Action Scheduler's own faster-than-cron path
(`ActionScheduler_QueueRunner::maybe_dispatch_async_request()`) is unconditionally gated on
`is_admin()`, so every visitor-originated job depends on WP-Cron cadence alone, which this project's
own environments already run at a 5-minute external interval and which cannot be assumed faster on
any other installation. Separately, the administrator's "Test message" action gives no immediate
result today, unlike the adjacent, already-synchronous `test_connection()` action in the same
controller.

**Decision.**
1. A new `Queue\ExpeditedDispatchTrigger` may, after an interactive job is durably enqueued via the
   existing, unmodified `Queue\Dispatcher::enqueue()`, request an out-of-band, non-blocking
   loopback dispatch of Action Scheduler's own existing async queue runner
   (`ActionScheduler_AsyncRequest_QueueRunner::maybe_dispatch()`), bypassing only the `is_admin()`
   gate that Action Scheduler's own admin-context convenience hook applies before reaching that same
   method — not any authentication or concurrency gate, all of which remain in force. This is
   strictly an optimization: durability, retry, dead-letter, and eventual delivery via normal cron
   cadence are all unchanged and remain fully sufficient on their own if the expedited call is
   unavailable, declined, or silently fails (it is fire-and-forget by construction). Every step —
   dependency check, concurrency pre-check, runner construction, and invocation — runs inside one
   guarded block, so no exception at any point can escape uncaught; the class/method-existence guard
   and the concurrency pre-check are exposed as protected, individually overridable methods (not
   inlined), and runner construction returns a deliberately untyped value, specifically so tests can
   simulate an unavailable class, an incompatible runner instance missing the expected method, or a
   thrown construction/invocation failure — a declared return type on the construction method would
   let PHP itself reject such a test double before the trigger's own logic ever ran, defeating the
   seam. The production path still explicitly verifies `method_exists()` on the concrete constructed
   object before ever calling it.
2. **No per-job priority differentiation is introduced.** Because Action Scheduler orders claims by
   priority across every claim, not merely within one already-mixed batch, giving interactive jobs a
   non-default priority would let a sustained chat burst starve ordinary notification/event jobs
   indefinitely. All jobs keep Action Scheduler's own default priority; latency improvement comes
   solely from causing an ordinary queue run to happen sooner, never from reordering what that run
   processes.
3. Diagnostics record only what is actually observable from a non-blocking call, and only what the
   audit log's own retention model can safely absorb: request-time state is audited as one of exactly
   two fixed codes — `expedited_dispatch_declined_concurrency` / `expedited_dispatch_unavailable` —
   each bounded to genuinely abnormal conditions, computed from Action Scheduler's own public
   `has_maximum_concurrent_batches()`/`has_pending_actions_due()` methods before the call is issued.
   The routine-success case is not audited at all: `universal_telegram_audit_log` has no retention or
   cleanup mechanism of its own, and one row per ordinary visitor message would grow it unboundedly
   for a state ("a request was issued") that is not, by itself, meaningful delivery evidence. Whether
   a given message's dispatch actually helped is answered later, and only, by that job's own recorded
   delivery outcome and queue age — never inferred at request time.
4. The administrator-initiated "Test message" action is changed from an enqueued, asynchronous send
   to a bounded (≤8 second), synchronous call to the same `Telegram\Client\TelegramApiClient` and
   `Telegram\Client\TelegramFailureClassifier` the queue's own send handler uses, following the
   precedent already established by this same controller's `test_connection()` method. This is the
   one explicitly-authorized exception to the "no synchronous Telegram calls from an interactive code
   path" posture, scoped narrowly to an administrator's own explicit diagnostic action.

**Alternatives.**
- *Differentiated per-job priority, capped or coalesced to bound its impact on ordinary jobs.*
  Rejected: still requires priority differentiation plus new capping/coalescing state and its own
  test surface to prevent the same starvation risk it introduces, for no latency benefit over
  triggering alone — expediting *when* a run happens is sufficient; reordering *what* runs is not
  needed and is the more dangerous of the two.
- *A second, priority-aware queue/worker.* Rejected: would duplicate `Queue\Dispatcher`,
  `Queue\WorkerRunner`, and every ADR-0014 reliability mechanism for a problem fully solvable by
  triggering the existing queue sooner.
- *A custom internal HTTP endpoint the plugin calls to force queue execution.* Rejected: would be a
  new, unauthenticated-or-newly-authenticated internal execution surface, explicitly forbidden by
  this milestone's frozen constraints; Action Scheduler already ships exactly this mechanism,
  nonce-protected.
- *Lowering Action Scheduler's own WP-Cron interval.* Rejected: does not help on installations
  (including this project's own dev VPS) where WP-Cron itself is disabled and driven by an external
  cron outside this plugin's control; would also apply globally to every job type.
- *Removing the `is_admin()` check inside Action Scheduler itself.* Rejected: a vendor patch to a
  Composer dependency (ADR-0006) is update-hostile; calling `maybe_dispatch()` directly from our own
  code, guarded per point 1, achieves the same effect without forking anything.
- *Claiming the loopback "dispatched," "started," or even "requested" in diagnostics.* Rejected as
  dishonest or unhelpful: a fire-and-forget non-blocking call cannot prove the first two, and the
  third, while technically true, is not meaningful delivery evidence on its own — so it is not
  recorded at all, avoiding an unbounded per-message audit-log write for a state that would not
  actually inform anyone (§8 decision point 3).
- *Making the visitor REST response wait for the interactive job to actually complete.* Rejected:
  reintroduces the synchronous-Telegram-call risk ADR-0012/ADR-0021 forbid.

**Consequences.** Any later milestone introducing its own time-sensitive interactive job should
follow this same pattern — enqueue via the unmodified `Dispatcher`, then optionally call
`ExpeditedDispatchTrigger` — rather than inventing a parallel priority mechanism. If a future
milestone genuinely needs differentiated priority, it must design its own bounded fairness
mechanism (e.g., capping or coalescing) rather than reusing this ADR's identical-priority resolution
unmodified, and should record that as its own superseding or sibling decision.
`ExpeditedDispatchTrigger`'s dependency on `ActionScheduler_AsyncRequest_QueueRunner`, a documented
internal implementation class, is a deliberate, narrow, verified-at-this-version reuse, guarded
against a future incompatible signature; a major Action Scheduler upgrade that removes or renames it
degrades this plugin to unmodified cron-cadence behavior automatically (via the `unavailable` guard),
never to an error.

**Security and privacy impact.** No new credential surface, no new endpoint, no new unauthenticated
trust boundary. The bounded Test Message path is the only place this ADR permits an
interactive-context synchronous Telegram call, scoped to an administrator's own capability-gated,
explicitly-initiated action.

**Affected Documents/Milestones.** ADR-0006 (the "never blocks" dispatch contract gains a
documented, narrow, purely-additive optimization, not a modification of its own guarantees);
ADR-0012/ADR-0021 (the "no synchronous Telegram calls from visitor REST" boundary is reaffirmed,
with one narrowly-scoped administrator-only exception recorded here); M07 (operator workflow —
should follow this same expedited-dispatch pattern for any future interactive job, including this
ADR's identical-priority fairness resolution, rather than a new mechanism).

**Compatibility/Migration Impact.** None. No schema change; additive code only.

---

**Version.** Patch bump, `0.6.0 → 0.6.1` (M06.1's confirmed landing point, per §0). No new end-user
functional-capability class is introduced — the chat widget and admin bot-management screen already
exist; this is a latency and diagnostics improvement over existing capabilities, with no persistence
change, mirroring the M04.1 precedent (`docs/ARCHITECTURE.md`). WP5 corrects all three canonical
version sources, including `readme.txt`'s currently-stale `Stable tag` (§0, §7).

**Database.** No `db_version` change — confirmed unchanged at `13` (§0).

**Documentation.** `docs/ARCHITECTURE.md` versioning-conventions paragraph gets one appended
sentence for `0.6.1`; `readme.txt` changelog entry; this plan and the ADR are the frozen governance
record (WP0).

**Closure evidence recommendation — corrected.** Per `docs/governance.md`, milestone closure is a
Product Owner decision informed by the Master Architect's recommendation — the Implementation Agent
never self-certifies it. Per ADR-0011's carve-out, the formal independent-tester (Vlad) acceptance
session does not apply to milestones M00–M09, but that carve-out only removes the *separate manual
acceptance session*; it does not substitute for evidence that the actual product requirement (a
latency improvement) was observed to work. Accordingly: automated tests (§6), code review, and green
CI close the *technical* correctness and fairness work; **the latency requirement additionally
needs WP6's recorded manual dev-bot smoke check** (Test Message immediate result, visitor
chat-to-topic timing under normal conditions) before Product Owner acceptance, since mocked/
intercepted-HTTP tests can prove correctness and fallback safety but cannot prove real-world
delivery timing. The closure record itself is written only after merge, citing the actual merge
SHA(s), actual CI run(s), and WP6's actual recorded evidence — not drafted speculatively now.

**M07 status.** M07 (operator workflow) remains unstarted. Nothing in this plan touches
`ConversationRepository::assign()`, the `resolved` status transition, or any operator-facing surface.

## 9. Lean final validation gate

Run once, after WP1–WP4 are implemented (tests are written alongside each package per §7 but not
executed to completion until this single gate):

1. Changed-scope tests only: `tests/unit/Queue/`, `tests/integration/Queue/`,
   `tests/integration/Conversations/`, `tests/integration/Administration/Telegram/`,
   `tests/integration/Administration/Diagnostics/`.
2. PHPCS (project ruleset, unchanged).
3. PHPStan (project baseline, unchanged).
4. Documentation-consistency check: `UNIVERSAL_TELEGRAM_VERSION`, `readme.txt` stable tag, and the
   new `docs/ARCHITECTURE.md` sentence all agree on `0.6.1`.

GitHub Actions remains the independent full-matrix run. WP6's manual smoke check (§7) runs
afterward, post-merge, and is not part of this gate.

## 10. Self-review checklist

- M05 queue durability: preserved — `Queue\Dispatcher::enqueue()` is unmodified; `ExpeditedDispatchTrigger`
  only requests earlier execution of what is already durably stored, fully optional/fire-and-forget. ✅
- No direct visitor-to-Telegram calls: `ConversationsController` still performs zero Telegram I/O;
  the one new call is a guarded, non-blocking loopback to Action Scheduler's own runner. ✅
- Duplicate prevention: `try_begin_topic_creation()`'s compare-and-set and the idempotency-key replay
  branches (ADR-0021 amendment) are untouched and re-tested (§6 items 3, 7). ✅
- Bounded interactive latency: visitor REST response adds one non-blocking call plus two cheap public
  reads (§4); admin Test Message is bounded to ≤8s (§3.4). ✅
- Normal-queue fairness: **corrected** — no priority differentiation exists to starve anything;
  fairness follows structurally from equal priority, proven by a sustained-burst test (§6 item 6),
  not merely asserted. ✅
- Truthful diagnostics: only independently-verifiable, non-routine request-time states are recorded
  (two codes, not three); "dispatched"/"started"/routine-"requested" claims removed, the last
  specifically because the audit_log table has no retention model to absorb it (§3.1, §5, §8). ✅
- Dependency hardening: dependency check, concurrency pre-check, runner construction, and invocation
  all run inside one outer `try/catch`; class/method existence guarded via overridable methods; the
  runner-construction seam is deliberately untyped so tests can actually simulate an incompatible
  runner instance; the production path still explicitly `method_exists()`-checks the concrete
  returned object before calling it; every failure caught and audited under one fixed code; durable
  fallback always intact (§3.1, §6 item 5). ✅
- Test traceability: every work package names its exact test files (§7); the lean gate (§9) is the
  single point where they all run together; WP6 is the separate, explicit manual evidence the
  latency requirement itself needs. ✅
- Closure integrity: no closure record drafted before merge; Product Owner acceptance explicitly
  gated on WP6's real evidence, not claimed from mocks (§8). ✅
- M06.1 sequencing honored, not merely assumed: this freeze pass re-verified `origin/main` and
  confirmed M06.1 has actually merged (`3886156...`, PR #14, closure status PASS), confirmed
  `0.6.0`/`db_version 13`/ADR-0023's number directly from that post-merge state rather than
  projecting them, and confirmed every file/class/line cited in this plan by re-reading
  `git show origin/main:<path>` against that current SHA — the M06.1 branch itself was never
  inspected, diffed, or merged from; only its already-merged, closed effect on `main` was read. ✅
