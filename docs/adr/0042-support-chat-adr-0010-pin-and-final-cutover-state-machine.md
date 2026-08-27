# ADR-0042 — Support Chat ADR-0010 Pin and Final-Cutover State Machine, Activation, and Incident Ownership

## Status

Accepted

## Context

Support Chat's ADR-0010 (`magpern/universal-support-chat`, freezing the final SC-M03 cutover package's handoff contract and cohort activation model) requires a corresponding Universal Telegram amendment, exactly mirroring the two-repository gate ADR-0007/ADR-0038, ADR-0008/ADR-0039, and ADR-0009/ADR-0041 already established. This ADR is that amendment. It pins Support Chat ADR-0010 and freezes the half of the final-cutover design this repository owns: a new, narrow cutover-orchestration state machine layered above (not replacing) the existing ADR-0040 quiescence machine; the corrected, monotonic-CAS activation/compensation saga for `prepared → active`; the cohort-aware amendment to the existing deferred-update replay loop; this repository's own incident record for pre-dispatch failures; and the resolution of the `maybe_mark_topic_unavailable()` live-webhook cross-talk risk against the adapter bridge.

This ADR **freezes design only**. It authorizes no implementation, no schema, no version bump, no branch, no production quiescence, cutover, route switch, soak, rollback, or deletion.

### Required source verification performed for this ADR

Every code-level claim below was verified directly against `origin/main` at `a761550f9e4c8b4422cb48dc23b0a6e82fdccbc5` this session, not assumed from prior planning text:

