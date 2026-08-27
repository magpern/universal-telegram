# ADR-0043: Support Chat ADR-0011 pin; `channel_case_ref` = Support Chat conversation UUID; deferred-replay fail-closed classification (F1)

## Status

**Proposed** — awaiting Product Owner review. Documentation-only; no code, schema, or
`Migrator::target_version()` change is made by this ADR. The adapter-side changes it specifies
are executed only under the separately-reviewed remediation plan
`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` after acceptance.

**Pins Support Chat ADR-0011** ("Contract v1 `channel_case_ref` is the Support Chat conversation
UUID; provenance-map and fail-closed semantics"). **Amends ADR-0042 §3 (handoff `channel_case_ref`
sender identity) and §4/§5 (closed incident vocabulary).** On acceptance, ADR-0042's Status field
gains "handoff `channel_case_ref` sender identity and closed incident vocabulary amended by
ADR-0043" (Status-field-only change per the immutability rule; ADR-0042's body is not edited).

## Context

The SC-M03 final-cutover disposable DEV rehearsal Tier 1 prerequisite validation halted at the
UT→SC deferred-update handoff phase by **finding F1**. Records of the halt (present in
`origin/main`, verified):

- Universal Telegram Tier 1 closure + characterization test — merge
  `98c602543bd67bc471e2a88468d175fb6e659b46`
  (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`,
  `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php` — OK, 2 tests / 41
  assertions on the pinned SHAs).
- Universal Support Chat Tier 1 closure — merge `fcbfaa773ef63661b6d8ce42962f10bb174588f8`.

### F1, from source (verified at UT `31519ee` / SC `ce46912`)

Two identifiers, never equal for a real binding:

| Identity | Owner | Field |
|---|---|---|
| **Binding identity** — the UT-owned row binding a Telegram forum topic to a Support Chat case; used for binding lookup, lifecycle, activation, and routing | Universal Telegram | `…_support_chat_bindings.binding_uuid` `CHAR(36) NOT NULL`, `UNIQUE KEY` — `ChannelBinding::binding_uuid()`. `wp_generate_uuid4()` at creation. |
| **Case identity** — the Support Chat conversation/case | Universal Support Chat | `Conversation::uuid()` via `ConversationRepository::find_by_uuid()`. |
| **The Support Chat conversation UUID, stored on the UT binding row** | Universal Telegram | `…_support_chat_bindings.support_conversation_uuid` `CHAR(36) NOT NULL`, `UNIQUE KEY support_conversation_uuid` — `ChannelBinding::support_conversation_uuid()`. |

**Authoritative UT field carrying the Support Chat conversation UUID: `ChannelBinding::support_conversation_uuid()`**
(column `support_conversation_uuid`). Proof it is unambiguous:

- Schema: `CREATE TABLE … support_conversation_uuid CHAR(36) NOT NULL … UNIQUE KEY support_conversation_uuid (support_conversation_uuid)`
  (`src/Persistence/Migrator.php` bindings-table step). `NOT NULL` + `UNIQUE` ⇒ every binding has
  exactly one, one-to-one with a Support Chat conversation.
- `ChannelBindingRepository::create( string $binding_uuid, string $support_conversation_uuid, … )`
  writes the second argument verbatim to that column; `find_by_conversation_uuid()` queries
  `WHERE support_conversation_uuid = %s`.
- `LegacyBindingImportServiceV1::import_one()` passes `(string) $candidate['support_conversation_uuid']`
  as that argument — the value comes from Support Chat's WP5 `legacy-bind run` output, i.e. a
  real Support Chat conversation UUID.
- `EnsureChannelCaseService::ensure( string $conversation_uuid, … )` passes its
  `$conversation_uuid` argument (the Support Chat `ensure` request field) as that argument.

There is no ambiguity and no missing field; the correction rests on an existing, populated,
uniquely-constrained column.

**The wire disagreed with Support Chat's resolver.** Universal Telegram sends
`$binding->binding_uuid()` as `channel_case_ref` everywhere; Support Chat's
`ContractOperationDispatcher::resolve_conversation()` resolves `channel_case_ref` as its own
`conversation_uuid` (`conversations->find_by_uuid()`). For a real distinct `binding_uuid`,
Support Chat returns `404 not_found`.

**Second defect — fail-closed classification.** `CutoverReplayDispatcher::finish()`
(`src/Migration/CutoverReplayDispatcher.php:232-244`) maps every non-
`handoff_provenance_conflict` Contract failure — **including `404 not_found`** — to
`OUTCOME_RETRY_TRANSIENT`. The caller (`QuiescenceCommand`'s replay loop) has *already* selected
an **active** binding for this row (`CutoverReplayDispatcher` class docblock: "this class does
not re-resolve or re-decide it", and has no legacy fallthrough). After an active binding is
selected, an unresolvable `channel_case_ref` is not a transient condition — but the current code
retries it every pass forever, so the widened backlog predicate never empties and
`replaying → idle` / `confirm-complete` are blocked indefinitely **with no classified outcome**.
The DEV rehearsal is designed to surface exactly this.

Product Owner direction (2026-08-27): adopt the alternative where `channel_case_ref` carries the
Support Chat conversation UUID; reject identifier collapse (option (c)); reject an SC-side
binding-map resolver unless separately designed later; confirm the exact stored field/source
mapping in ADR review before code (done above); produce this documentation-only correction.

## Decision

### 1. Identity

**Universal Telegram retains its independent `binding_uuid`** for binding lookup
(`find_by_uuid`, `find_by_bot_topic`), lifecycle (`set_status`), activation
(`activate_prepared` / `revert_activation` CAS), routing identity, idempotency keys
(`ensure_idempotency_key`, delivery keys, `record_ingest_update_id`), and every `cutover_*` /
binding audit row. `binding_uuid` is **UT-local and never crosses the Contract v1 wire.**

**Universal Telegram sends `$binding->support_conversation_uuid()` as `channel_case_ref`** on
every relevant live and cutover Contract v1 operation:

| Path | Sites (verified) | Operations |
|---|---|---|
| `InboundAdapterBridge::try_handle()` + `handle_command()` | `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:118,134,144,157-165` | live `ingest_operator_reply`, `claim` / `release` / `resolve` / `reopen` |
| `CutoverReplayDispatcher` | `src/Migration/CutoverReplayDispatcher.php:135,189-192,214` | cutover-replay `ingest_operator_reply`, the four lifecycle commands, `report_channel_unavailable` |
| shared client used by all six provenance-capable operations | `src/SupportChatAdapter/Inbound/SupportChatContractClient.php` (`ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `report_channel_unavailable`) | parameter renamed/redocumented `channel_case_ref` = Support Chat conversation UUID |
| lifecycle-unavailable reporting whose Contract request carries a case reference | `CutoverReplayDispatcher::dispatch_lifecycle_event()` (`:214`) and the live `WebhookController` → `report_channel_unavailable( binding_uuid, … )` path named in ADR-0042 §5 | `report_channel_unavailable` — send `support_conversation_uuid()` |

