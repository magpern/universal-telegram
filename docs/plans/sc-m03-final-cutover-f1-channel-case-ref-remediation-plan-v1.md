# SC-M03 Final-Cutover — F1 `channel_case_ref` identity-correction plan v1 (Universal Telegram, primary)

**Status: Proposed — awaiting Product Owner review. Documentation-only. No code, schema, test,
CLI, version, or release change is made by committing this plan.** Implementation begins only
after Product Owner acceptance of **ADR-0043** and **Support Chat ADR-0011**, and produces its
own implementation report citing this plan's freeze SHA. This freeze adds **no** Product Owner
implementation acceptance — that is a separate later action (acceptance text in §12).

## 1. Milestone charter and ADRs

- Milestone: UT Adapter M1, §0d (final-cutover follow-up, ADR-0042) — remediation of a
  production defect (F1) surfaced by the SC-M03 final-cutover disposable DEV rehearsal Tier 1
  prerequisite validation.
- Introduces / relies on: **ADR-0043** (this repo — pins Support Chat ADR-0011; adapter-side
  wire identity + `CutoverReplayDispatcher` fail-closed classification) and **Support Chat
  ADR-0011** (`channel_case_ref` = Support Chat conversation UUID; provenance-map + fail-closed
  semantics). Amends ADR-0042 §3 and §4/§5, and (SC) ADR-0010 §4 — via the new ADRs, not by
  editing the originals. Relies unchanged on ADR-0037, ADR-0040, ADR-0007.
- Finding of record: `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`
  (finding F1), merge `98c602543bd67bc471e2a88468d175fb6e659b46`; Support Chat closure merge
  `fcbfaa773ef63661b6d8ce42962f10bb174588f8`; characterization test
  `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php`.

## 2. Repository findings at plan-drafting time (UT `31519ee3ae297369118bf2deda6eae05d13a3d8b`, SC `ce4691241eb843485117b323516899df916fdaf7`)

### 2.1 The authoritative UT field

**`ChannelBinding::support_conversation_uuid()`** — column
`wp_universal_telegram_support_chat_bindings.support_conversation_uuid`, `CHAR(36) NOT NULL`,
`UNIQUE KEY support_conversation_uuid`. Populated from the Support Chat conversation UUID by
**both** creation paths:

- `LegacyBindingImportServiceV1::import_one()` → `bindings->create( wp_generate_uuid4(),
  (string) $candidate['support_conversation_uuid'], … STATUS_PREPARED )` — value from Support
  Chat WP5 `legacy-bind run`.
- `EnsureChannelCaseService::ensure( $conversation_uuid, … )` → `create( wp_generate_uuid4(),
  $conversation_uuid, … )` — value from the Support Chat `ensure` request.

`ChannelBindingRepository::find_by_conversation_uuid()` already queries this column. **No new
field, no ambiguity, no design fiction.**

### 2.2 Send sites (send `binding_uuid` today; must send `support_conversation_uuid()`)

| # | File | Lines | Operation(s) |
|---|---|---|---|
| S1 | `src/Migration/CutoverReplayDispatcher.php` | 135 | cutover-replay `ingest_operator_reply` |
| S2 | `src/Migration/CutoverReplayDispatcher.php` | 189-192 | cutover-replay `claim` / `release` / `resolve` / `reopen` |
| S3 | `src/Migration/CutoverReplayDispatcher.php` | 214 | cutover-replay `report_channel_unavailable` |
| S4 | `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php` | 118, 134, 144 | live `ingest_operator_reply` |
| S5 | `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php` | 157-165 (`handle_command`) | live `claim` / `release` / `resolve` / `reopen` |
| S6 | `src/SupportChatAdapter/Outbound/EnsureChannelCaseService.php` | 62, 70, 119, 156, 168 | the `ensure` response `channel_case_ref` returned to Support Chat (all branches) |
| S7 | `src/SupportChatAdapter/Inbound/SupportChatContractClient.php` | 70-215 (param docs) | rename/redocument `channel_case_ref` (documentation) |

