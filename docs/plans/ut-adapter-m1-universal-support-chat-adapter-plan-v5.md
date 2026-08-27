# UT Adapter M1 — Universal Support Chat Adapter — Implementation Plan v5

Supersedes [plan v4](ut-adapter-m1-universal-support-chat-adapter-plan-v4.md) (`docs/plans/README.md`: v4 is retained, unedited, permanently; this file is the frozen plan going forward) only to add work package 10 below. Plan v4's work package 9 (`LegacyBindingImportServiceV1`, `prepared` status, lock-scoped quiescence assertion) is complete and Product Owner accepted — see `docs/closure/ut-adapter-m1-legacy-binding-import-service-closure.md` — and is not reproduced or re-scoped here.

**Documentation-freeze scope only.** This plan is implementation-ready but not authorized to execute — no code, schema, branch, PR, release, tag, deployment, or production quiescence/cutover/route-switch/soak/rollback/deletion is performed by this document.

## 1. References

- Charter: `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (§0d final-cutover follow-up amendment)
- ADRs: ADR-0002, ADR-0007, ADR-0037, ADR-0038, ADR-0039, ADR-0040, ADR-0041 (unedited), **ADR-0042** (new — Support Chat ADR-0010 pin and final-cutover state machine, activation, and incident ownership)

## 2. Repository findings (plan-freeze time)

Plan v4's work package 9 is implemented, merged, and Product Owner accepted. Support Chat's SC-M03 work packages 2–5 are all implemented and Product Owner accepted. Support Chat's final-cutover package is architecturally authorized by Support Chat's own ADR-0010 but blocked on this repository supplying: a new cutover-orchestration state machine; a corrected, monotonic-CAS activation/compensation saga; a cohort-aware amendment to the existing deferred-update replay loop; this repository's own incident record; and the `maybe_mark_topic_unavailable()` cross-talk resolution.

Confirmed by direct source read at plan-freeze time (`origin/main` `a761550f9e4c8b4422cb48dc23b0a6e82fdccbc5`):

- `InboundAdapterBridge::try_handle()`'s sole routing gate is `$binding->is_active()` (`src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:71`), checked in `WebhookController::process_update()` before command dispatch and legacy routing (`src/Telegram/Inbound/WebhookController.php:216-229`) — **this work package requires no change to any of these three call sites' own routing logic.**
- `ChannelBindingRepository::set_status()` (`src/SupportChatAdapter/ChannelBindingRepository.php:182-206`) has no CAS guard, no current-status precondition, and zero production callers — confirmed by repository-wide grep; cannot be reused for activation.
- `QuiescenceGate::with_quiescence_lock()` (`src/Migration/QuiescenceGate.php:186`, ADR-0041) already provides the exact atomic check-and-write pattern activation needs.
- `DeferredUpdateRepository::unreplayed_grouped_by_bot()` (`src/Migration/DeferredUpdateRepository.php:187-220`) already provides the deterministic per-bot, `update_id`-ascending, `id`-tie-broken ordering this work package's replay amendment reuses unchanged.
- `WebhookController::maybe_mark_topic_unavailable()` runs before the adapter-bridge check (`WebhookController.php:212` vs. `216-219`) and matches via `ConversationRepository::find_by_bot_chat_thread()`, whose `WHERE` clause checks only `topic_creation_state = 'created'` (`src/Conversations/ConversationRepository.php:438-464`) — confirmed cross-talk defect, resolved by this plan's WP10.
- `db_version` is `34`; plugin version `0.18.0`, unchanged by this plan itself (bumped only at implementation).

## 3. Assumptions and open questions

**Assumptions:**
- Both plugins continue to run in the same WordPress install for the duration of this work package.
- Support Chat implements its own final-cutover package (cohort candidate identification, the handoff-map schema, the six-operation Contract payload extension) entirely in its own repository per its ADR-0010; this plan does not implement or specify Support Chat-side code.
- The exact WP3-4 Phase B CLI command name used in cohort preflight's own cross-check — not re-verified this session; confirm at implementation time.

**Open questions — none remaining as unresolved architecture.** ADR-0042 fixes the cutover state machine, the activation/compensation saga's CAS semantics, the cohort-aware replay amendment, the incident record and its closed reason vocabulary, and the `maybe_mark_topic_unavailable()` resolution precisely.

## 4. Architectural decisions

- Follow ADR-0042 §1 exactly for the cutover state machine: a new, separate singleton table, layered above (never replacing) the existing ADR-0040 quiescence machine; CAS transitions; append-only, `cutover_run_id`-correlated audit; no force/abandon command.
- Follow ADR-0042 §2 exactly for activation: whole-cohort read-only preflight before any write; one lock-scoped, CAS-guarded transaction per candidate (`activate_prepared()`); automatic compensation via `revert_activation()` on any commit-phase failure; `cas_version` strictly monotonic, never restored — a compensated candidate ends at pre-run `+2`, never back at its pre-run value.
- Follow ADR-0042 §3 exactly for cohort-aware replay: one additional, live `is_active()` check per row inside the existing replay loop, evaluated at drain time, never from a pre-computed cohort list; no separate pre-`exit` scan; the `replaying → idle` backlog predicate widened to three columns (`replayed_at`, `handed_off_at`, `incident_resolved_at`).
- Follow ADR-0042 §4 exactly for the incident record: five new additive columns on `quiescence_deferred_updates`; the closed, non-content reason vocabulary; the narrowly-scoped, Product-Owner-approved `incident-acknowledge` command accepting only an opaque `--po-decision-ref`, never free-form content.
- Follow ADR-0042 §5 exactly for the lifecycle cross-talk fix: an active-binding check ahead of `maybe_mark_topic_unavailable()`'s legacy lookup for topic-lifecycle service messages only; dispatch via the existing `SupportChatContractClient::report_channel_unavailable()`; fail-closed "claimed but not delivered" semantics mirroring `try_handle()`'s own existing pattern.
- No Contract v1 operation-allow-list change on this repository's own outbound calling surface beyond what Support Chat's ADR-0010 already authorizes on the receiving side; no new REST route, Ajax handler, or cron-invoked path.

## 5. Directory, namespace, schema, and API impact

- New schema step (number assigned at implementation time, additive): a new singleton `universal_telegram_cutover_state` table and its own append-only `universal_telegram_cutover_transitions` audit table, mirroring `quiescence_state`/`quiescence_transitions`' own shape.
- New schema step, additive columns on the existing `universal_telegram_quiescence_deferred_updates` table: `handed_off_at DATETIME NULL`, `incident_reason VARCHAR(64) NULL`, `incident_recorded_at DATETIME NULL`, `incident_resolved_at DATETIME NULL`, `incident_resolution VARCHAR(32) NULL`.
- `ChannelBindingRepository` gains two new methods: `activate_prepared( string $binding_uuid, int $expected_cas ): bool` and `revert_activation( string $binding_uuid, int $expected_cas ): bool` (saga-internal-only, never a general operator command) — both CAS-guarded, current-status-guarded, per ADR-0042 §2.
- New `Migration\Cli\CutoverCommand` (namespace illustrative, final naming at implementation time): `wp universal-telegram cutover {status, begin, activate, handoff-deferred-updates, confirm-complete, incident-acknowledge, recover}`, self-registering under the existing `defined('WP_CLI') && WP_CLI` guard pattern.
- `WebhookController::process_update()`'s topic-lifecycle-service-message branch reordered per ADR-0042 §5 — the only change to existing, previously-shipped routing code this whole final-cutover design requires anywhere in this repository.
- `QuiescenceGate::attempt_replaying_to_idle()`'s per-row dispatch amended to check `ChannelBindingRepository::find_by_bot_topic()`'s live `is_active()` result before choosing between the (new) cohort-aware handoff dispatcher and the (existing, unchanged) `process_update()` legacy branch; its final backlog-empty CAS predicate widened per §4 above.
- No new REST route. No change to `SupportChatAdapter\Outbound\OutboundContractController` or any existing Contract v1 acceptor. `InboundAdapterBridge`, `DeliverMessageService`, and `try_handle()`'s own gate are **unchanged**.

## 6. Security and privacy impact

Per ADR-0042 in full: no new REST route, no new authentication mechanism, no shared secret. Every new persisted field across the new cutover-state table, the incident columns, and the audit trail is non-content — ids, uuids, a fixed reason/kind vocabulary, timestamps. The `incident-acknowledge` command accepts only an opaque, pre-existing Product Owner decision reference, never free-form text, in any argument it writes anywhere.

## 7. Test and CI impact

- Whole-cohort activation and compensation: preflight rejects the whole cohort on one ineligible candidate with zero writes; a forced mid-commit failure after N successful commits triggers exactly N compensating reverts; every compensated candidate's `cas_version` confirmed at pre-run `+2`, never restored; every activation and its compensation in the same forced-failure test share one `cutover_run_id`.
- Crash/restart at every saga boundary: pre-commit, post-commit-pre-audit, mid-compensation — each proven idempotent-safe on retry.
- Cohort-aware deferred replay: a row for an active-at-drain-time topic never reaches legacy `process_update()`; a row for an inactive/failed-activation topic correctly falls back to legacy replay with no special-casing required.
- Success-after-SC-commit/before-UT-ack retry convergence: a forced crash between Support Chat's `{ok: true}` response and this repository's own `handed_off_at` stamp, followed by a retry, produces exactly one domain effect and exactly one `handed_off_at` stamp — real dual-plugin interop, against Support Chat's real handoff-map implementation once merged.
- Support Chat handoff-map idempotency and provenance-conflict refusal: a genuine retry (matching `kind`/`channel_case_ref`) succeeds silently; a mismatched retry is refused `409` and writes nothing — proven against Support Chat's real handler, real dual-plugin interop.
- This repository's own incident record: one test per closed reason code, asserting zero Support Chat Contract calls are ever attempted for `decrypt_failed`/`parse_failed`/`unsupported_command`/`unmapped_sender`; `incident-acknowledge` stamps only `incident_resolved_at`/`incident_resolution`, never `replayed_at`/`handed_off_at`, never deletes ciphertext.
- The serialized webhook-vs-final-idle race: the existing ADR-0040 two-connection interleaving proof (`QuiescenceRaceInterleavingTest`), re-run against the widened three-column backlog predicate.
- Lifecycle-event non-cross-talk: a real active-binding topic's `forum_topic_closed`/`forum_topic_deleted` reaches `report_channel_unavailable`, never legacy mutation; an inactive-binding topic's identical event still reaches legacy mutation unchanged — both proven via real dual-plugin interop, extending the existing `InboundAdapterBridgeNonInterferenceTest` family.
- All supported WordPress/PHP combinations (floor and current) per this repository's existing CI matrix, plus real dual-plugin interop for every cross-plugin scenario above.
- **A permanent regression proof that no `prepared` binding ever routes traffic, and no `active` binding is ever silently skipped by any of this work package's own new code paths** — extending the existing `InboundAdapterBridgeNonInterferenceTest`/its WP9-era activation counterpart with this work package's own cohort-activation and cohort-aware-replay call sites.

## 8. Work packages (execution order)

### WP10 — Final cutover: state machine, activation saga, cohort-aware replay, incident record, lifecycle cross-talk fix

- New cutover-state and transitions tables; `activate_prepared()`/`revert_activation()`.
- Whole-cohort preflight and the all-or-nothing commit/compensation saga.
- Cohort-aware amendment to the existing replay loop; widened backlog-empty predicate.
- Incident-record columns, closed reason vocabulary, and `incident-acknowledge` command (opaque PO-reference only).
- `maybe_mark_topic_unavailable()` reordering.
- `cutover` WP-CLI namespace.
- Permanent regression tests per §7.
- Documentation: closure record for this work package, citing this plan's freeze SHA.
- **Explicitly excluded from this work package** (ADR-0042 §"Consequences", restated for execution clarity): any production quiescence, cutover, route switch, soak, rollback, or deletion; any change to `InboundAdapterBridge`, `DeliverMessageService`, or `try_handle()`'s own gate; any Support Chat-side code.
- *Gate: ADR-0042 merged before this work package's implementation begins.*

## 9. Risks and mitigations

- Implementing against an unmerged ADR-0042 — mitigated by the explicit gate stated in §8 and in the milestone charter's §0d.
- A compensation implementation that restores `cas_version` rather than advancing it monotonically — mitigated by ADR-0042 §2's explicit, corrected invariant and a dedicated regression test asserting the exact post-compensation `cas_version` value.
- Building the cohort-aware replay check as a second, subtly-different binding lookup rather than genuinely reusing `find_by_bot_topic()` — mitigated by §4/§5's explicit instruction to reuse the identical method `try_handle()` already calls.
- Scope creep into hardening `BindingImportCommand`'s own pre-existing, unrelated reroute risk (carried forward from WP9, still unresolved) during this work package — mitigated by this plan's explicit exclusion list; this work package's own `activation_conflict_*`/incident outcomes remain only a detection mechanism for a collision with it, never a fix at its source.

## 10. Explicit out-of-scope list

Any production quiescence, cutover, route switch, soak, rollback, or deletion; any code, schema, branch, PR, release, tag, or deployment in this task; Support Chat's own final-cutover schema/handler/Contract-payload work (Support Chat repository); retirement of this plugin's legacy Conversations tab, AI tab, chat widget, or chat settings (separate future task, gated on SC-M03 acceptance); any AI-related migration.

## 11. Definition of done

1. ADR-0042 merged to `main`.
2. The cutover state machine, activation/compensation saga, cohort-aware replay amendment, incident record, and `maybe_mark_topic_unavailable()` fix implemented and passing the full test suite (§7) in this repository's CI, including every permanent regression test.
3. Closure record committed; Product Owner acceptance recorded.
4. Only then does Support Chat's final-cutover implementation proceed past its own two-repository merge gate (ADR-0010 Compatibility/Migration Impact; this plan's §8 gate, mirrored from Support Chat's side).