`EnsureChannelCaseService::ensure()` returns the Support Chat conversation UUID (equivalently
`$binding->support_conversation_uuid()`, identical to its `$conversation_uuid` argument) as the
`channel_case_ref` it hands back to Support Chat, in all return branches.

**Universal Telegram resolves an inbound `channel_case_ref` (SC→UT deliver/notify/backfill) by
conversation UUID**: `DeliverMessageService::deliver()` (`src/SupportChatAdapter/Outbound/DeliverMessageService.php:67`)
changes `find_by_uuid( $channel_case_ref )` → `find_by_conversation_uuid( $channel_case_ref )`.
All subsequent local-row writes there continue to key on the resolved `$binding->binding_uuid()`.

### 2. Support Chat resolution and provenance map — unchanged

Support Chat continues resolving `channel_case_ref` as its own conversation UUID through
`ConversationRepository::find_by_uuid()` (Support Chat ADR-0011). **No SC-side
binding-to-conversation lookup table, direct UT SQL, shared map, or fallback that interprets a
UT binding UUID as an SC identifier is added** — rejected by Support Chat ADR-0011 and this ADR.
The `legacy_handoff_map.channel_case_ref` value is the Support Chat conversation UUID (already
the case — `dispatch_with_provenance()` is passed `$conversation->uuid()`), never the UT binding
UUID.