`binding_uuid` **stays** at: `activate_prepared`/`revert_activation` (`ChannelBindingRepository`),
`record_ingest_update_id`, `record_delivered_key`, `find_by_uuid`, `find_by_ensure_key`, all
`cutover_*` and `…_bindings_audit` rows, every idempotency key. These are local-row operations,
not wire operations.

### 2.3 Resolve site (resolves an inbound ref as UT's own `binding_uuid` today)

| # | File | Line | Change |
|---|---|---|---|
| R1 | `src/SupportChatAdapter/Outbound/DeliverMessageService.php` | 67 | `find_by_uuid( $channel_case_ref )` → `find_by_conversation_uuid( $channel_case_ref )` |

`OutboundContractController` handlers (`handle_notify`/`handle_backfill`/`handle_deliver`) pass
`channel_case_ref` straight through to `DeliverMessageService` — no change there beyond the
resolution in R1.

### 2.4 The fail-closed classification gap

`CutoverReplayDispatcher::finish()` (`src/Migration/CutoverReplayDispatcher.php:232-244`) maps
every non-`handoff_provenance_conflict` failure — **including `404 not_found`** — to
`OUTCOME_RETRY_TRANSIENT`. After an active binding is selected (the caller has already done so;
this class has no legacy fallthrough), an unresolvable `channel_case_ref` is not transient. Left
as-is it is a silent infinite retry that blocks `replaying → idle` and `confirm-complete` with
no classified outcome.

### 2.5 Support Chat side

`ContractOperationDispatcher::resolve_conversation()` already resolves `channel_case_ref` as the
Support Chat `conversation_uuid` and returns `null` (→ handler `404 not_found`) for a malformed
or unknown ref. `dispatch_with_provenance()` is already passed `$conversation->uuid()`, so the
`legacy_handoff_map.channel_case_ref` column and the `409` conflict check already operate on the
conversation UUID. **No Support Chat runtime change is required** — only comment corrections
(Support Chat companion plan).

## 3. Assumptions and open questions (separate from decisions)

- **A-F1-1**: `legacy_handoff_map` (SC) and `quiescence_deferred_updates.handed_off_at` /
  incident columns (UT) are empty in every environment (cutover never run). *Verify* by
  `wp eval` count on DEV before implementation lands; expected: 0 rows.
- **A-F1-2**: No `channel_bindings` row exists with `binding_uuid != support_conversation_uuid`
  **and** a non-null `last_ingest_update_id` **and** an in-flight Support Chat-side reference.
  *Verify* against the deployed adapter state. Support Chat stores no durable `channel_case_ref`
  outside the (empty) `legacy_handoff_map`, so the worst realistic case is one retriable failed
  delivery that succeeds after deploy.
- **A-F1-3**: `CHAR(36)` holds a v4 conversation UUID without truncation (36 chars) — confirmed
  by inspection of both schemas.
- **A-F1-4**: No consumer treats `EnsureChannelCaseService`'s returned `channel_case_ref` as a
  binding UUID beyond `OutboundContractController` passthrough. *Verify* by grep at
  implementation time.
- Open question: whether a live-inbound `404` (post-fix, implying real Support Chat data loss)
  should additionally raise an operator alert. *Deferred* — the plan adds only a non-content
  audit event; anything more is a separate finding.

## 4. Architectural decisions (cite ADRs)

Per ADR-0043 §Decision and Support Chat ADR-0011 §Decision:

1. **Send `$binding->support_conversation_uuid()` as `channel_case_ref`** at S1–S6; redocument
   at S7. `$binding` is already in scope at every site.
2. **Resolve inbound `channel_case_ref` by conversation UUID** at R1.
3. **`EnsureChannelCaseService::ensure()` returns the conversation UUID** as `channel_case_ref`
   in all branches (the value is its `$conversation_uuid` argument).
4. **Add two closed incident codes** to `CutoverIncidentReason` —
   `UNRESOLVED_CASE_REFERENCE = 'unresolved_case_reference'` (Support Chat `404 not_found` after
   active-binding selection) and `HANDOFF_REJECTED = 'handoff_rejected'` (every other
   deterministic Support Chat refusal: `400 invalid_body` / `400 invalid_operator` /
   `400 unsupported_operation` / `409 already_claimed` / `409 claimed_by_other` /
   `409 invalid_transition`, and the UT-client `sc_contract_unsupported_operation` code-bug
   guard). `finish()` is rewritten so **every** branch returns a named outcome — no generic
   `return OUTCOME_RETRY_TRANSIENT` fallback (see §7). Only the explicitly transient conditions
   stay retryable.
