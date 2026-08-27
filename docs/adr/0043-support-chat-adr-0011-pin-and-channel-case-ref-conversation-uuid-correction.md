# ADR-0043: Support Chat ADR-0011 pin and `channel_case_ref` = conversation UUID adapter correction (F1)

## Status

**Proposed** — awaiting Product Owner review. Documentation-only; no code, schema, or
`Migrator::target_version()` change is made by this ADR. The adapter-side code change it
specifies is executed only under the separately-reviewed remediation plan
`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` after acceptance.

Pins Support Chat **ADR-0011** ("`channel_case_ref` is the Support Chat conversation UUID"),
`universal-support-chat` commit to be recorded on acceptance. Mirrors the ADR-0042↔ADR-0010
relationship.

On acceptance, ADR-0042's Status field gains "§ handoff `channel_case_ref` sender semantics
corrected by ADR-0043" (Status-field-only change per the immutability rule).

## Context

The SC-M03 final-cutover disposable DEV rehearsal Tier 1 prerequisite validation
(`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`) halted at the UT→SC
deferred-update handoff phase by **finding F1**: the Contract v1 wire field `channel_case_ref`
is resolved by Support Chat as its own `conversation_uuid`
(`ContractOperationDispatcher::resolve_conversation()` → `conversations->find_by_uuid()`), but
every Universal Telegram adapter call site sends `$binding->binding_uuid()`, and every real
binding-creation path mints a fresh `binding_uuid` unrelated to the conversation UUID:

- `src/SupportChatAdapter/Migration/LegacyBindingImportServiceV1.php` — `import_one()` calls
  `$this->bindings->create( wp_generate_uuid4(), (string) $candidate['support_conversation_uuid'], … STATUS_PREPARED )`.
- `src/SupportChatAdapter/Outbound/EnsureChannelCaseService.php:139` — `$binding_uuid = wp_generate_uuid4();`.

Call sites that send `binding_uuid` as `channel_case_ref` today:

| File | Lines | Path |
|---|---|---|
| `src/Migration/CutoverReplayDispatcher.php` | 135, 189-192, 214 | cutover-replay handoff (`ingest_operator_reply`, `claim`/`release`/`resolve`/`reopen`, `report_channel_unavailable`) |
| `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php` | 118-119, 134, 144, 157-165 | live inbound operator reply + `/claim` `/release` `/resolve` `/reopen` |
| `src/SupportChatAdapter/Inbound/SupportChatContractClient.php` | 70-215 (param docs) | the client that puts the value in the request body as `'channel_case_ref' => $channel_case_ref` |
| `src/SupportChatAdapter/Outbound/EnsureChannelCaseService.php` | 62, 70, 119, 156, 168 | the `ensure` response's `channel_case_ref` it hands back to Support Chat |

The outbound (SC→UT) direction is internally consistent *today* only because Support Chat echoes
back exactly the `channel_case_ref` string `EnsureChannelCaseService` gave it, and
`DeliverMessageService::deliver()` (`:67`) resolves it with `find_by_uuid( $channel_case_ref )`
— i.e. Universal Telegram resolves the same wire field as *its own binding UUID*. So the one
wire field `channel_case_ref` means "UT binding UUID" to UT and "SC conversation UUID" to SC.
It only works when the two are seeded equal, as `CutoverHandoffIntegrationTest` and the adapter
interop suites do. F1 is the cutover path hitting this first with a real, distinct
`binding_uuid`.

The Product Owner has directed: adopt the alternative where `channel_case_ref` carries the
Support Chat conversation UUID (Support Chat ADR-0011 option (b)); reject collapsing the two
identifiers (option (c)); confirm the exact stored field/source mapping in ADR review before
code (done below); do not authorize Tier 2 or accept Tier 1; produce this documentation-only
correction and implementation plan as the next step.

## Decision

**Universal Telegram sends `$binding->support_conversation_uuid()` as `channel_case_ref` on
every adapter→Support Chat call, and resolves an inbound `channel_case_ref` by conversation
UUID.** `binding_uuid` is retained exclusively as Universal Telegram's private binding-row
identity and never crosses the Contract v1 boundary.

