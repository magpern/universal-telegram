# ADR-0023 — Expedited Interactive Queue Dispatch and Bounded Synchronous Diagnostic Send

## Status

Accepted (M06.2)

## Context

ADR-0006 established that `Queue\Dispatcher::enqueue()` never blocks the originating request and is
deliberately unaware of any provider-specific urgency. ADR-0012 and ADR-0021 established that
visitor-facing REST requests must never call Telegram directly or create a topic synchronously. In
practice, nothing in the shipped system causes a durably-enqueued interactive job to run promptly:
Action Scheduler's own faster-than-cron path
(`ActionScheduler_QueueRunner::maybe_dispatch_async_request()`) is unconditionally gated on
`is_admin()`, so every visitor-originated job depends on WP-Cron cadence alone, which this project's
own environments already run at a 5-minute external interval and which cannot be assumed faster on
any other installation. Separately, the administrator's "Test message" action gives no immediate
result today, unlike the adjacent, already-synchronous `test_connection()` action in the same
controller.

## Decision

1. A new `Queue\ExpeditedDispatchTrigger` may, after an interactive job is durably enqueued via the
   existing, unmodified `Queue\Dispatcher::enqueue()`, request an out-of-band, non-blocking loopback
   dispatch of Action Scheduler's own existing async queue runner
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

## Alternatives

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
  actually inform anyone (decision point 3 above).
- *Making the visitor REST response wait for the interactive job to actually complete.* Rejected:
  reintroduces the synchronous-Telegram-call risk ADR-0012/ADR-0021 forbid.

## Consequences

Any later milestone introducing its own time-sensitive interactive job should follow this same
pattern — enqueue via the unmodified `Dispatcher`, then optionally call `ExpeditedDispatchTrigger` —
rather than inventing a parallel priority mechanism. If a future milestone genuinely needs
differentiated priority, it must design its own bounded fairness mechanism (e.g., capping or
coalescing) rather than reusing this ADR's identical-priority resolution unmodified, and should
record that as its own superseding or sibling decision. `ExpeditedDispatchTrigger`'s dependency on
`ActionScheduler_AsyncRequest_QueueRunner`, a documented internal implementation class, is a
deliberate, narrow, verified-at-this-version reuse, guarded against a future incompatible signature;
a major Action Scheduler upgrade that removes or renames it degrades this plugin to unmodified
cron-cadence behavior automatically (via the `unavailable` guard), never to an error.

## Security and privacy impact

No new credential surface, no new endpoint, no new unauthenticated trust boundary. The bounded Test
Message path is the only place this ADR permits an interactive-context synchronous Telegram call,
scoped to an administrator's own capability-gated, explicitly-initiated action.

## Affected Documents/Milestones

ADR-0006 (the "never blocks" dispatch contract gains a documented, narrow, purely-additive
optimization, not a modification of its own guarantees); ADR-0012/ADR-0021 (the "no synchronous
Telegram calls from visitor REST" boundary is reaffirmed, with one narrowly-scoped administrator-only
exception recorded here); M07 (operator workflow — should follow this same expedited-dispatch
pattern for any future interactive job, including this ADR's identical-priority fairness resolution,
rather than a new mechanism).

## Compatibility/Migration Impact

None. No schema change; additive code only.

## Amendment (M06.2 corrective plan v2): Claim-Protected Immediate Delivery Replaces Expedited Dispatch as the Primary Mechanism

**Status:** Accepted (M06.2 corrective v2).

**Context.** Live testing on `dev.biopentra.eu` — a real, busy, multi-plugin WordPress install sharing
one Action Scheduler queue — showed that `ExpeditedDispatchTrigger` alone does not deliver the latency
this ADR originally decided on: a real chat message sat 33 seconds before its outbound send even began,
because both the expedited loopback and ordinary cron cadence depend on the same contended, shared
Action Scheduler batch slot. Separately, this ADR's original design left a latent double-send exposure:
neither `OutboundMessageRepository::mark_sending()` (an unconditional `UPDATE`) nor
`ConversationRepository::try_begin_topic_creation()`'s `none → pending` compare-and-set (no expiry)
prevents two callers from both completing a send/topic-creation for the same row if one crashes
mid-flight. A widget bug also caused `ensureStarted()` to mint a fresh idempotency key/secret pair on
every retry instead of reusing `utChatPendingStart`'s own pair, so retries never reached the server's
idempotent-replay branch and instead exhausted the per-IP daily start-rate-limit bucket, surfacing as an
undifferentiated `{"ok":false}`. The administrator's bounded synchronous "Test message" send (Decision
point 4 above) was confirmed fast and correct in the same testing and is unaffected by this amendment.

**Decision — primary mechanism replaced.** Sections 1–3 of this ADR's original Decision are superseded
for the *primary* interactive-latency mechanism:

1. Primary interactive latency now comes from a **bounded, in-process, claim-protected synchronous
   delivery attempt**, not from triggering Action Scheduler's own async loopback. A persisted,
   atomic, leased claim (`outbound_messages.claim_expires_at`, `conversations.topic_claim_expires_at`,
   both `DATETIME NULL`, 15-second lease) ensures at most one caller — the immediate in-process
   attempt, the durable queue worker, or a later reclaim after crash/expiry — is ever active on a given
   row at once. `SendMessageHandler`/`TopicCreationHandler` each expose a non-throwing
   `try_once(...): AttemptOutcome`, shared unmodified between the immediate attempt and the durable
   queue worker; `handle_job()` becomes a thin wrapper around it. A queue worker that observes another
   claimant's unexpired lease does not silently drop its one-shot job: it self-reschedules to check back
   just after that lease's observed expiry, so a claimant crash is reclaimed automatically without
   busy-polling or premature dead-lettering.