### 3. Fail-closed, classified replay outcome

`CutoverReplayDispatcher::finish()` is corrected so that, **after an active binding has been
selected**, an unresolvable `channel_case_ref` is a **durable UT-only incident**, not an
unbounded transient retry:

- Add one closed code to `CutoverIncidentReason` — `unresolved_case_reference` — extending the
  vocabulary ADR-0042 §4 owns and ADR-0010 §4 / Support Chat ADR-0011 reference. `finish()`
  maps a `404 not_found` (and any Support Chat "malformed/absent case reference" rejection)
  to `incident( $record, CutoverIncidentReason::UNRESOLVED_CASE_REFERENCE )` →
  `OUTCOME_INCIDENT`. Like every other incident it blocks `replaying → idle` and
  `confirm-complete`, writes **no** `legacy_handoff_map` row, stamps neither `replayed_at` nor
  `handed_off_at`, and is resolvable only by a real retry that succeeds (auto-stamping
  `incident_resolution = 'retried_success'`) or by the existing authority-gated
  `cutover incident-acknowledge --po-decision-ref` terminal path (ADR-0042 §4 — unchanged,
  opaque ref, never stamps `replayed_at`/`handed_off_at`).
- Genuinely transient failures — Support Chat unreachable, `503`, `contract_auth_failed`,
  not-paired, discovery-incompatible, a caught collaborator exception — stay
  `OUTCOME_RETRY_TRANSIENT` and are **not** incidents. Unchanged.
- No legacy-processing fallback after an active binding is selected (the dispatcher already has
  none); no implicit UUID-equality assumption; no silent retry loop without an outcome.
- **Live inbound** (`InboundAdapterBridge::try_handle()`) intentionally discards the Contract
  result and marks the update ingested regardless (at-most-once). The correction makes it send
  the resolvable ref so a `404` no longer occurs for a correctly-bound topic; the at-most-once
  ingest contract is **not** changed and no live-path incident record is introduced (a live
  `404` after this fix implies genuine Support Chat data loss and is out of scope — the
  remediation plan adds only a non-content audit event for it).

### 4. Scope boundary

Not changed by this ADR: the quiescence state machine (ADR-0040), the activation/compensation
saga and `cas_version` monotonicity (ADR-0042 §2), `InboundAdapterBridge`'s existing
`return false` for non-lifecycle bot commands on an active-bound topic (live traffic; ADR-0042
§3 unchanged), `try_handle()` / `DeliverMessageService` / `process_update()` routing order,
`maybe_mark_topic_unavailable()` cross-talk fix (ADR-0042 §5 — its `report_channel_unavailable`
call gains the conversation-UUID ref but its ordering and fail-closed semantics are unchanged).

## Alternatives

1. **SC-side binding→conversation resolution map / new `ensure_channel_case` Contract op.**
   *Rejected* (Support Chat ADR-0011 §Alternatives): new public operation, table, `db_version`
   step, and an ownership inversion, to preserve an indirection with no local benefit. May only
   be introduced by a separate future ADR.
2. **`binding_uuid == support_conversation_uuid` at creation (identifier collapse, option (c)).**
   *Rejected* by Product Owner direction: contradicts shipped `LegacyBindingImportServiceV1` /
   `EnsureChannelCaseService`, forces an ADR-0041 / Support Chat ADR-0009 amendment, removes
   re-keying ability.
3. **Keep `404` as transient (no `unresolved_case_reference` code).** *Rejected*: leaves the
   fail-closed defect — replay can still block forever without a classified outcome if a Support
   Chat conversation is genuinely absent.