Confirmed field/source mapping (read at UT `31519ee` / SC `ce46912`, this ADR's research):

| Concern | Field / method | Populated from | After ADR-0043 |
|---|---|---|---|
| SC conversation identity, stored per binding | `channel_bindings.support_conversation_uuid`, `ChannelBinding::support_conversation_uuid()` | `create( …, $support_conversation_uuid, … )` — from `$candidate['support_conversation_uuid']` (cutover) or the `ensure` request's `conversation_uuid` (live) | **the value sent as `channel_case_ref`** |
| UT binding-row identity | `channel_bindings.binding_uuid`, `ChannelBinding::binding_uuid()` | `wp_generate_uuid4()` at create time | UT-internal only: `activate_prepared`/`revert_activation` CAS, `find_by_uuid`, `record_ingest_update_id`, `record_delivered_key`, `EnsureIdempotencyKey`, audit rows |
| Inbound resolution (SC→UT deliver/notify/backfill) | `DeliverMessageService::deliver()` `find_by_uuid( $channel_case_ref )` | — | `find_by_conversation_uuid( $channel_case_ref )` |
| `ensure` response ref | `EnsureChannelCaseService::ensure()` return `channel_case_ref` (all 6 branches) | `$binding->binding_uuid()` / `$race->binding_uuid()` | `$conversation_uuid` (equivalently `$binding->support_conversation_uuid()`) |

`InboundAdapterBridge`'s `record_ingest_update_id()` / `record_delivered_key()` /
`ChannelBindingRepository::activate_prepared()` etc. keep using `binding_uuid` — those are
local-row operations, not wire operations.

The concrete edit set, test impact, rollout verification against the deployed adapter state, and
CI plan are in `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`.

## Alternatives

1. **SC-side binding→conversation resolution map + new `ensure_channel_case` Contract op.**
   *Rejected* (Support Chat ADR-0011 §Alternatives): new public operation, new table, new
   `db_version` step, to preserve an indirection with no local benefit — UT already holds
   `support_conversation_uuid` on every binding.
2. **`binding_uuid == support_conversation_uuid` at creation (collapse the identifiers).**
   *Rejected* by Product Owner direction: contradicts shipped `LegacyBindingImportServiceV1` /
   `EnsureChannelCaseService` behaviour, forces an ADR-0041/ADR-0009 amendment, and removes the
   ability to re-key a binding without disturbing the SC-facing reference.
3. **Keep sending `binding_uuid`; change Support Chat to resolve by binding UUID.** *Rejected*:
   Support Chat has no binding table and no reason to hold one; this pushes adapter-internal
   identity into the canonical contract, the opposite of the ADR-0037 boundary.
4. **Do nothing.** *Rejected*: no real cohort can be handed off; Tier 2 and production cutover
   permanently blocked; `CutoverHandoffIntegrationTest` asserts a non-production condition.

## Consequences

- The cutover-replay handoff, the live inbound operator-reply/command path, and the SC→UT
  outbound delivery path all use `channel_case_ref == SC conversation UUID` consistently. F1's
  `404 not_found` → `OUTCOME_RETRY_TRANSIENT` dead-end is removed; a real `legacy-bind` cohort's
  replayed rows can be handed off; `replaying → idle` and `confirm-complete` become reachable.
- `CutoverReplayDispatcher::finish()`'s incident classification is unchanged — a genuine
  `handoff_provenance_conflict` still becomes an incident; a real `404` now cannot occur for a
  correctly-prepared cohort, and if it does (SC conversation genuinely missing) it remains a
  transient-retry, which is the correct fail-safe.
- `CutoverHandoffIntegrationTest` (7 cases, `tests/integration/Interop/`) and
  `CutoverTier1HandoffResolutionTest` (the merged F1 characterization test) are rewritten to
  assert the corrected mapping with a **distinct** `binding_uuid`; the "does not resolve" F1
  test inverts to "now resolves". Adapter outbound/inbound interop and unit suites follow.
- No Universal Telegram schema change. `Migrator::target_version()` stays at `36`.
- The DEV rehearsal runbook is revised to v2 (F1 resolution as a hard precondition) before Tier 1
  is re-attempted. Tier 2 stays blocked on B1, B2, and F1.

## Security and privacy impact

None. `channel_case_ref` stays an opaque content-free UUID; only *which* opaque UUID changes.
`binding_uuid` no longer leaves Universal Telegram, marginally reducing cross-system
correlatable identifiers. No key handling, redaction, capability, or webhook-authenticity
boundary (ADR-0008/0009/0010/0013) is touched. `legacy_handoff_map` and the
`quiescence_deferred_updates` incident columns still carry only ids/uuids/fixed
vocabulary/timestamps.

## Affected Documents/Milestones

- `docs/adr/0042-support-chat-adr-0010-pin-and-final-cutover-state-machine.md` — handoff
  `channel_case_ref` sender semantics corrected by this ADR (Status-field note on acceptance).
- `docs/adr/README.md` — ADR-0043 row; next available number becomes 0044.
- `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` — new; the
  adapter edit set, tests, CI, rollout.
- `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md` — F1 note; runbook v2 pending.
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` — §0d planning note.
- `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` — the finding this ADR
  resolves (referenced, not edited).
- `docs/master-plan.md`, `docs/ARCHITECTURE.md` — cross-reference line if they enumerate the
  SC-M03 handoff contract.

## Compatibility/Migration Impact

- **Schema**: none, either plugin.
- **Wire contract**: `channel_case_ref` name/type/operation-set unchanged; referent changes to
  the SC conversation UUID. Support Chat already resolves it that way, so a corrected Universal
  Telegram talking to an unchanged Support Chat is *more* correct, not broken; an uncorrected
  Universal Telegram talking to a corrected Support Chat is exactly today's behaviour. No unsafe
  intermediate state.
- **Deployed bindings**: every existing `channel_bindings` row already stores
  `support_conversation_uuid`; nothing to backfill. The remediation plan verifies against the
  deployed adapter state that no live path has persisted a `binding_uuid` *as* an SC-side
  reference that would now dangle (expected: none, because SC only ever stored the ref transiently
  per request and `legacy_handoff_map` is empty).
- **Rollout**: Support Chat ADR-0011's comment corrections and this ADR's adapter change may
  merge in either order; neither reaches production before Product Owner acceptance of both ADRs
  and both remediation plans, and before DEV rehearsal runbook v2.
- **Rollback**: revert the remediation commit; `channel_case_ref` reverts to `binding_uuid`;
  no persisted state to unwind (`legacy_handoff_map` empty; cutover never run).
