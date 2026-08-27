# SC-M03 Final-Cutover — F1 `channel_case_ref` Identity-Correction Implementation — Closure

## Status

**F1 runtime correction implemented, tested, and merged — 2026-08-27.** ADR-0043 /
Support Chat ADR-0011 (both Accepted 2026-08-27) are now in code. **No DEV, production, or
operational cutover / rehearsal action occurred or is authorized by this work.**

- **Universal Telegram** — PR #53, merged `__UT_MERGE_SHA__`.
- **Universal Support Chat** — PR #26, merged `__SC_MERGE_SHA__` (comment corrections C1–C4 only).

## What changed

### Identity (ADR-0043 §1)

Contract v1 `channel_case_ref` is the Support Chat conversation/case UUID
(`ChannelBinding::support_conversation_uuid()`) on every relevant path — never the UT-local
`binding_uuid`:

| Path | Change |
|---|---|
| `CutoverReplayDispatcher` | `ingest_operator_reply`, `claim`/`release`/`resolve`/`reopen`, `report_channel_unavailable` send `support_conversation_uuid()` |
| `InboundAdapterBridge::try_handle()` / `handle_command()` | same; `record_ingest_update_id()` still keys on the local `binding_uuid` |
| `EnsureChannelCaseService::ensure()` | returns the conversation UUID as `channel_case_ref` in every branch |
| `DeliverMessageService::deliver()` | resolves the inbound ref via `find_by_conversation_uuid()` |
| `ChannelBinding`, `SupportChatContractClient`, `BackfillService`, `NotifyOperatorsService` docblocks | `binding_uuid` documented as UT-local, never on the wire |

`binding_uuid` remains UT-local for binding lookup, activation CAS, lifecycle, routing, and
idempotency/audit keys. No UUID-equality assumption, no identifier conversion, no new Contract
operation, no shared map, no Support Chat fallback.

### Fail-closed classification (ADR-0043 §3)

New pure classifier `CutoverReplayFailureClassifier`; `CutoverReplayDispatcher::finish()` no
longer has a generic transient fallback. Two new closed `CutoverIncidentReason` codes:

| Contract result | `finish()` outcome |
|---|---|
| `{ok:true}` (incl. target-state convergence) | handed off; `handed_off_at` stamped |
| `404 not_found` | incident **`unresolved_case_reference`** |
| `400 invalid_body` / `invalid_operator` / `unsupported_operation`; `409 already_claimed` / `claimed_by_other` / `invalid_transition`; `sc_contract_unsupported_operation`; any unrecognised `ok:false` reason | incident **`handoff_rejected`** |
| `409 handoff_provenance_conflict` | incident `handoff_provenance_conflict` (unchanged) |
| `503 request_failed`; `401 contract_auth_failed`; client not-paired / unavailable / discovery-incompatible / signing-unavailable / transport-failed | `OUTCOME_RETRY_TRANSIENT` |
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
- No new Contract v1 operation, route, or field.
- No SC-side binding→conversation resolver, map, lookup, or fallback.
- The quiescence state machine, the activation/compensation saga, `cas_version` monotonicity,
  `maybe_mark_topic_unavailable()` ordering, and the terminal-acknowledgement exception.
- No DEV or production quiescence, migration, cohort activation, route switch, cutover,
  deployment, soak, release, tag, rollback, deletion, or retention change.

## Verification

| Suite | Result |
|---|---|
| Universal Telegram — dual-plugin interop (`phpunit-interop.xml.dist`), wp7.1/php8.3 | **OK (47 tests, 722 assertions)** |
| Universal Telegram — dual-plugin interop, wp6.9/php8.1 (fresh DB) | **OK (47 tests, 722 assertions)** |
| Universal Telegram — unit | OK (416 tests) |
| Universal Telegram — wp-only integration, wp7.1/php8.3 | OK (1131 tests) |
| Universal Telegram — phpcs / phpstan | clean / `[OK] No errors` |
| Support Chat — unit | OK (88 tests) |
| Support Chat — wp-only integration, wp7.1/php8.3 | OK (122 tests) |
| Support Chat — dual-plugin interop (against the Universal Telegram F1 branch) | OK (18 tests) |
| Support Chat — phpcs / phpstan | clean / `[OK] No errors` |
| Per-repo CI on PR #53 / #26 | green (see PRs) |
| Ordered-merge re-verification (interop against merged Universal Telegram `main`) | **OK (47 tests)** — see below |

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

## Ordered-merge

Universal Telegram PR #53 merged first (`__UT_MERGE_SHA__`). The dual-plugin interop suite was
then re-run from a fresh checkout of merged Universal Telegram `main` plus the Support Chat F1
branch — **OK (47 tests)** — and only then was Support Chat PR #26 merged (`__SC_MERGE_SHA__`).

## Deployment note (not performed here)

Per the frozen remediation plan §3 (A-F1-1 / A-F1-2): before this correction is deployed to any
environment, confirm by `wp eval` that `legacy_handoff_map` (SC) and
`quiescence_deferred_updates` handoff/incident columns (UT) are empty (expected: cutover has
never run) and that no live binding has an in-flight Support Chat-side `channel_case_ref` that
would dangle. This closure performs **no** deployment; that remains a separate, later action and
is **not** authorized by the F1 implementation acceptance.

## Rehearsal status — unchanged

- **Tier 1** remains **unexecuted** ("attempted 2026-08-27 → halted by F1"). It **cannot be
  accepted** until a Tier 1 re-attempt is run and its real-binding handoff path passes. A Tier 1
  re-attempt requires a **separate Approval A addendum** from the Product Owner and runs only
  under DEV rehearsal runbook v2 (not written by this task).
- **Tier 2** remains **unexecuted and blocked on B1** (isolated full-WordPress instance) **and
  B2** (dedicated non-production Telegram bot / supergroup / topics). Approval B is unchanged.
- No acceptance record for Tier 1 or Tier 2 is created by this work.

## Next authorized step

A separately approved Tier 1 re-attempt under DEV rehearsal runbook v2 — nothing further. The
runbook v2 and any Tier 1 re-attempt each require their own Product Owner authorization.
