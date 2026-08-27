# SC-M03 Final-Cutover — F1 `channel_case_ref` remediation plan v1 (Universal Telegram, primary)

**Status: Proposed — awaiting Product Owner review. Documentation-only. No code, schema, test,
CLI, or release change is made by committing this plan.** Implementation begins only after
Product Owner acceptance of ADR-0043 and Support Chat ADR-0011, and produces its own
implementation report citing this plan's freeze SHA.

## 1. Milestone charter and ADRs

- Milestone: UT Adapter M1, §0d (final-cutover follow-up, ADR-0042) — remediation of a defect
  found by the SC-M03 final-cutover disposable DEV rehearsal Tier 1 prerequisite validation.
- Introduces / relies on: **ADR-0043** (this repo — pins Support Chat ADR-0011; owns the
  adapter-side wire change) and **Support Chat ADR-0011** (`channel_case_ref` is the Support
  Chat conversation UUID). Relies unchanged on ADR-0037 (adapter boundary), ADR-0042 / Support
  Chat ADR-0010 (cutover state machine, handoff contract), ADR-0007 (Contract v1 auth).
- Finding of record: `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` (finding
  F1), and the merged characterization test
  `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php`.

## 2. Repository findings at plan-drafting time (UT `31519ee`, SC `ce46912`)

`channel_case_ref` is sent as `$binding->binding_uuid()` and resolved by Support Chat as its own
`conversation_uuid`. Every real binding mints an independent `binding_uuid`
(`LegacyBindingImportServiceV1::import_one()`, `EnsureChannelCaseService.php:139`). Interop and
cutover-handoff tests only pass because they seed `binding_uuid == conversation_uuid`.

Sites that send `binding_uuid` on the wire as `channel_case_ref`:

| # | File | Lines | Operation(s) |
|---|---|---|---|
| S1 | `src/Migration/CutoverReplayDispatcher.php` | 135 | `ingest_operator_reply` (cutover replay) |
| S2 | `src/Migration/CutoverReplayDispatcher.php` | 189-192 | `claim` / `release` / `resolve` / `reopen` (cutover replay) |
| S3 | `src/Migration/CutoverReplayDispatcher.php` | 214 | `report_channel_unavailable` (cutover replay) |
| S4 | `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php` | 118, 134, 144 | live inbound `ingest_operator_reply` |
| S5 | `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php` | 157-165 (`handle_command`) | live inbound `claim` / `release` / `resolve` / `reopen` |
| S6 | `src/SupportChatAdapter/Outbound/EnsureChannelCaseService.php` | 62, 70, 119, 156, 168 | the `ensure` response's `channel_case_ref` returned to Support Chat |
| S7 | `src/SupportChatAdapter/Inbound/SupportChatContractClient.php` | 70-215 | param docs "Opaque binding UUID" (documentation only) |

Sites that resolve an inbound `channel_case_ref` as UT's own `binding_uuid`:

| # | File | Line | Note |
|---|---|---|---|
| R1 | `src/SupportChatAdapter/Outbound/DeliverMessageService.php` | 67 | `$this->bindings->find_by_uuid( $channel_case_ref )` — SC→UT deliver / notify / backfill |

Local-row `binding_uuid` uses that **stay** (not wire): `activate_prepared` / `revert_activation`
(`ChannelBindingRepository`), `record_ingest_update_id`, `record_delivered_key`,
`find_by_ensure_key`, all `cutover_*` audit rows, `EnsureIdempotencyKey`.

Repository already provides `ChannelBindingRepository::find_by_conversation_uuid( string )`
(`:54`) and `ChannelBinding::support_conversation_uuid()` — no new method needed.

## 3. Assumptions and open questions (separate from decisions)

- **A-F1-1**: No production environment has run the final cutover, so `legacy_handoff_map` (SC)
  and `quiescence_deferred_updates.handed_off_at` (UT) are empty everywhere. *Verify* on DEV
  before implementation lands: `wp eval` count on both.
- **A-F1-2**: The live inbound adapter path (`InboundAdapterBridge::try_handle`) has not been
  exercised in production against a real distinct-`binding_uuid` binding (or has only worked
  where the two UUIDs happened to match). *Verify* against the deployed adapter state: are there
  `channel_bindings` rows where `binding_uuid != support_conversation_uuid` that have a non-null
  `last_ingest_update_id`? If yes, document the reconciliation (SC side stored nothing durable;
  no action needed) in the implementation report.
- **A-F1-3**: `CHAR(36)` holds a v4 conversation UUID with no truncation (36 chars exactly) —
  confirmed by inspection of `Migrator.php:833`; no DDL change. Open question deferred to Support
  Chat ADR-0011's plan, not this one.