5. **`binding_uuid` never crosses the Contract v1 wire.** No SC-side binding-map resolver, no
   direct UT SQL from SC, no shared map, no UUID-equality assumption, no legacy fallback after
   an active binding is selected.
6. **No schema change.** `Migrator::target_version()` = 36. No new migration step, no
   `db_version` bump either plugin.

Rejected alternatives: ADR-0043 §Alternatives (SC resolver / new op; identifier collapse;
`404`-stays-transient; one combined code; do nothing).

## 5. Directory, namespace, schema, API impact (scoped)

- **Changed source** (≈8 files, all under `src/`): S1–S7 + R1 files, plus
  `src/Migration/CutoverIncidentReason.php` (two constants + `all()`) and the rewritten
  `finish()` classification in `CutoverReplayDispatcher.php`. No new file, no namespace change.
- **Schema / migration**: none. **Explicit proof no data-model migration is needed**:
  `support_conversation_uuid` is an existing `NOT NULL UNIQUE` column on every binding row;
  `legacy_handoff_map` / deferred handoff+incident columns are empty everywhere; the new
  incident code is a string value, not a column. No `ALTER`, no backfill, no `db_version` step.
- **Public API / wire**: `channel_case_ref` referent only; name/shape/operation-set unchanged.
  Discovery output unchanged.
- **CLI / config / options**: none.

## 6. Compatibility treatment for existing `prepared` and future `active` bindings

| Binding state | Origin | `support_conversation_uuid` present? | Behaviour after correction |
|---|---|---|---|
| `prepared` (existing, from `legacy-bind run`) | `LegacyBindingImportServiceV1` | Yes (`NOT NULL`) | On activation + replay, `channel_case_ref` = `support_conversation_uuid()` resolves at Support Chat immediately. No per-row action, no re-preparation. |
| `active` (future, from `ensure`) | `EnsureChannelCaseService` | Yes | `ensure` returns the conversation UUID; Support Chat stores/echoes it; `DeliverMessageService` resolves by conversation UUID. Consistent end-to-end. |
| `active` (any created before the fix) | `EnsureChannelCaseService` | Yes | Same column already populated; first post-deploy Contract call uses the corrected ref. Any in-flight pre-deploy call that returned `404` is retried (transient) and succeeds. |
| `unavailable` / `closed` | either | Yes | Not routed; unaffected. |

No migration, no dual-read window, no compatibility shim. The one-to-one `UNIQUE` constraint on
`support_conversation_uuid` guarantees resolution is deterministic.

## 7. Failure classification and fail-closed behaviour

**Exhaustive.** After the caller has selected an **active** binding and `CutoverReplayDispatcher`
has dispatched, every possible Contract result maps to a named outcome. `finish()` is rewritten
to remove the current generic `return self::OUTCOME_RETRY_TRANSIENT` tail; an unlisted/unknown
`reason` string maps to `handoff_rejected` (fail-closed), never to a silent retry.

