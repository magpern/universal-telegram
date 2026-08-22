# M06.2 — Interactive Telegram Delivery — Corrective Implementation Plan v2 (ADR-0023 Amendment)

## 0. Context

M06.2 v1 (frozen `961987e`, merged `3f2f50e`, ADR-0023) shipped `Queue\ExpeditedDispatchTrigger` — a
fire-and-forget nudge to Action Scheduler's own async runner — as the *primary* mechanism for
interactive chat latency. Live testing on `dev.biopentra.eu` (a real, busy, multi-plugin WordPress
install sharing one Action Scheduler queue) showed this does not hold up: a real chat message sat
33 seconds before its outbound send even began, and a widget bug independently exhausted an
overly-strict per-IP daily start-rate-limit bucket, surfacing as an undifferentiated "Something went
wrong." Admin Test Message (a bounded synchronous send, unrelated mechanism) was confirmed fast and
correct and is not in question.

This corrective pass replaces the primary latency mechanism, closes a real double-send exposure the
first design left open, defines a genuine second-layer fallback that does not depend on Action
Scheduler's shared batch slot, fixes the widget/rate-limit interaction, and specifies exact acceptance
evidence via the real browser widget.

## 1. Baseline

`main` @ `2e09db9`. ADR-0023 (v1) and `docs/plans/m06-2-interactive-telegram-delivery-plan-v1.md` are
already frozen/merged. This plan supersedes v1 per `docs/governance.md`'s Freeze model (v1 stays
untouched; this becomes `docs/plans/m06-2-interactive-telegram-delivery-plan-v2.md` at freeze time)
and amends ADR-0023 in place (same style as ADR-0021's own "Amendment" section) rather than a new ADR
number, since it corrects that ADR's own central decision.

## 2. Confirmed root causes (recap)

1. **Latency**: `ExpeditedDispatchTrigger`'s fire-and-forget, non-retried, single-shared-batch-slot
   dependency is not reliable on a busy, multi-plugin Action Scheduler install (verified: 33s gap
   between message persistence and outbound send start, on `main` at the correct commit; Cloudflare/
   WAF loopback blocking directly ruled out by same-host test).
2. **Double-send exposure already latent in v1's own design** (not yet triggered, but real): nothing
   prevents two callers from both completing a Telegram send/topic-creation for the same row —
   `OutboundMessageRepository::mark_sending()` performs an unconditional `UPDATE`, not a
   conditional one, and `ConversationRepository::try_begin_topic_creation()`'s existing
   `none → pending` compare-and-set has no expiry, so a caller that wins it and then crashes
   mid-flight leaves the state unrecoverable except by the original job's own bounded retry count.
3. **Widget retry bug**: `ensureStarted()` mints a **fresh** idempotency key/secret pair on every
   retry rather than reusing `utChatPendingStart`'s own pair, so retries never hit the server's
   idempotent-replay branch and each one consumes a fresh `START_CLIENT_SCOPE` token (previously
   5/day). Compounded by `handle_start()` checking rate limits *before* the idempotency-replay lookup.
4. **No machine-readable failure signal**: every response is `{"ok": bool}` only — the widget cannot
   distinguish rate-limiting, expiry, or a transient failure, so all surface as one generic error.

## 3. Corrective architecture decisions

### 3.1 Atomic claim/lease protocol (replaces "re-read status then send")

Both outbound sends and topic creation gain a real, atomic, lease-based claim — not a plain read-then-
act check — so exactly one caller (the in-process immediate attempt, the durable queue worker, or a
later reclaim) may hold an **active claim** for a given row at any moment.

**Honest delivery guarantee.** A persisted lease prevents two *concurrent* claimants from both being
active on the same row at once — that is what it actually provides. It does **not** provide exactly-once
delivery to Telegram in the one unavoidable window a lease can never close: a claimant crashing *after*
Telegram has accepted the send/topic-creation request but *before* the local terminal-state write
(`SENT`/`created`) commits. In that specific window, lease expiry will let a later claimant reclaim and
resend, because the local record still shows non-terminal. **The guarantee this plan actually delivers
is at-least-once delivery**: duplicate sends are prevented on every normal path (concurrent claim
contention, ordinary retries, crash *before* the Telegram call, crash *after* the terminal write), and
possible only in that one narrow post-call/pre-commit crash window, which is rare and not otherwise
detectable — no column or marker records it, and the crash that causes it is exactly the kind of event
that prevents recording one. This replaces any prior "exactly-once" framing throughout this plan and its
acceptance criteria (§7, §9); no new "possible duplicate" flag or field is part of this plan.

**Schema change** (both nullable, additive, no backfill required): `outbound_messages.claim_expires_at
DATETIME NULL`; `conversations.topic_claim_expires_at DATETIME NULL`. One new `Migrator` step,
`db_version` `13 → 14`. This corrects v1's "no schema change" assumption — necessary because true
mutual exclusion requires a persisted lease, not just a status re-read.

**Outbound-message claim** — new `OutboundMessageRepository::try_claim_for_sending(int $id): bool`,
raw-SQL atomic `UPDATE` (mirroring the existing raw-SQL CAS idiom already used for idempotency
lookups elsewhere in this boundary):
```sql
UPDATE outbound_messages
SET status = 'sending', claim_expires_at = %lease_expiry%, attempt_count = attempt_count + 1, updated_at = %now%
WHERE id = %id%
  AND ( status IN ('pending', 'retry_scheduled')
        OR ( status = 'sending' AND claim_expires_at IS NOT NULL AND claim_expires_at < %now% ) )
```
`rows_affected > 0` ⇒ this caller owns the claim and — and only then — may call
`TelegramApiClient::send_message()`. **Lease duration: 15 seconds** (comfortably longer than the
bounded attempt's own 4s budget plus overhead; short enough that a crash recovers quickly). **Terminal
states** (`SENT`, `DEAD_LETTER`) are never matched by this `WHERE` clause — once reached, no claim can
ever be re-acquired, which is the actual double-send prevention. **Graceful give-up**: if the claim
owner decides to stop (budget exceeded, deferrable failure) *without* crashing, it explicitly releases
the claim by reverting `status` to `pending`/`retry_scheduled` and clearing `claim_expires_at`
immediately — it does not wait out the 15s lease, so the next attempt (in-process fallback or queue)
can reclaim right away. **Crash recovery**: if the owning process dies outright (fatal error, OOM,
infra-level kill) with no chance to release, the lease simply expires after 15s and the next claimant's
`UPDATE` succeeds via the `claim_expires_at < NOW()` branch. **Queue-worker behavior when it does not
own the claim**: `SendMessageHandler::try_once()` calls `try_claim_for_sending()` as its first step;
on failure (already terminal, or currently claimed with an unexpired lease by someone else) it returns
a new `AttemptOutcome::ALREADY_CLAIMED`. `handle_job()` must **not** treat this as job completion: a
one-shot Action Scheduler action that simply returns is gone for good, and if the other claimant then
crashes before reaching a terminal state, nothing would ever reclaim the row. Instead, on
`ALREADY_CLAIMED`, `handle_job()` reads the other claimant's `claim_expires_at` and self-reschedules
(same `as_schedule_single_action` idiom used elsewhere in this codebase) for `claim_expires_at + 1s`
— i.e. it checks back exactly once, just after the lease it observed should have expired, rather than
busy-polling. No throw, no retry-count consumption, no audit noise (an active, valid claim elsewhere is
not a failure) — but the job is never silently dropped. On waking, it re-attempts `try_claim_for_sending()`:
if the row is by then terminal, it's a clean no-op; if the lease was renewed/extended by an in-progress
legitimate retry, it reschedules once more against the new expiry; otherwise it wins the claim and sends.
**New regression tests** (§6): (a) unexpired lease held by another claimant → `handle_job()` reschedules
near that lease's expiry and does not increment `attempt_count`; (b) after the rescheduled delay, with
the other claimant's lease actually expired and never completed, this worker successfully reclaims and
sends; (c) a worker that repeatedly observes an active claim (renewed by a legitimately slow but
still-alive owner) reschedules again each time without runaway retries or premature dead-lettering.

**Topic-creation claim** — extends the existing `try_begin_topic_creation()` CAS (currently `none →
pending` only) to also allow reclaim of an expired lease: `WHERE topic_creation_state = 'none' OR
(topic_creation_state = 'pending' AND topic_claim_expires_at < %now%)`, setting
`topic_claim_expires_at = %now% + 15s` on success. **Only the winning caller may call
`createForumTopic`.** A non-winning caller (state is `pending` with an unexpired lease) must not call
Telegram at all — for the durable queue's own `TopicCreationHandler::handle_job()`, this means: if
`topic_creation_state` is `pending` and the lease has not expired and this call didn't win it, self-
reschedule a short delay later (reusing the existing `ConversationOutboundHandler`-style
`as_schedule_single_action` reschedule idiom) rather than attempting a second, competing `createForumTopic`
call. Terminal states `created`/`failed` are, as today, never re-enterable.

### 3.2 Bounded immediate-delivery attempt (in-process, primary mechanism)

Unchanged in shape from the prior draft, now built on the claim protocol above:

- `SendMessageHandler`/`TopicCreationHandler` each gain a non-throwing `try_once(...): AttemptOutcome`
  extracted from their existing `handle_job()` core (circuit breaker, rate limiter, decrypt, the single
  `TelegramApiClient` call, status transitions) — `handle_job()` becomes a thin wrapper: claim, attempt,
  translate `AttemptOutcome` into existing queue-side behavior (attempt-count exhaustion, reschedule,
  dead-letter, throw for `WorkerRunner`) unchanged.
- New orchestrator `Conversations\ImmediateDeliveryAttempt`: a dedicated `TelegramApiClient` instance
  (mirroring the existing `test_message_client` pattern) with a **4-second total wall-clock budget**
  for the whole attempt, tracked via `microtime(true)`. **Every individual Telegram API call's own
  timeout is capped by the remaining budget, never a fixed value**: `call_timeout = min(3.0,
  remaining_budget - overhead_margin)`, `overhead_margin = 0.2s`. This is the fix for the case that
  previously broke the budget — a first message needs *two* calls (topic creation, then send) and two
  independent fixed 3-second timeouts could total 6 seconds against a 4-second budget. With
  budget-aware timeouts: call 1 gets `min(3.0, 3.8)=3.0s`; if it takes the full 3.0s, call 2 gets
  `min(3.0, 4.0-3.0-0.2)=0.8s` — the attempt as a whole can never exceed its stated 4-second budget.
  Called from `ConversationsController::handle_post_message()` immediately after the existing
  `topic_creation->maybe_create()` / `outbound->route()` enqueue calls (both untouched — durable path
  unconditionally unaffected):
  1. If no topic yet: attempt `TopicCreationHandler::try_once()` once, within the remaining budget;
     anything other than immediate success stops here (cannot send without a topic) — falls through to
     §3.3.
  2. If a topic exists (already did, or was just created): attempt `ConversationOutboundHandler::send()`
     (decrypt + create the outbound row, unchanged) then immediately `SendMessageHandler::try_once()`
     on that row, within whatever budget remains after step 1.
  3. Returns `DELIVERED` or `PENDING`. The message row and both durable queue jobs exist *before* this
     ever runs; a `PENDING` outcome touches nothing durable — the message is never lost.

### 3.3 Bounded prompt-delivery fallback (does not depend on Action Scheduler's shared batch slot)

Resolves the gap the first draft left open: relying only on `ExpeditedDispatchTrigger` + cron after a
`PENDING` outcome could still reproduce the original 30s–5min delay, because both still depend on
winning the *same* contended, shared batch slot.

- **Mechanism (identical on every supported host)**: on a `PENDING` outcome from §3.2, the REST
  callback — still inside the same `handle_post_message()` invocation, before returning its response
  object to WordPress — runs a **second, in-process attempt layer**: up to 2 further bounded
  `try_once()` calls, spaced 1 second apart, within a further **5-second ceiling**, using the same
  budget-aware per-call timeout rule as §3.2 (`min(3.0, remaining_fallback_budget - 0.2s)`). This layer
  never touches Action Scheduler at all, so it cannot be blocked by another plugin's queued job — that
  is what actually satisfies "does not depend on Action Scheduler's shared batch slot." There is no
  early-response/detached-worker trick: a `WP_REST_Server` callback returns a response object that
  WordPress itself serializes and emits afterward, so nothing can reliably flush the JSON body early
  and keep running past it without risking corrupting or duplicating that output. The plan does not
  attempt this. **Consequence, stated plainly**: the normal case (primary attempt succeeds, §3.2)
  returns to the visitor in well under a second as today; the rare `PENDING`-after-primary-attempt case
  may take up to **~9 seconds** (4s primary + 5s fallback) before the HTTP response is sent, on every
  supported host, with no difference between PHP-FPM and any other SAPI. This is still a bounded,
  prompt, in-process delivery attempt — never a silent drop to the 5-minute cron — just one the visitor
  waits for directly in that rare case rather than one accelerated by an early response.
- **Claim/budget reconciliation**: the claim acquired in §3.1 must remain valid for the full combined
  hold time of primary + fallback. Worst case ≈ 4s (primary) + 5s (fallback) + scheduling overhead
  ≈ 9–10s, comfortably inside the **15-second lease** with margin for a crash-recovery window — the
  15s figure in §3.1 is chosen specifically to dominate this combined bound, not just the primary
  attempt alone.
- **Final fallback, unconditional**: if this second layer also doesn't complete, the already-enqueued
  durable jobs remain exactly as-is; `ExpeditedDispatchTrigger` (unchanged, demoted — see §3.5) is
  called once more here as a best-effort nudge, and Action Scheduler's own queue plus the 5-minute
  external cron remain the **durable recovery** layer — never the *sole* interactive fallback, per the
  correction.
- **Truthful state if Telegram itself is unavailable**: the poll response (`handle_poll`) is extended
  to include each message's own `delivery_state` (`stored`/`sent`/`failed` — the field already exists
  on `ConversationMessage`, simply not yet serialized into the poll body) so the widget reflects real
  eventual outcome — pending, then either sent or failed — never a false "sent" and never silently
  stuck with no signal at all.

### 3.4 `ExpeditedDispatchTrigger`'s role — demoted, not removed

Unchanged in implementation; called only from within §3.3's final-fallback branch, never as the
primary mechanism, per "do not rely on Action Scheduler's shared batch slot for interactive latency."

### 3.5 Start-idempotency ordering, exact

`ConversationsController::handle_start()` reordered:

1. Validate body/`chat_profile`/secret-format/idempotency-key-presence (400s, unchanged).
2. **New first step, before any rate-limit check**: look up `find_by_start_idempotency_key(key)`.
   - Found, secret matches (`password_verify()`): return the **original** conversation's
     `{ok:true, conversation_uuid, secret}` — **no rate-limit token of any kind is consumed** for a
     valid replay.
   - Found, secret does **not** match: consume one token from the new `conv_auth_fail_ip` bucket
     (§3.6) and return the existing generic `400` — never distinguishing this from any other malformed
     start request.
   - Not found: continue to step 3 (this is a genuinely new start attempt).
3. `START_SITE_SCOPE` then `START_CLIENT_HOUR`/`START_CLIENT_DAY` checks (§3.6) — only reached for a
   genuinely new conversation, never for a replay.
4. Bot resolution, create, as today.

**Browser-side pending-key retention, exact.** `ensureStarted()`:
- **Retains** `utChatPendingStart` (same idempotency key **and** the same client-generated secret,
  reused verbatim on the next attempt) for: any network error/fetch rejection, any `429`, `500`,
  `503`, or timeout — i.e., anything that is not a definitive, final outcome for that specific attempt.
- **Clears** `utChatPendingStart` only on: (a) a `200` success — moves into `utChatConversation` as
  today; or (b) a definitive client-side-malformed `400` that retrying the identical pair could never
  fix (bad JSON, invalid secret format) — a fresh pair is minted only on the visitor's *next*, separate
  attempt, never automatically.
- **Never** mints a new key/secret pair while a not-yet-terminally-resolved pending-start exists.

### 3.6 New tiered rate limits

| Concern | Scope(s) | Capacity / refill |
|---|---|---|
| Message sending, per conversation / site | `conv_post` / `conv_post_site` (existing, unchanged) | 20/(20 per 60s) / 300/(5 per s) |
| New-conversation, per IP, hourly | `conv_start_ip_hr` (new) | 10 / hour |
| New-conversation, per IP, daily | `conv_start_ip_day` (renamed from `conv_start_ip`) | 50 / day |
| New-conversation, site-wide | `conv_start_site` (existing, unchanged) | 120/(2 per s) |
| Auth/secret failures, per IP | `conv_auth_fail_ip` (new) | 20 / hour |

`client_scope_id()` generalized to accept granularity (`'hour'`/`'day'`), hashing
`$ip . "\x1f" . gmdate('Y-m-d-H'|'Y-m-d')` through the existing per-install HMAC secret — same pattern
already accepted for `Events\Visitor\IngestController`, self-resetting, no new cleanup code. All new
buckets are additive to, never a replacement of, the existing non-bypassable site-wide caps.

### 3.7 Machine-readable `reason` field

Additive, optional `reason` string on existing response shapes — exactly four fixed values, never
free text:

- `rate_limited` — every `429` (any bucket), uniform value regardless of which bucket tripped.
- `conversation_expired` — attached **uniformly, identically, with no branching on cause**, to every
  `controlled_not_found()` `404` — verified not to weaken ADR-0021's non-enumeration guarantee, since
  the body remains byte-for-byte identical across every failure cause, just with one previously-empty
  field now carrying a constant label.
- `request_failed` — genuine `400`/`503` validation/storage failures.
- `temporary_delivery_pending` — attached to a `200`, alongside `delivery: "pending"`, per §3.2/§3.3.
  A fully synchronous success carries neither field.

No schema change for this field (response-only); the claim/lease columns (§3.1) are the plan's only
schema change.

## 4. ADR-0023 Amendment

**Status**: Proposed, amending the Accepted ADR-0023 in place (ADR-0021's own amendment style), not a
new ADR number.

**Amended decision** (supersedes ADR-0023 §1–§3):
1. Primary interactive latency comes from a **bounded, in-process, claim-protected synchronous
   attempt** (§3.1–§3.2), never from Action Scheduler's own async loopback.
2. **At-least-once delivery, not exactly-once**: `try_once()` is one implementation shared by the
   immediate attempt and the durable queue worker; a persisted, atomic, leased claim (§3.1) prevents
   two claimants being concurrently active on the same row, with defined crash-recovery via lease
   expiry and a defined reschedule-at-expiry behavior for a non-owning queue worker (§3.1). Duplicate
   delivery is prevented on every normal path; a duplicate remains possible only if a claimant crashes
   after Telegram accepts the call but before the local terminal-state write commits — a known, rare,
   accepted edge case, not a guarantee this design claims to close.
3. A **second, bounded fallback attempt layer** (§3.3) runs synchronously, before the REST response is
   returned, on every PHP host identically — independent of Action Scheduler and of any SAPI-specific
   early-response mechanism, which this plan does not use inside a REST callback. Action Scheduler
   plus the external cron remain durable recovery, never the sole interactive fallback. The rare
   `PENDING`-after-primary case may take up to ~9 seconds before the visitor gets a response; this is
   an accepted, bounded, stated cost, not hidden behind an early-flush trick.
4. `ExpeditedDispatchTrigger` is retained, unchanged, demoted to the final-fallback branch only.
5. Start-idempotency replay is checked, and its secret verified, **before** any new-conversation
   rate-limit token is consumed (§3.5); the per-IP start limit is split into hourly (10) and daily
   (50) buckets; a new per-IP auth/secret-failure bucket (20/hour) exists independently.
6. A new, optional `reason` field (§3.7) is added; ADR-0021's uniform-404 guarantee is preserved
   exactly (byte-for-byte identical body across every 404 cause).

**Alternatives** (unchanged from the prior draft's reasoning, extended): *raising Action Scheduler's
global concurrent-batch filter* — rejected, affects every other plugin on a shared site and still
doesn't guarantee winning against unrelated jobs. *Retrying the expedite loopback itself* — rejected,
still depends on the same contended shared resource. *Unbounded synchronous blocking* — rejected,
reintroduces exactly the risk ADR-0012/ADR-0021 exist to prevent. *A single re-read-then-write guard
instead of a leased claim* — rejected per this amendment's own finding: it does not survive a mid-flight
crash between the external Telegram call and the local status write, which a lease-based claim does.

**Security/privacy impact**: unchanged token/secret handling; new IP-hash granularities reuse the
existing per-install HMAC secret and pattern — no new raw-IP persistence.

**Compatibility/migration impact**: one additive migration step, two nullable columns, `db_version`
`13 → 14`. Per `docs/ARCHITECTURE.md`'s own stated independence of `db_version` from the plugin's
SemVer string, this does not by itself require a minor version bump — this remains a patch-level
correctness/reliability fix to an already-shipped capability, not a new one.

## 5. Work packages

- **WP0 — Freeze.** Standalone, documentation-only commit: this plan (`...v2.md`) + the ADR-0023
  amendment text, no code. Must exist before any implementation commit.
- **WP1 — Claim/lease schema + repository methods.** Migration step (`db_version` 14);
  `OutboundMessageRepository::try_claim_for_sending()`; `ConversationRepository::try_begin_topic_creation()`
  extended for lease-reclaim; new `AttemptOutcome` enum.
- **WP2 — `try_once()` extraction.** `SendMessageHandler`/`TopicCreationHandler` refactored; `handle_job()`
  becomes a thin wrapper; full regression of every existing test in both classes.
- **WP3 — `Conversations\ImmediateDeliveryAttempt`.** Wired into `ConversationsController` in place of
  the direct `expedited_dispatch->trigger()` call.
- **WP4 — Bounded prompt fallback.** Host-independent second-layer retry (§3.3), run synchronously
  before the REST response is returned, identically on every host; poll response extended with
  per-message `delivery_state`.
- **WP5 — Response contract.** `reason`/`delivery` fields across all handlers.
- **WP6 — Rate-limit tiers + start-idempotency reordering.** `client_scope_id()` generalized; new
  buckets wired; `handle_start()` reordered per §3.5.
- **WP7 — Widget fix.** Pending-key retention exact per §3.5; `429`/`pending` distinct UI states.
- **WP8 — Documentation/version.** Version bump `0.6.1 → 0.6.2` (patch — see §4's `db_version`
  independence note); ADR/plan cross-references finalized.

## 6. Test plan (additive; ADR-0023 v1's existing suite must stay green)

- Claim/lease: concurrent-claim race test (two callers attempt `try_claim_for_sending()`/topic-CAS on
  the same row — exactly one wins); crash-recovery test (a claim left with an expired lease is
  reclaimable; one still within lease is not); terminal-state test (a `SENT`/`DEAD_LETTER`/`created`/
  `failed` row can never be re-claimed).
- `try_once()`: every `AttemptOutcome` case, independent of `handle_job()`; `handle_job()` regression
  unchanged for every existing case.
- `ImmediateDeliveryAttempt`: budget-exceeded → `PENDING`; first-message and subsequent-message happy
  paths; any exception caught, never propagated.
- Prompt fallback: the synchronous, host-independent fallback path exercised directly (no early-response
  mechanism involved anywhere in the test); verify no duplicate send when the second-layer retry and a
  (test-simulated) concurrent queue-worker run both fire — asserts the claim protocol, not just timing;
  budget-aware per-call timeout math covered with a fixed-clock test (first-message two-call sequence
  never exceeds its budget).
- Rate limits: hourly/daily buckets trip independently; auth-failure bucket trips only on genuine
  secret mismatches, never on ordinary success; start-idempotency replay consumes zero rate-limit
  tokens; a replay with a wrong secret consumes exactly one `conv_auth_fail_ip` token.
- `reason` field: correct for all four cases; `404` body asserted byte-for-byte identical across every
  distinct failure cause.
- JS: pending-key reuse across simulated network/5xx/429 failures; `429` and `pending` map to distinct,
  non-`transient-failure` states.
- No live Telegram calls in any automated test; existing HTTP-interception pattern reused throughout.

## 7. Mandatory post-merge acceptance evidence (real browser widget only)

No WP-CLI, reflection, or direct-REST substitute for this step — it specifically validates what an
actual visitor experiences, which the earlier in-process/`curl` verification demonstrably failed to
catch.

1. **First message in a brand-new conversation** (fresh browser session, cleared/incognito so a real
   `start` + topic creation occurs): Telegram receipt within **5 seconds**.
2. **At least five subsequent messages** in that same conversation, sent from the widget: each
   received in Telegram within **2 seconds**.
3. Both performed **while the site's normal, ambient Action Scheduler workload is present** — no
   artificial quieting of the queue; this is naturally satisfied by testing on the live dev site as-is.
4. If a genuine transient Telegram failure occurs during the session: verify the widget shows the
   truthful pending state (never a false "sent"), and — via a read-only post-hoc DB check (row counts
   in `conversation_messages`/`outbound_messages` compared to the number of distinct sends actually
   performed) — confirm **no message was lost, and no duplicate occurred in this exercised normal
   path**. Per §3.1's honest guarantee, this step verifies at-least-once delivery with no duplicates
   on the paths actually exercised here (concurrent-claim contention, ordinary retry, pre-call crash) —
   it is not, and cannot be, proof of an unconditional no-duplicate guarantee across the one
   unavoidable post-call/pre-commit crash window, which is not something a live acceptance pass can
   induce or observe. A *deliberately induced* Telegram outage is tested separately, with HTTP
   interception, under §6 — not as part of this live acceptance step.

Only after this evidence is recorded does Product Owner acceptance become possible; automated green
CI and the lean local gate close the technical work but do not, on their own, close this milestone.

## 8. Version/DB impact

Patch bump `0.6.1 → 0.6.2` (per §4's reasoning). `db_version` `13 → 14` (claim/lease columns only,
independent of the SemVer bump per this project's own stated convention).

## 9. Self-review checklist (pre-freeze)

- [ ] At most one *active* claimant exists per row at any moment, enforced by a persisted, atomic,
      leased claim, not a re-read of status; delivery is honestly at-least-once, with duplicates
      possible only in the narrow post-call/pre-commit crash window (§3.1), never claimed as
      exactly-once.
- [ ] Crash mid-flight recovers automatically within the lease duration (15s); a non-owning queue
      worker reschedules itself at the observed lease's expiry rather than dropping the job (§3.1).
- [ ] The bounded fallback (§3.3) runs synchronously and identically on every PHP host, before the REST
      response is returned, without touching Action Scheduler and without any early-response/detached-
      worker trick inside the REST callback; durable AS/cron recovery is preserved as the last resort
      only.
- [ ] Every Telegram API call's timeout is capped by its attempt's *remaining* wall-clock budget, not a
      fixed value — no first-message path (topic creation + send) can exceed its stated budget.
- [ ] The claim lease duration (15s) is confirmed to exceed the maximum possible combined primary +
      fallback hold time (~9–10s) with margin.
- [ ] A message is never lost: durable rows/jobs exist before any attempt runs.
- [ ] Start-idempotency replay never consumes a new-conversation rate-limit token; a wrong-secret
      replay consumes exactly one auth-failure token, nothing else.
- [ ] ADR-0021's uniform-404 guarantee verified byte-for-byte identical with the new `reason` field.
- [ ] Acceptance evidence (§7) is real-browser-only; no WP-CLI/reflection/REST substitute accepted, and
      its no-duplicate claim is scoped to the exercised normal path, not the unavoidable crash window.