- **A-F1-4**: No other consumer of `EnsureChannelCaseService`'s return `channel_case_ref` treats
  it as a binding UUID beyond `OutboundContractController` passthrough. *Verify* by grep at
  implementation time.

## 4. Architectural decisions (cite ADRs)

Per ADR-0043 (Decision) and Support Chat ADR-0011 (Decision, option (b); option (c) rejected by
Product Owner direction):

1. **Send `$binding->support_conversation_uuid()` as `channel_case_ref`** at S1–S6. For the
   cutover-replay dispatcher, `$binding` is already in scope; for `InboundAdapterBridge`, replace
   the `$binding->binding_uuid()` argument to `$this->sc_client->*` with
   `$binding->support_conversation_uuid()` while keeping `$binding->binding_uuid()` for
   `record_ingest_update_id()`.
2. **Resolve an inbound `channel_case_ref` by conversation UUID** at R1:
   `find_by_uuid()` → `find_by_conversation_uuid()`. All subsequent local-row writes in
   `DeliverMessageService` continue to key on `$binding->binding_uuid()` (they operate on the
   resolved object).
3. **`EnsureChannelCaseService::ensure()` returns the conversation UUID** as `channel_case_ref`
   in all six return branches (`created`, `reused`, and the "already exists" fast paths). The
   value is `$conversation_uuid` (the method argument), identical to
   `$binding->support_conversation_uuid()`.
4. **Update `SupportChatContractClient` and `ChannelBinding` doc comments** — "Opaque binding
   UUID" → "Support Chat conversation UUID (see ADR-0043 / Support Chat ADR-0011)";
   `ChannelBinding::binding_uuid()` docblock → "UT-internal binding-row identity; never sent on
   the Contract v1 wire".
5. **No schema change.** `Migrator::target_version()` stays `36`. No new migration step.
6. **No new Contract v1 operation, route, or field.** Discovery output unchanged.

Alternatives and their rejection: ADR-0043 §Alternatives (SC resolution map + new op; collapse
identifiers; resolve-by-binding on SC; do nothing).

## 5. Directory, namespace, schema, API impact (scoped)

- **Changed files** (≈7 source, all under `src/`): the S1–S7 + R1 files above. No new file, no
  namespace change.
- **Schema**: none.
- **Public API / wire**: `channel_case_ref` referent only; name/shape/operations unchanged.
- **CLI**: none (`cutover` / `quiescence` command surfaces unchanged).
- **Config / options**: none.

## 6. Security and privacy impact

Per ADR-0043 §Security and privacy impact: none adverse. `binding_uuid` stops crossing the
Contract v1 boundary (one fewer cross-system correlatable identifier). No key handling,
redaction (ADR-0009), capability (ADR-0010 UT), or webhook-authenticity (ADR-0013) surface is
touched. phpcs privacy-classification rules unaffected — no new logged field.

## 7. Test and CI impact

- **Rewrite** `tests/integration/Interop/CutoverHandoffIntegrationTest.php` (7 cases): create
  the binding with a **distinct** `binding_uuid` (`wp_generate_uuid4()`) and the SC conversation
  with its own UUID; assert the handoff resolves via `support_conversation_uuid`; assert the
  `legacy_handoff_map` row's `channel_case_ref` equals the SC conversation UUID (not
  `binding_uuid`).
- **Invert** `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php`:
  `test_handoff_does_not_resolve_a_real_legacy_bind_prepared_binding` becomes
  `test_handoff_resolves_a_real_legacy_bind_prepared_binding` — same real setup (real
  `legacy-bind` import → `activate_prepared` → buffered reply → `dispatch`), now asserting
  `OUTCOME_HANDED_OFF`, `handed_off_at` set, one `legacy_handoff_map` row, one SC message. Keep
  `test_handoff_succeeds_when_binding_uuid_equals_conversation_uuid` as a degenerate-case guard.
- **Adapter interop**: outbound (`DeliverMessageService` / `OutboundContractController`) and
  inbound (`InboundAdapterBridge`) interop tests updated to distinct-UUID bindings; assert R1
  resolves by conversation UUID.
- **Unit**: `EnsureChannelCaseServiceTest`, `InboundAdapterBridgeTest`,
  `CutoverReplayDispatcherTest`, `DeliverMessageServiceTest` — update expected `channel_case_ref`
  argument/return.
- **CI**: existing `ci.yml` jobs (phpcs, static-analysis, unit 8.1/8.3/8.4,
  integration-wp-only-{floor,current}, integration-wc-present-current, js-behavioural, build,
  package-acceptance) must stay green. The interop suite is not a CI job in this repo (as noted
  in the Tier 1 closure) — it is run locally via `bin/docker/test-integration-interop.sh` and
  the result attached to the implementation report, exactly as the Tier 1 evidence was.