| Contract result (source-verified) | Origin | `finish()` outcome | Incident code | Blocks `replaying → idle` / `confirm-complete`? | Resolution |
|---|---|---|---|---|---|
| `{ok:true}` (`200`), incl. resolve/reopen already-in-target-state short-circuit | SC handler | `OUTCOME_HANDED_OFF` (stamps `handed_off_at`) | — | no | — |
| `404 not_found` | SC `resolve_conversation()` → `null` (unknown/malformed ref) | `OUTCOME_INCIDENT` | **`unresolved_case_reference`** (new) | yes | real retry succeeds (`retried_success`), or `incident-acknowledge --po-decision-ref` |
| `400 invalid_body` / `400 invalid_operator` / `400 unsupported_operation` / `409 already_claimed` / `409 claimed_by_other` / `409 invalid_transition` | SC handler — deterministic domain refusal | `OUTCOME_INCIDENT` | **`handoff_rejected`** (new) | yes | real retry succeeds, or `incident-acknowledge` |
| `409 handoff_provenance_conflict` | SC `dispatch_with_provenance()` | `OUTCOME_INCIDENT` | `handoff_provenance_conflict` (existing) | yes | real retry succeeds, or `incident-acknowledge` |
| any other / unrecognised `ok:false` `reason` from a `2xx`-authenticated call | SC | `OUTCOME_INCIDENT` | `handoff_rejected` (fail-closed default) | yes | as above |
| `503 request_failed` | SC — `messages->create()` returned `null` (transient DB write failure) | `OUTCOME_RETRY_TRANSIENT` | — | no (row left unresolved for the next pass) | next ordinary replay pass |
| `401 contract_auth_failed` | SC `ContractOperationsController` — signature/nonce/clock (re-pair / clock fix) | `OUTCOME_RETRY_TRANSIENT` | — | no | next pass after re-pair |
| `sc_contract_not_paired` / `sc_authenticated_contract_unavailable` / `sc_contract_discovery_incompatible` / `sc_contract_signing_unavailable` / `sc_contract_transport_failed` | UT `SupportChatContractClient` client-side gate — call never sent | `OUTCOME_RETRY_TRANSIENT` | — | no | next pass after the environmental condition clears |
| `sc_contract_unsupported_operation` | UT client — op not on the adapter allow-list (code bug; unreachable normally) | `OUTCOME_INCIDENT` | `handoff_rejected` | yes | code fix + real retry |
| caught `\Throwable` from a collaborator inside `dispatch()` | UT | `OUTCOME_RETRY_TRANSIENT` | — | no | next pass; **the replay command reports the retryable count every pass**, so a non-decreasing count is an operator-visible escalation, never a silent infinite loop |
| pre-dispatch `decrypt_failed` / `parse_failed` / `unsupported_command` / `unmapped_sender` (SC never called) | UT | `OUTCOME_INCIDENT` | existing codes | yes | real retry, or `incident-acknowledge` |

Fail-closed guarantees: no legacy-processing fallback once an active binding is selected; no
implicit UUID equality; **every** Contract outcome is a named retryable outcome or a named
incident; the only retryable branches are the explicitly transient/transport/unavailable/unpaired
ones plus a caught exception surfaced on every pass; no `default` arm silently retries forever.

## 8. Handling of the old erroneous `channel_case_ref` values in test-only / DEV evidence

- **Production / DEV `legacy_handoff_map`**: empty (A-F1-1) — no stored row carries a
  binding-UUID `channel_case_ref` to rewrite. Nothing to migrate.
- **Tier 1 scratchpad evidence** (`scratchpad/evidence/f1-probe.log` etc.): disposable, never
  committed, destroyed with the throwaway environment. It records the *defect* (binding UUID
  sent, `404` returned) and remains valid as evidence of F1. It is **not** edited or "corrected".
- **Runbook v2 evidence**: when Tier 1 is re-run under runbook v2, its fresh evidence bundle
  supersedes the halted Tier 1 bundle; the old bundle is retained unchanged as the F1 record.
- **Committed tests**: `CutoverTier1HandoffResolutionTest` currently asserts the *defect*
  (`OUTCOME_RETRY_TRANSIENT`, no handoff). WP-F1-5 rewrites it to assert the *corrected*
  behaviour with a distinct `binding_uuid`; the git history preserves the characterization form.

## 9. Test matrix — real bindings only, no equality fixtures

Every binding is created by the real `LegacyBindingImportServiceV1` or real
`EnsureChannelCaseService` (independent `binding_uuid`); **no test seeds
`binding_uuid == support_conversation_uuid`** except one explicit degenerate-case guard.