1. **`InboundAdapterBridge::try_handle()`'s sole routing gate is `$binding->is_active()`** (`src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:71`), checked in `WebhookController::process_update()` before command dispatch and before legacy conversation routing (`src/Telegram/Inbound/WebhookController.php:216-229`). **Unchanged by this ADR** — activation's only effect on live routing is via this pre-existing, already-tested gate.
2. **`DeliverMessageService::deliver()` resolves `(bot_id, destination_id)` from the binding row itself** (`src/SupportChatAdapter/Outbound/DeliverMessageService.php:67-80`) — outbound routing requires no change.
3. **`ChannelBindingRepository::set_status()` has no CAS/version guard, no current-status precondition, and zero production callers today** (`src/SupportChatAdapter/ChannelBindingRepository.php:182-206`; confirmed by repository-wide grep against every other call site in `src/` and `tests/`). It cannot be reused for activation.
4. **`QuiescenceGate::with_quiescence_lock( callable $work ): string`** (`src/Migration/QuiescenceGate.php:186`, ADR-0041) already provides the exact atomic check-current-state-and-write pattern activation needs — reused unchanged, not reinvented.
5. **`DeferredUpdateRepository::unreplayed_grouped_by_bot()`** (`src/Migration/DeferredUpdateRepository.php:187-220`) already provides deterministic per-bot, `update_id`-ascending, `id`-tie-broken ordering — reused unchanged; this ADR amends only the *dispatch decision* made per row, not the ordering.
6. **`WebhookController::maybe_mark_topic_unavailable()` runs before the adapter-bridge check** (`WebhookController.php:212` vs. `216-219`), and matches via `ConversationRepository::find_by_bot_chat_thread()`, whose `WHERE` clause checks only `topic_creation_state = 'created'` — **never the legacy conversation's own open/closed/resolved status** (`src/Conversations/ConversationRepository.php:438-464`). A legacy conversation row therefore continues to match this lookup indefinitely after its topic's binding is activated. This is the cross-talk §5 resolves.
7. **`QuiescenceGate::attempt_replaying_to_idle()`** (`QuiescenceGate.php:399`) and `decide_webhook_disposition()` (`QuiescenceGate.php:356`) already serialize on the same `quiescence_state` row lock (ADR-0040 §3's proof, unchanged) — this ADR widens only the *backlog-empty predicate* that CAS checks, not the locking mechanism.

## Decision

### 1. A new cutover-orchestration state machine, layered above quiescence, not replacing it

ADR-0040's `idle → draining → quiescent → replaying → idle` machine is reused **unmodified**. A new, separate singleton table records cutover-orchestration phase, independently of quiescence state, mirroring ADR-0040 §4's own reasoning for keeping its state table (Table 1) and audit trail (Table 2) separate:

```
not_started ──begin()──▶ prepared ──activate()──▶ activating ──(saga outcome)──▶ activated | activation_failed ──confirm_complete()──▶ complete
```

- `begin( cohort_file )`: `not_started → prepared`. Requires `quiescence.state === quiescent`. Loads and cross-validates the operator-supplied cohort (§2) read-only; writes nothing to any binding.
- `activate --assume-cutover-authority [--dry-run]`: `prepared → activating → (activated | activation_failed)`. The only command permitted to write binding status (§2). Mandatory authority flag, no default.
- `handoff-deferred-updates`/the amended `replay-deferred-updates` (§3): runs inside `activated`, no further cutover-state transition of its own.
- `confirm-complete --assume-cutover-authority`: `activated → complete`, gated on the widened backlog predicate (§3) being empty and every incident (§4) either resolved or explicitly, permanently acknowledged (§4).
- Every transition is a single CAS `UPDATE ... WHERE state = %s` (the identical mechanic `QuiescenceGate::try_transition()` already uses), so concurrent/duplicate invocations resolve safely; every transition inserts one append-only audit row, carrying a per-invocation `cutover_run_id` (§2) — never binding content, never message content.
- **No force/abandon command exists in this surface**, mirroring ADR-0040 §"Alternatives" #9's identical rejection of a "force-idle" command, applied here to cutover state.

### 2. Cohort activation — corrected two-phase saga, monotonic CAS, no restored value

**Preflight** (read-only, whole cohort, `begin()`): every candidate confirmed `prepared`, matching, mapping-complete on Support Chat's side, and free of any blocking incident (§4) — in one pass, before any write. **A single failing candidate refuses the whole cohort.**

**Commit phase** (`activate`, one lock-scoped transaction per candidate, reusing `with_quiescence_lock()` unchanged): a new, CAS-guarded, current-status-guarded method — **not** a reuse of `set_status()` (§"Required source verification" item 3) —

```php
// ChannelBindingRepository (new, additive)
public function activate_prepared( string $binding_uuid, int $expected_cas ): bool {
    // UPDATE ... SET status = 'active', cas_version = cas_version + 1, updated_at = NOW()
    //   WHERE binding_uuid = %s AND status = 'prepared' AND cas_version = %d
}
```

**If any candidate's commit-time re-check fails, the run halts and compensates**: every candidate already committed `active` in this same run is reverted via a second new, saga-internal-only method —

```php
// ChannelBindingRepository (new, additive, callable only from the saga's own compensation path)
public function revert_activation( string $binding_uuid, int $expected_cas ): bool {
    // UPDATE ... SET status = 'prepared', cas_version = cas_version + 1, updated_at = NOW()
    //   WHERE binding_uuid = %s AND status = 'active' AND cas_version = %d
}
```

**Frozen invariants, corrected from an earlier draft that wrongly claimed CAS could be "restored":**

- `cas_version` is **strictly monotonic**. For a candidate starting at `(prepared, N)`: a successful activation ends at `(active, N+1)`; a subsequently compensated activation ends at `(prepared, N+2)` — **never** back at `N`. Every future caller must present `expected_cas = N+2`, not `N`, after a compensated run.
- **Status and semantic routing state return to `prepared`** on compensation — this, not the numeric CAS value, is the property that actually matters: `is_active()` is `false` again, `try_handle()` structurally cannot claim traffic for this binding (§"Required source verification" item 1).
- **No external traffic may pass while the saga or its compensation runs** — a structural property, not a policy statement: the cohort-aware replay dispatcher (§3) never runs until the saga has already reached a terminal `activated`/`activation_failed` state, and quiescence remains non-`idle` (blocking/buffering every arrival) for the saga's entire duration, so no buffered or live update can reach a binding the saga might still compensate away.
- **Audit records link both an activation and its compensation to the same saga/run** — every audit row this command produces (each candidate's `activate_prepared()` success, each candidate's `revert_activation()` compensation, the cohort's own final transition) carries the same `cutover_run_id` (a UUID generated once per `activate` invocation), reconstructable as one linked sequence.
- **Operator confirmation, run identity, durable audit, resume/recovery, fail-closed semantics**: `--assume-cutover-authority` mandatory, no default; `cutover_run_id` as above; every transition audited in the same transaction as its CAS; a crash mid-saga leaves already-committed candidates untouched and never-attempted candidates simply retried by the next `activate` invocation for the same cohort file (idempotent resume, no special flag); a preflight or commit-phase failure never silently leaves a mixed cohort state (§"Alternatives" below).

### 3. Cohort-aware deferred-update replay — one authoritative barrier, no separate scan

The existing `replay-deferred-updates` loop (ADR-0040 §6, `QuiescenceGate::attempt_replaying_to_idle()`, unchanged trigger and lock mechanics) gains, per row, one additional live check: **does `ChannelBindingRepository::find_by_bot_topic()` currently return an `active` binding for this row's `(bot_id, telegram_topic_id)`?** — the identical predicate `try_handle()` itself already evaluates (§"Required source verification" item 1), reused, evaluated fresh at drain time, never from a pre-computed cohort list. If yes, the row is dispatched through the cohort-aware handoff dispatcher (§4, and Support Chat ADR-0010 §4's handler-side half) instead of the legacy `process_update()` branch; if no — including a cohort candidate whose activation ultimately failed and was compensated — the row is replayed exactly as ADR-0040 already specifies, automatically and correctly, with no special-casing required.

**Deterministic per-bot ordering by `(update_id, id)` is unchanged** (§"Required source verification" item 5) — this amendment adds a per-row dispatch-target decision, never a reordering.

**The final `replaying → idle` transition remains serialized with webhook buffering on the same row lock** ADR-0040 §3 already proves closes this race (§"Required source verification" item 7) — unchanged. **Its backlog predicate is widened**, from ADR-0040's original `replayed_at IS NULL`, to:

```sql
COUNT(*) FROM {deferred_updates}
WHERE replayed_at IS NULL
  AND handed_off_at IS NULL
  AND incident_resolved_at IS NULL
```

— three new, additive columns on the existing `quiescence_deferred_updates` table: `handed_off_at DATETIME NULL` (stamped only after Support Chat's handler returns `{ok: true}` — never before, per Support Chat ADR-0010 §4's exact crash/retry convergence proof, cross-referenced not duplicated here), `incident_reason VARCHAR(64) NULL`, `incident_recorded_at DATETIME NULL`, `incident_resolved_at DATETIME NULL`, `incident_resolution VARCHAR(32) NULL` (§4).

**There is no separate "final handoff scan" step performed before `quiescence exit`.** The replay loop itself is the single authoritative drain — this is what closes the race an earlier draft of this design left open (a row arriving between a separate scan and `quiescence exit` would have been invisible to that scan and wrongly fallen to legacy replay).

### 4. This repository's own incident record — never a Support Chat write, strictly separated

**Frozen, exhaustive rule:** a pre-dispatch failure — decrypt failure, parse failure, an unsupported command classification, an unmapped sender — occurs entirely inside this repository's own cohort-aware replay dispatcher, strictly before any Support Chat Contract call is attempted. **No Support Chat operation is ever invoked for these.** A `409 handoff_provenance_conflict` refusal from Support Chat (ADR-0010 §4) is likewise recorded here as an incident, never accompanied by any write on Support Chat's side (Support Chat rolled back and wrote nothing for that call).

**Closed, non-content reason vocabulary**: `decrypt_failed`, `parse_failed`, `unsupported_command`, `unmapped_sender`, `handoff_provenance_conflict`. A transient transport/availability failure is **not** an incident — no reason is set, the row is simply re-selected by the next ordinary replay-loop pass, matching this whole programme's established "resume is retry" pattern.

**An unresolved incident blocks both `replaying → idle` (§3's widened predicate) and `confirm-complete`.**

**Product Owner decision, recorded in Support Chat's ADR-0010 §5/PO decision record, binding on this repository's implementation too**: the exceptional terminal-acknowledgement path is retained. This repository's own new command —

```
wp universal-telegram cutover incident-acknowledge --id=<row> --po-decision-ref=<opaque reference> --assume-cutover-authority
```

— stamps `incident_resolved_at`/`incident_resolution = 'po_acknowledged_terminal'`, **never** `replayed_at` or `handed_off_at`. It accepts **only** an opaque, pre-existing Product Owner decision reference (never free-form text) in `--po-decision-ref`, and no other content-bearing argument. The row's ciphertext and full audit trail are **never deleted** by this command or by any retention sweep — the existing 30-day replayed-row cleanup pass (ADR-0040 §3) is amended to select `WHERE (replayed_at IS NOT NULL OR handed_off_at IS NOT NULL) AND ... < cutoff`, **never** touching a row whose `incident_resolved_at` alone is set, since that row was never replayed or handed off and its content must be retained forever regardless of the workflow-level acknowledgement.

### 5. `maybe_mark_topic_unavailable()` live-webhook cross-talk — resolved and frozen

**Confirmed defect** (§"Required source verification" item 6, restated): the legacy lookup this method uses continues to match an activated cohort topic's legacy conversation row indefinitely, since nothing in this whole programme ever mutates `topic_creation_state`.

**Frozen resolution:** `WebhookController::process_update()`'s handling of a topic-lifecycle service message (`forum_topic_closed`/`forum_topic_deleted`) is reordered so that, **before** `maybe_mark_topic_unavailable()`'s legacy lookup runs, a check for an **active** binding on the update's `(bot_id, message_thread_id)` is performed — reusing `ChannelBindingRepository::find_by_bot_topic()`, the identical lookup `try_handle()` already performs. **If an active binding exists**, the event is dispatched via `SupportChatContractClient::report_channel_unavailable( binding_uuid, reason_code )`, reusing the **same** fixed `reason_code` vocabulary legacy already emits — `'telegram_topic_closed'` / `'telegram_topic_deleted'`, confirmed identical strings (`WebhookController.php:354`) — and the update is considered handled, **never** reaching `maybe_mark_topic_unavailable()`'s legacy mutation. **If no active binding exists**, existing legacy behavior is retained unchanged.

**Fail-closed semantics, precise:** mirroring `try_handle()`'s own existing "claimed but fail-closed for channel only" pattern (`InboundAdapterBridge.php:75-77`) exactly — if the Contract call itself fails (adapter unpaired, discovery incompatible, transport failure), the event is still considered **claimed** and does **not** fall through to legacy mutation, since falling through would mutate a legacy conversation row for a topic no longer legacy-owned. This is a **live-webhook-path** fix, distinct from and never touching the deferred-replay incident record (§4), which applies only to buffered rows processed during quiescence/replay.

**`ChannelStatusRepository::upsert()`'s retry/timestamp behavior, verified this session and frozen** (Support Chat `src/ChannelContract/ChannelStatusRepository.php:110-134`, `UNIQUE(conversation_id)`): a real `INSERT ... ON DUPLICATE KEY UPDATE`; repeated identical calls converge to the identical `status`/`reason_code` and legitimately advance `updated_at` on every call — correct, expected behavior, requiring no change, for both this live-webhook path and the deferred-replay path (Support Chat ADR-0010 §4).

## Alternatives

- **A dedicated cutover state added to `quiescence_state.state` itself, rather than a separate table** — rejected: would duplicate ADR-0040's already-tested write-blocking/buffering machinery inside a second, parallel state dimension, exactly the class of design ADR-0040 §"Alternatives" #4/#7 already reject for analogous reasons.
- **Allowing partial cohort activation, with conflicts treated as a normal, acceptable per-row outcome** — rejected: contradicts the charter's own "Partial cutover — forbidden; switch is atomic" principle, now clarified (not weakened) by Support Chat's PO decision record to mean atomic *per approved cohort*.
- **Restoring `cas_version` to its pre-activation value on compensation** — rejected, corrected in this ADR: would violate the monotonic-CAS invariant every other guarded write in this repository already relies on, creating an ABA-style collision hazard for any future caller presenting a stale `expected_cas`.
- **A separate "final handoff scan" step before `quiescence exit`, distinct from the replay loop** — rejected: creates an unclosed race window between the scan and the exit transition; unifying scan-and-drain into the single existing, already-lock-serialized replay loop closes it structurally.
- **Requiring every incident to be remediated with no exception path** — considered and rejected by explicit Product Owner decision (Support Chat ADR-0010 §5/PO decision record): a single genuinely unrecoverable row would otherwise have no defined way to ever let cutover complete.
- **Reusing `set_status()` for activation** — rejected: confirmed to have no CAS guard and no current-status precondition (§"Required source verification" item 3).

## Consequences

- This repository gains one new singleton cutover-state table, two new guarded `ChannelBindingRepository` methods, five new additive columns on `quiescence_deferred_updates`, an amendment to the existing replay loop's per-row dispatch decision and its backlog-empty predicate, a new `cutover` WP-CLI namespace, and a reordering of `process_update()`'s topic-lifecycle-service-message handling. No existing table, column, or index is altered; no existing behavior changes for any install that never invokes the new command surface.
- `InboundAdapterBridge`, `DeliverMessageService`, and `try_handle()`'s own routing gate remain **entirely unchanged** — activation's effect on live routing is a proven, pre-existing code property (§"Required source verification" item 1), not new code.
- A future, separately-approved implementation task is required before any of this design's code, schema, or CLI surface exists. This ADR authorizes documentation only.

## Security and privacy impact

- No new REST route, no new authentication mechanism, no shared secret — the cutover WP-CLI surface follows the identical WP-CLI-only, OS-shell-authority boundary this repository's every prior migration surface already establishes.
- Every new persisted field is non-content: ids, uuids, a fixed reason/kind vocabulary, timestamps. The terminal-acknowledgement exception (§4) accepts only an opaque, pre-existing Product Owner reference — never free-form content — preventing it from becoming an uncontrolled audit-poisoning surface.
- The `maybe_mark_topic_unavailable()` reordering (§5) reduces, not increases, this repository's cross-plugin exposure: an activated topic's lifecycle event is routed through the already-authenticated, already-signed adapter Contract channel instead of mutating a legacy row that topic no longer legitimately owns.

## Affected Documents/Milestones

- `docs/adr/README.md` (index and reserved-number table updated for ADR-0042).
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (additive `§0d`).
- `docs/plans/README.md`, `docs/master-plan.md`, `docs/ARCHITECTURE.md`, `docs/milestones/README.md` (cross-reference updates).
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v5.md` (new — supersedes v4 only to add the final-cutover work package; v1–v4 retained unedited).
- ADR-0040, ADR-0041 (referenced, unedited).
- Support Chat repository: ADR-0010 (pinned, external, unedited by this ADR) — see pin table below.

## Compatibility/Migration Impact

- No runtime code, schema, plugin version (`0.18.0` unchanged), `db_version` (`34` unchanged), release, tag, or deployment change in this freeze — this ADR is documentation only.
- Future implementation may not begin until **both** this ADR and Support Chat's ADR-0010 are merged to their respective `main` branches, mirroring the identical two-repository gate every prior SC-M03 work package already established.
- This ADR does not authorize, schedule, or execute any production quiescence, cutover, route switch, soak, rollback, or deletion.

## Pin (exact, immutable reference)

| Item | Value |
|---|---|
| Support Chat ADR-0010 | Commit SHA `be7461544a39c7ad074164d21e3c1b04c71f2fc2` — `https://github.com/magpern/universal-support-chat/blob/be7461544a39c7ad074164d21e3c1b04c71f2fc2/docs/adr/0010-final-cutover-handoff-contract-and-cohort-activation.md` |

Universal Telegram does not copy Support Chat ADR-0010's text into this repository, mirroring the existing ADR-0037/ADR-0038/ADR-0039/ADR-0041 rule against duplicating Support Chat's ADRs. The pin SHA above was filled in from Support Chat PR #16's real merge commit before this ADR's own PR merged — mirroring the identical placeholder-then-`sed` sequencing ADR-0041 already used for Support Chat ADR-0009.