- **No new CI job** is added by this plan.

## 8. Work packages in execution order

1. **WP-F1-1 (docs, this commit)** — ADR-0043, ADR-0011 (SC), this plan, the SC remediation
   plan, registry/milestone updates, PO decision item 7. Merge after doc CI green. **No code.**
2. **WP-F1-2 (Support Chat)** — comment corrections in `Migrator.php`, `HandoffMapRepository`,
   `ContractOperationDispatcher` docblocks; interop fixture alignment. Support Chat plan owns it.
   No `db_version` bump.
3. **WP-F1-3 (Universal Telegram, adapter send)** — S1–S6 wire change + S7/`ChannelBinding` doc
   comments. Unit + adapter interop tests updated.
4. **WP-F1-4 (Universal Telegram, inbound resolve)** — R1 `find_by_conversation_uuid`; outbound
   interop + unit tests updated.
5. **WP-F1-5 (cutover-handoff tests)** — rewrite `CutoverHandoffIntegrationTest`; invert
   `CutoverTier1HandoffResolutionTest`. Run the full interop suite locally; attach evidence.
6. **WP-F1-6 (DEV rehearsal runbook v2)** — new
   `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md` (+ SC companion v2) adding F1
   resolution as a hard precondition and updating the §1.3 / §2 "wire detail" note from
   "fixture-seeding nuance" to "resolved by ADR-0043 / ADR-0011". Supersedes v1.
7. **WP-F1-7** — implementation report citing this plan's freeze SHA; Tier 1 re-attempt is a
   *separate* Product-Owner-authorized step (new Approval A addendum), not part of this plan.

WP-F1-3 and WP-F1-4 may land in one PR. WP-F1-2 is an independent Support Chat PR. Order
WP-F1-2/3/4 freely; do not merge any to a release branch or deploy before Product Owner
acceptance of both ADRs.

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A live binding somewhere has `binding_uuid != support_conversation_uuid` and an in-flight SC-side reference | A-F1-1/A-F1-2 verification on DEV before merge; SC stores no durable `channel_case_ref` outside `legacy_handoff_map` (empty), so worst case is one retriable failed delivery that succeeds on retry after deploy |
| `CutoverHandoffIntegrationTest` rewrite masks a real regression | Keep the degenerate `binding_uuid == conversation_uuid` case as an explicit separate test; add an assertion that the wire value ≠ `binding_uuid` in the primary cases |
| Someone treats Tier 1 re-attempt as authorized by this plan | Plan and ADR both state Tier 1 re-attempt needs a separate Approval A addendum; no acceptance record is created here |
| Scope creep into an `ensure_channel_case` Contract operation | ADR-0043 §Alternatives explicitly rejects it; this plan changes no Contract operation set |

## 10. Out of scope

- Any production or DEV cutover, quiescence window, migration, cohort activation, route switch,
  soak, deployment, release, tag, or rollback.
- Executing (or re-executing) Tier 1 or Tier 2 of the DEV rehearsal.
- Any Support Chat schema change or `db_version` bump.
- A new Contract v1 operation, route, field, or `ensure_channel_case` service.
- Collapsing `binding_uuid` and `support_conversation_uuid` (option (c), rejected).
- Changing `CutoverReplayDispatcher::finish()` incident classification, the quiescence state
  machine, the activation/compensation saga, or `cas_version` monotonicity.
- Any change to `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` (immutable
  record of the finding).

## 11. Definition of done

- ADR-0043 and Support Chat ADR-0011 accepted by the Product Owner.
- `channel_case_ref` carries the SC conversation UUID at every send site (S1–S6) and is resolved
  by conversation UUID at R1; `binding_uuid` appears in no Contract v1 request/response body.
- `CutoverHandoffIntegrationTest` (7 cases) and `CutoverTier1HandoffResolutionTest` pass with a
  **distinct** `binding_uuid`; the full local interop suite is green on both WP/PHP variants and
  the result is attached to the implementation report.
- All `ci.yml` jobs green.
- `Migrator::target_version()` unchanged (36); no new migration step.
- DEV rehearsal runbook v2 committed, superseding v1, with F1 resolution as a hard precondition.
- Implementation report cites this plan's freeze SHA.
- **No acceptance record for Tier 1 or Tier 2 is created by this work** — that remains a later,
  separate Product Owner action. Tier 2 stays blocked on B1, B2, and (until this plan is
  implemented and the runbook revised) F1.