| # | Coverage | Path | Assertion |
|---|---|---|---|
| T1 | Live inbound operator reply | real `EnsureChannelCaseService` binding → `InboundAdapterBridge::try_handle()` → real `SupportChatContractClient` → real SC `ContractOperationDispatcher` | SC message created against the conversation resolved from `support_conversation_uuid`; wire `channel_case_ref` ≠ `binding_uuid` |
| T2 | Live inbound lifecycle command (`/claim` …) | as T1, `handle_command()` | SC assignment change on the right conversation |
| T3 | Deferred cutover replay — operator reply | real `legacy-bind` `prepared` binding → `activate_prepared` → buffered reply → `CutoverReplayDispatcher::dispatch()` | `OUTCOME_HANDED_OFF`, one `legacy_handoff_map` row with `channel_case_ref` = SC conversation UUID, `handed_off_at` set |
| T4 | Deferred replay — lifecycle commands + `report_channel_unavailable` | as T3, each op | correct SC domain effect; map `kind` server-derived; `channel_case_ref` = conversation UUID |
| T5 | Idempotent retry / crash convergence | run T3, simulate pre-`handed_off_at` crash, re-run `replay-deferred-updates` | no duplicate SC message, no duplicate map row; matching `(bot_id,update_id)` converges silently |
| T6 | Provenance mismatch | pre-seed a `legacy_handoff_map` row with a different `kind`/`channel_case_ref` for the same `(bot_id,update_id)` | `409 handoff_provenance_conflict`; UT `handoff_provenance_conflict` incident; no SC domain/map write |
| T7 | Lifecycle event reporting | buffered `forum_topic_closed` on an active-bound topic | `report_channel_unavailable` with the conversation-UUID ref; SC channel status degraded; legacy UT conversation row unmutated |
| T8 | **Unresolved case reference (fail-closed)** | active binding whose `support_conversation_uuid` points at a conversation deleted on the SC side | `finish()` → `OUTCOME_INCIDENT` (`unresolved_case_reference`); `replaying → idle` and `confirm-complete` refused; no map row; ciphertext + audit retained |
| T8b | **`handoff_rejected` (fail-closed)** | buffered reply > 4096 chars → SC `400 invalid_body`; and a buffered `/resolve` against an already-resolved conversation → SC `409 invalid_transition` | each → `OUTCOME_INCIDENT` (`handoff_rejected`); replay completion blocked; no map row |
| T9 | Transient stays transient | SC `503 request_failed` and SC made unreachable (`sc_contract_transport_failed`) mid-replay | `OUTCOME_RETRY_TRANSIENT`; row unresolved but not an incident; next pass succeeds; retryable count reported |
| T9b | **No generic silent retry** | inject an `ok:false` result with an unrecognised `reason` string on a `200` call | `finish()` → `OUTCOME_INCIDENT` (`handoff_rejected`), **not** `OUTCOME_RETRY_TRANSIENT` |
| T10 | No plaintext persistence | after T3–T8b | `SHOW COLUMNS` + filtered `SELECT *` on `legacy_handoff_map`, `quiescence_deferred_updates` incident columns, `cutover_*` audit — only ids/uuids/fixed-vocabulary/timestamps; `verify_step_11` passes |
| T11 | Degenerate guard | one binding with `binding_uuid == support_conversation_uuid` | still resolves (no regression for the historical fixture shape) |
| T12 | Rewrite `CutoverHandoffIntegrationTest` (7 cases) | distinct `binding_uuid` throughout | every case asserts wire `channel_case_ref` = conversation UUID ≠ `binding_uuid` |
| T13 | Invert `CutoverTier1HandoffResolutionTest` | the merged characterization test's real `legacy-bind` setup | `test_handoff_does_not_resolve_…` → `test_handoff_resolves_a_real_legacy_bind_prepared_binding` asserting `OUTCOME_HANDED_OFF` |
| T14 | `finish()` classification is total | table-driven over every row of §7 | every Contract result maps to exactly one named outcome; no `default`/unhandled path |

**Real dual-plugin coverage** (interop harness `bin/docker/test-integration-interop.sh`, real
two-way pairing, `pre_http_request` fake, `down -v` between runs): T1, T2, T3, T4, T5, T6, T7,
T8, T8b, T10, T13. Unit-level: T9, T9b, T11, T14, plus `EnsureChannelCaseServiceTest`,
`InboundAdapterBridgeTest`, `CutoverReplayDispatcherTest`, `DeliverMessageServiceTest`
argument/return updates. **No test may seed `binding_uuid == support_conversation_uuid`** except
T11.

## 10. CI impact