4. **Do nothing.** *Rejected*: no real cohort can be handed off; Tier 2 and production cutover
   permanently blocked; `CutoverHandoffIntegrationTest` asserts a non-production condition.

## Consequences

- The cutover-replay handoff, the live inbound operator-reply/command path, and the SC→UT
  outbound delivery path all use `channel_case_ref == Support Chat conversation UUID`
  consistently. F1's `404 → OUTCOME_RETRY_TRANSIENT` dead-end is removed for a correctly-prepared
  cohort; a genuinely unresolvable reference now produces a classified terminal incident instead
  of an infinite retry.
- One new closed incident code (`unresolved_case_reference`). `CutoverIncidentReason::all()` and
  the ADR-0042 §4 vocabulary gain it; the "UT incident never writes an SC map row" structural
  property is preserved for it.
- `CutoverHandoffIntegrationTest` (7 cases) and `CutoverTier1HandoffResolutionTest` are rewritten
  to use real distinct-`binding_uuid` bindings; the "does not resolve" F1 test inverts to "now
  resolves". Adapter outbound/inbound interop and unit suites follow.
- No Universal Telegram schema change. `Migrator::target_version()` stays at `36`.
- DEV rehearsal runbook v2 (a new plan file) adds F1 resolution as a hard precondition before
  Tier 1 is re-attempted. Tier 2 stays blocked on B1, B2, and F1.

## Security and privacy impact

None adverse. `channel_case_ref` stays an opaque content-free UUID; `binding_uuid` stops
crossing the Contract v1 boundary (one fewer cross-system correlatable identifier). The new
`unresolved_case_reference` code is a fixed non-content string on a UT-only incident row that
already carries only ids/uuids/fixed-vocabulary/timestamps. No key handling (ADR-0008),
redaction (ADR-0009), capability (ADR-0010), or webhook-authenticity (ADR-0013) surface is
touched.

## Affected Documents/Milestones

- `docs/adr/0042-support-chat-adr-0010-pin-and-final-cutover-state-machine.md` — §3 and §4/§5
  amended by this ADR (Status-field note on acceptance; body unchanged).
- `docs/adr/README.md` — ADR-0043 row; next available number becomes 0044.
- `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` — new; the
  adapter edit set, fail-closed classification, tests, CI, rollout, Tier 1 gate.
- `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md` — immutable (frozen plan); F1 halt
  and acceptance gate recorded in the closure and the Support Chat decision record; runbook v2
  is an implementation-phase deliverable.
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` — §0d planning note.
- `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`,
  `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md` — referenced, not edited.
- `docs/master-plan.md`, `docs/ARCHITECTURE.md` — cross-reference line if they enumerate the
  SC-M03 handoff contract.

## Compatibility/Migration Impact

- **Schema**: none, either plugin. `Migrator::target_version()` = 36; `db_version` = 11.
- **Data migration**: none required. `channel_bindings.support_conversation_uuid` is already
  `NOT NULL` on every row. `legacy_handoff_map` (SC) and `quiescence_deferred_updates`
  handoff/incident columns (UT) are empty in every environment (cutover never run) — proven, not
  assumed, by a `wp eval` count on DEV before the correction lands.
- **`prepared` bindings**: resolve correctly the instant Universal Telegram sends
  `support_conversation_uuid()`; no per-row action.
- **future `active` bindings**: created with the same `support_conversation_uuid` column
  populated; `EnsureChannelCaseService` returns the conversation UUID as `channel_case_ref` so
  Support Chat stores and echoes the right value; `DeliverMessageService` resolves it by
  conversation UUID. Consistent end-to-end.
- **Wire contract**: `channel_case_ref` name/type/operation-set unchanged; referent becomes the
  Support Chat conversation UUID. No required deploy order between the two repositories.
- **Rollback**: revert the remediation commit; `channel_case_ref` reverts to `binding_uuid` and
  `finish()` reverts to all-non-conflict-transient; no persisted state to unwind.
