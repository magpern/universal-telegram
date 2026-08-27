# SC-M03 Final-Cutover — F1 `channel_case_ref` Identity-Correction Implementation — Closure

## Status

**F1 runtime correction implemented, tested, and merged — 2026-08-27.** ADR-0043 /
Support Chat ADR-0011 (both Accepted 2026-08-27) are now in code. **No DEV, production, or
operational cutover / rehearsal action occurred or is authorized by this work.**

| Repo | PR | Base (pre-merge `main`) | Merge commit |
|---|---|---|---|
| Universal Telegram | [#53](https://github.com/magpern/universal-telegram/pull/53) | `3ae0407916c5d3a0f6acd0ee802a3e45ec0c18ae` | `7d4cc4fecb97f862721cea0fec427ade26b46ea7` |
| Universal Support Chat | [#26](https://github.com/magpern/universal-support-chat/pull/26) | `4c0650db65f0e911ba6422eaf6fc85fc91d26c6b` | `9144cb1e2362c2be8d4c74f1461bba7ffe236575` (comment corrections C1–C4 only) |

## What changed

### Identity (ADR-0043 §1)

Contract v1 `channel_case_ref` is the Support Chat conversation/case UUID
(`ChannelBinding::support_conversation_uuid()`) on every approved path — never the UT-local
`binding_uuid`:

| Path | Change |
|---|---|
| `CutoverReplayDispatcher` | `ingest_operator_reply`, `claim`/`release`/`resolve`/`reopen`, `report_channel_unavailable` send `support_conversation_uuid()` |
| `InboundAdapterBridge::try_handle()` / `handle_command()` | same; `record_ingest_update_id()` still keys on the local `binding_uuid` |
| `EnsureChannelCaseService::ensure()` | returns the conversation UUID as `channel_case_ref` in every branch |
| `DeliverMessageService::deliver()` | resolves the inbound ref via `find_by_conversation_uuid()` |
| `ChannelBinding`, `SupportChatContractClient`, `BackfillService`, `NotifyOperatorsService` docblocks | `binding_uuid` documented as UT-local, never on the wire |

`binding_uuid` remains UT-local for binding lookup, activation CAS, lifecycle, routing, and
idempotency/audit keys — it is **absent from every Contract v1 wire body**. No UUID-equality
rule, no identifier conversion, no new Contract operation, no shared map, no Support Chat-side
resolver or fallback.

### Fail-closed classification (ADR-0043 §3)

New pure classifier `CutoverReplayFailureClassifier`; `CutoverReplayDispatcher::finish()` no
longer has a generic transient fallback. The classification is **exhaustive and fail-closed** —
two new closed `CutoverIncidentReason` codes:

| Contract result | `finish()` outcome |
|---|---|
| `{ok:true}` (incl. target-state convergence) | handed off; `handed_off_at` stamped |
| `404 not_found` | incident **`unresolved_case_reference`** |
| `400 invalid_body` / `invalid_operator` / `unsupported_operation`; `409 already_claimed` / `claimed_by_other` / `invalid_transition`; `sc_contract_unsupported_operation`; **any unrecognised `ok:false` reason** | incident **`handoff_rejected`** |
| `409 handoff_provenance_conflict` | incident `handoff_provenance_conflict` (unchanged) |
| `503 request_failed`; `401 contract_auth_failed`; client not-paired / unavailable / discovery-incompatible / signing-unavailable / transport-failed | `OUTCOME_RETRY_TRANSIENT` — the frozen explicit transient set, the only retryable results |
| caught `\Throwable` | `OUTCOME_RETRY_TRANSIENT`, reported in every replay pass's retryable count |

Both new incidents: preserve the encrypted payload and audit trail, leave `handed_off_at` and
`replayed_at` unset, write **no** Support Chat `legacy_handoff_map` row, and block
`replaying → idle` and `cutover confirm-complete` until resolved by a genuine successful retry
(`incident_resolution = 'retried_success'`) or the existing authority-gated
`cutover incident-acknowledge --po-decision-ref` terminal path. The terminal-acknowledgement
exception was **not** widened or redesigned.

### Support Chat (ADR-0011)

**Comment corrections only** — `Migrator::step_11_create_legacy_handoff_map_table()`,
`HandoffMapRepository`, `ContractOperationDispatcher` class + `resolve_conversation()` docblocks.
`resolve_conversation()` and `dispatch_with_provenance()` behaviour, the `legacy_handoff_map`
shape, and `universal_support_chat_db_version` (11) are unchanged.

## What did NOT change

- No schema change; `Migrator::target_version()` = 36; SC `db_version` = 11.
- No new Contract v1 operation, route, or field; no UUID-equality rule; no SC-side
  binding→conversation resolver, shared map, lookup, or fallback.
- The quiescence state machine, the activation/compensation saga, `cas_version` monotonicity,
  `maybe_mark_topic_unavailable()` ordering, and the terminal-acknowledgement exception.
- No DEV or production quiescence, migration, cohort activation, route switch, cutover,
  deployment, soak, release, tag, rollback, deletion, or retention change.

## Verification

| Suite | Result |
|---|---|
| Per-repo CI on PR #53 (`3ae0407` → `7d4cc4f`) | all jobs green |
| Per-repo CI on PR #26 (`4c0650d` → `9144cb1`) | all jobs green (incl. `docs`) |
| Post-merge dual-plugin interop — merged UT `main` (`7d4cc4f`) + SC F1 branch (`7222d01`), WP 7.1 / PHP 8.3, fresh DB | **OK (47 tests, 722 assertions)** |
| Post-merge dual-plugin interop — same, WP 6.9 / PHP 8.1, fresh DB | **OK (47 tests, 722 assertions)** |
| Universal Telegram — unit (local, pre-merge) | OK (416 tests) |
| Universal Telegram — wp-only integration (local, pre-merge, WP 7.1 / PHP 8.3) | OK (1131 tests) |
| Universal Telegram / Support Chat — phpcs / phpstan | clean / `[OK] No errors` |
| Support Chat — unit / wp-only integration / interop (local, pre-merge) | OK (88 / 122 / 18) |

Key regression tests, all with a **distinct** `binding_uuid` (real bindings from
`LegacyBindingImportServiceV1` / a minted UUID; no equality fixtures except the T11 degenerate
guard):

- `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php` — the F1 characterization
  test, **inverted**: `test_handoff_resolves_a_real_legacy_bind_prepared_binding` now asserts
  `OUTCOME_HANDED_OFF`, one SC message, one handoff-map row keyed by the conversation UUID,
  `handed_off_at` stamped.
- `tests/integration/Interop/CutoverHandoffIntegrationTest.php` — 7 rewritten cases + new
  `test_unresolvable_conversation_uuid_becomes_unresolved_case_reference_incident`,
  `test_deterministic_sc_refusal_becomes_handoff_rejected_incident`,
  `test_wire_channel_case_ref_is_the_conversation_uuid_never_the_binding_uuid`.
- `tests/unit/Migration/CutoverReplayFailureClassifierTest.php` — exhaustive `(status, reason)`
  table, totality guard, and "a novel reason never becomes retryable".

## Ordered merge

1. Universal Telegram PR #53 CI green → merged first (`3ae0407` → `7d4cc4f`).
2. UT `origin/main` fetched fresh at `7d4cc4f`; its tree confirmed identical to the reviewed
   branch tip.
3. The dual-plugin interop suite was re-run against merged UT `main` (`7d4cc4f`) plus the
   Support Chat PR #26 branch (`7222d015964f6d7e3573d9af6f259099db44f9eb`), both supported
   WP/PHP variants, fresh disposable database each run — **OK (47 tests, 722 assertions)** each.
4. Only then was Support Chat PR #26 merged (`4c0650d` → `9144cb1`).
5. SC `origin/main` fetched fresh at `9144cb1`.

## Deployment note (not performed here)

Per the frozen remediation plan §3: before this correction is deployed to any environment,
confirm by `wp eval` that `legacy_handoff_map` (SC) and `quiescence_deferred_updates`
handoff/incident columns (UT) are empty (expected: cutover has never run) and that no live
binding has an in-flight Support Chat-side `channel_case_ref` that would dangle. This closure
performs **no** deployment; that remains a separate, later action and is **not** authorized by
the F1 implementation acceptance.

## Rehearsal status — unchanged

- **Tier 1** remains **unexecuted** ("attempted 2026-08-27 → halted by F1"). It **cannot be
  accepted** until a Tier 1 re-attempt is run and its real-binding handoff path passes. A Tier 1
  re-attempt requires a **separate Approval A addendum** from the Product Owner and runs only
  under DEV rehearsal runbook **v2** (not written by this task).
- **Tier 2** remains **unexecuted and blocked on B1** (isolated full-WordPress instance) **and
  B2** (dedicated non-production Telegram bot / supergroup / topics). Approval B is unchanged.
- No acceptance record for Tier 1 or Tier 2 is created by this work.

## Next authorized step

Draft and freeze DEV rehearsal runbook **v2** plus a separate Tier 1 Approval A addendum. Do
**not** execute Tier 1. Nothing further is authorized.