Existing `ci.yml` jobs (phpcs, static-analysis, unit 8.1/8.3/8.4,
integration-wp-only-{floor,current}, integration-wc-present-current, js-behavioural, build,
package-acceptance ×3) must stay green. The interop suite is **not** a CI job in this repo — it
is run locally via `bin/docker/test-integration-interop.sh` on both WP/PHP variants and the
verbatim result lines attached to the implementation report, exactly as the Tier 1 evidence was.
**No new CI job** is added by this plan.

## 11. Work packages in execution order

1. **WP-F1-1 (docs, this freeze)** — ADR-0043, Support Chat ADR-0011, this plan, the Support
   Chat companion plan, the Support Chat PO decision item 7, registry/milestone updates. Merge
   after doc CI green. **No code. No PO implementation acceptance.**
2. **WP-F1-2 (Support Chat)** — comment corrections + interop fixture alignment; no `db_version`
   bump. (Support Chat companion plan owns it.)
3. **WP-F1-3 (UT, adapter send + incident vocab + classification)** — S1–S7 + `ChannelBinding`
   doc; add `CutoverIncidentReason::UNRESOLVED_CASE_REFERENCE` and `::HANDOFF_REJECTED`; rewrite
   `finish()` to the exhaustive §7 mapping (no generic transient tail). Unit + adapter interop
   tests (T1, T2, T8, T8b, T9, T9b, T11, T14).
4. **WP-F1-4 (UT, inbound resolve)** — R1; outbound interop + unit tests.
5. **WP-F1-5 (cutover-handoff tests)** — rewrite `CutoverHandoffIntegrationTest` (T12); invert
   `CutoverTier1HandoffResolutionTest` (T13); T3–T7, T10. Run the full interop suite locally on
   both variants; attach evidence.
6. **WP-F1-6 (DEV rehearsal runbook v2)** — new
   `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md` (+ Support Chat companion v2)
   superseding v1: F1 resolution as a hard precondition; the §1.3/§2 "wire detail" note changed
   from "fixture-seeding nuance" to "resolved by ADR-0043 / Support Chat ADR-0011"; new
   `unresolved_case_reference` and `handoff_rejected` incident scenarios added to the Run 3
   family. (v1 carries only the non-design "Amendment A" status footer added by this freeze.)
7. **WP-F1-7** — implementation report citing this plan's freeze SHA.

WP-F1-3/4 may land in one PR. WP-F1-2 is an independent Support Chat PR. Do not merge any code
PR to a release branch or deploy anything before Product Owner acceptance of both ADRs and this
plan's implementation acceptance (§12).

## 11a. Tier 1 rerun gate

After WP-F1-2…F1-6 are merged and CI-green: a Tier 1 **re-attempt** may be requested. It
requires a **separate Approval A addendum** from the Product Owner (the existing Approval A was
consumed by the halted run) and runs only under DEV rehearsal runbook v2. **Tier 1 cannot be
accepted until the correction is implemented and its real-binding handoff path (T3, T4, T8)
passes green in the interop harness.** Until then Tier 1 remains "attempted → halted by F1".
Tier 2 stays blocked on B1, B2, and F1 and remains unexecuted.

## 12. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A live binding has `binding_uuid != support_conversation_uuid` and an in-flight SC reference | A-F1-1/A-F1-2 verification on DEV before merge; SC holds no durable ref outside the empty map; worst case one retriable delivery |
| `CutoverHandoffIntegrationTest` rewrite masks a regression | keep T11 degenerate guard; assert wire value ≠ `binding_uuid` in T12 |
| `unresolved_case_reference` / `handoff_rejected` misclassifies a transient condition | `finish()` distinguishes `404`/`4xx`/`409` (deterministic → incident) from `503 request_failed` / `401 contract_auth_failed` / client transport-unavailable gates (→ retryable); T9 + T9b pin both directions; T14 proves totality |
| New incident codes break the "no SC map row for a UT incident" property | T10 asserts it structurally for both new codes |
| A deterministic 409 lifecycle conflict that is actually idempotent-benign becomes a noisy incident | acceptable fail-closed default; the implementation MAY narrow a specific 409 toward idempotent-success only with a proof it is safe, documented in the implementation report — the freeze default is incident |
| Someone treats Tier 1 re-attempt or F1 implementation as authorized by this freeze | §11a + §12 acceptance text; ADR-0043 Status is Proposed; no acceptance record created here |
| Scope creep into an `ensure_channel_case` Contract operation | ADR-0043 / Support Chat ADR-0011 explicitly reject it; this plan changes no Contract operation set |

