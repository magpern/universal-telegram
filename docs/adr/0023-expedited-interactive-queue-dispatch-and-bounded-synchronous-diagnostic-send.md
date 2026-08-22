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