2. **The delivery guarantee this ADR provides is at-least-once, not exactly-once.** The claim prevents
   duplicate sends on every normal path (concurrent claim contention, ordinary retries, crash before the
   Telegram call, crash after the terminal write). A duplicate remains possible only in the one
   unavoidable window a lease can never close: a claimant crashing *after* Telegram has accepted the
   send/topic-creation request but *before* the local terminal-state write commits. This window is rare,
   accepted, and not otherwise detectable — no column or marker records it, and the crash that causes it
   is exactly the kind of event that would prevent recording one. This corrects, and replaces, any prior
   "exactly-once" framing.
3. A **second, bounded fallback attempt layer** runs synchronously, still inside the same REST callback
   invocation, before the response object is returned to WordPress — identically on every supported PHP
   host, independent of Action Scheduler's shared batch slot and independent of any SAPI-specific
   early-response mechanism (`fastcgi_finish_request()` is explicitly not used inside the REST callback:
   a `WP_REST_Server` callback returns a response object that WordPress itself serializes and emits
   afterward, so nothing can reliably flush the JSON body early from inside it without risking corrupting
   or duplicating that output). Every Telegram API call's own timeout is capped by its attempt's
   remaining wall-clock budget (`min(3.0, remaining_budget - 0.2s)`), not a fixed value, so a first
   message's two-call sequence (topic creation, then send) cannot exceed its stated budget. The normal
   case still returns in well under a second; the rare case where the primary bounded attempt (4s budget)
   does not complete may take up to ~9 seconds total (4s primary + 5s fallback) before the visitor gets a
   response — an accepted, bounded, honestly-stated cost, never hidden behind an early-flush trick.
4. `Queue\ExpeditedDispatchTrigger` (Decision point 1 above) is **retained, unchanged**, but demoted:
   called only from the final-fallback branch after both bounded layers above complete, never as the
   primary mechanism. Action Scheduler's own queue plus the 5-minute external cron remain the durable
   recovery layer — never the sole interactive fallback, which is the defect this amendment corrects.
5. Start-idempotency replay (`ConversationsController::handle_start()`) is now checked, and its secret
   verified via `password_verify()`, **before** any new-conversation rate-limit token is consumed. The
   per-IP new-start limit is split into an hourly bucket (`conv_start_ip_hr`, 10/hour) and a renamed
   daily bucket (`conv_start_ip_day`, 50/day, was `conv_start_ip`); a new, independent per-IP
   auth/secret-failure bucket (`conv_auth_fail_ip`, 20/hour) is consumed only on a genuine secret
   mismatch against a valid idempotency-key replay, never on ordinary success. The site-wide
   `conv_start_site` cap and the existing `conv_post`/`conv_post_site` message-sending caps are
   unchanged.
6. A new, optional `reason` field (`rate_limited` | `conversation_expired` | `request_failed` |
   `temporary_delivery_pending`) is added to existing response shapes, response-only, no schema impact.
   ADR-0021's uniform-404 guarantee is preserved exactly: `conversation_expired` is attached uniformly to
   every `controlled_not_found()` 404 regardless of underlying cause, so the body remains byte-for-byte
   identical across every failure cause.

**Alternatives considered (in addition to this ADR's original list).** *Raising Action Scheduler's
global concurrent-batch filter* — rejected: affects every other plugin on the shared site and still does
not guarantee winning against unrelated jobs. *Retrying the expedited loopback itself* — rejected: still
depends on the same contended shared resource that caused the original 33-second delay. *Unbounded
synchronous blocking* — rejected: reintroduces exactly the risk ADR-0012/ADR-0021 exist to prevent. *A
single re-read-then-write guard instead of a leased claim* — rejected: does not survive a mid-flight
crash between the external Telegram call and the local status write, which a lease-based claim does.
*An early-response/detached-worker trick using `fastcgi_finish_request()` inside the REST callback* —
rejected: incompatible with the WP REST response lifecycle described in point 3 above.

**Security/privacy impact.** Unchanged token/secret handling. The new per-IP rate-limit granularities
reuse the existing per-install HMAC secret and hashing pattern already accepted for
`Events\Visitor\IngestController` — no new raw-IP persistence.

**Compatibility/migration impact.** One additive `Migrator` step, two nullable columns
(`outbound_messages.claim_expires_at`, `conversations.topic_claim_expires_at`), `db_version` `13 → 14`.
Per this project's own stated independence of `db_version` from the plugin's SemVer string, this remains
a patch-level correctness/reliability fix (`0.6.1 → 0.6.2`) to an already-shipped capability, not a new
one.

**Affected documents/milestones.** `docs/plans/m06-2-interactive-telegram-delivery-plan-v2.md` (this
amendment's originating plan); `docs/closure/m06-2-interactive-telegram-delivery-closure.md` (gains a
corrective addendum recording this amendment's freeze/PR/merge/closure evidence, following the
M06.1 closure record's own addendum precedent); M07 (any future interactive job should follow this
amended claim-protected pattern, not the original expedited-dispatch-only pattern this supersedes).