## 13. Out of scope

- Any production or DEV cutover, quiescence window, migration, cohort activation, route switch,
  soak, deployment, release, tag, or rollback.
- Executing or re-executing Tier 1 or Tier 2 of the DEV rehearsal (this task or the
  implementation task).
- Any Support Chat schema change or `db_version` bump.
- A new Contract v1 operation, route, field, `ensure_channel_case` service, or SC-side
  binding→conversation resolver.
- Collapsing `binding_uuid` and `support_conversation_uuid` (option (c), rejected).
- Changing the quiescence state machine, the activation/compensation saga, `cas_version`
  monotonicity, `try_handle()`'s `return false` for non-lifecycle bot commands, or the
  `maybe_mark_topic_unavailable()` ordering.
- Editing `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md` (immutable record).
  Editing the **design sections** of the frozen rehearsal plan v1 — this freeze appends only a
  dated non-design "Amendment A" status footer (F1 halt + correction gate); the design revision
  is deferred to runbook v2.
- Any Product Owner implementation acceptance (separate later action, §12 below).

## 14. Definition of done

- ADR-0043 and Support Chat ADR-0011 accepted by the Product Owner.
- `channel_case_ref` carries the Support Chat conversation UUID at every send site (S1–S6),
  resolved by conversation UUID at R1; `binding_uuid` appears in **no** Contract v1
  request/response body (grep-proven).
- `CutoverIncidentReason::UNRESOLVED_CASE_REFERENCE` and `::HANDOFF_REJECTED` added; `finish()`
  matches the exhaustive §7 table with **no generic transient fallback**; T8, T8b, T9, T9b, T14
  green.
- `CutoverHandoffIntegrationTest` (7 cases) and `CutoverTier1HandoffResolutionTest` pass with a
  **distinct** `binding_uuid`; full local interop suite green on both WP/PHP variants; result
  lines in the implementation report.
- All `ci.yml` jobs green.
- `Migrator::target_version()` unchanged (36); no new migration step; SC `db_version` unchanged
  (11).
- DEV rehearsal runbook v2 committed, superseding v1.
- Implementation report cites this plan's freeze SHA.
- **No Tier 1 / Tier 2 acceptance record is created by the implementation.** Tier 1 re-attempt
  needs a separate Approval A addendum; Tier 2 stays blocked on B1, B2, and F1.

## 15. Exact Product Owner acceptance text required before F1 implementation may begin

> **Product Owner authorization — SC-M03 final-cutover F1 identity-correction implementation**
>
> I have reviewed ADR-0043 (Universal Telegram) and ADR-0011 (Universal Support Chat) and their
> F1 remediation plans, frozen as documentation-only. I accept the frozen identity rule:
> `channel_case_ref` in Contract v1 identifies the Support Chat conversation/case (resolved via
> Support Chat's own conversation repository); the Universal Telegram binding UUID is a
> UT-owned binding identity that never crosses the Contract v1 wire; equality of the two UUIDs
> is never required or assumed; no Support Chat binding→conversation resolver, shared map, or
> UT-binding-UUID fallback is added; a missing/malformed/non-existent case reference after an
> active binding is selected is a classified terminal incident, never an unbounded retry.
>
> I authorize implementation of exactly the work packages in
> `docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` (and its Support
> Chat companion), pinned to the accepted baselines, in normal feature branches with per-repo
> CI and the interop harness. This authorizes **no** schema or `db_version` change, **no** new
> Contract operation, **no** DEV or production quiescence, migration, activation, route switch,
> cutover, deployment, release, tag, or rollback, and **no** execution of Tier 1 or Tier 2 of
> the DEV rehearsal.
>
> A Tier 1 re-attempt remains a separate authorization (a new Approval A addendum) after this
> implementation is merged, CI-green, and its real-binding handoff path passes, under DEV
> rehearsal runbook v2. Tier 2 stays blocked on B1, B2, and F1.
>
> Signed: __________________________  Date: __________
