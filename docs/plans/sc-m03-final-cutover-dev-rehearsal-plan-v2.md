# SC-M03 Final-Cutover — Disposable DEV Rehearsal Plan v2 (primary operator runbook)

**Status: planning-only. No rehearsal has run under this runbook. Product Owner execution
approval is outstanding.** This document authorizes nothing. It changes no code, schema, plugin
version, configuration, test, tag, release, or deployment, and it creates no infrastructure,
containers, Telegram bots, groups, topics, DNS records, certificates, or credentials.

**This runbook supersedes [`sc-m03-final-cutover-dev-rehearsal-plan-v1.md`](sc-m03-final-cutover-dev-rehearsal-plan-v1.md)
as the operative runbook.** It revises only the portions of v1 that finding F1 invalidated — the
pinned baselines, the `channel_case_ref` wire identity, Run 1's handoff fixture and assertions,
and the replay-failure classification referenced by Runs 2 and 3. **Every safety boundary,
evidence requirement, redaction rule, teardown requirement, the Tier 1 / Tier 2 distinction, and
blockers B1–B5 of v1 are carried forward unchanged** and are reproduced or referenced below. v1
is retained unedited as the historical record of the halted first attempt (its "Amendment A"
footer already points here).

## 0. Why v2 exists — finding F1, now corrected

The Tier 1 prerequisite validation was executed on 2026-08-27 and **HALTED** at the UT→SC
deferred-update handoff phase by **finding F1**
([`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md);
merges UT `98c602543bd67bc471e2a88468d175fb6e659b46`, SC `fcbfaa773ef63661b6d8ce42962f10bb174588f8`).

**F1**: Universal Telegram sent `$binding->binding_uuid()` as the Contract v1 `channel_case_ref`,
but Support Chat's `ContractOperationDispatcher::resolve_conversation()` resolves
`channel_case_ref` only as its own `conversation_uuid`, and every real binding-creation path
(`LegacyBindingImportServiceV1`, `EnsureChannelCaseService`) mints an independent
`binding_uuid ≠ support_conversation_uuid`. A real `legacy-bind` cohort's replayed updates got
Support Chat `404 not_found`, which `CutoverReplayDispatcher::finish()` mapped to an unbounded
`OUTCOME_RETRY_TRANSIENT` — never handed off, never an incident — so `replaying → idle` and
`cutover confirm-complete` were blocked forever with no classified outcome.

**F1 is corrected and merged in both repositories** (documentation-only decision:
[ADR-0043](../adr/0043-support-chat-adr-0011-pin-and-channel-case-ref-conversation-uuid-correction.md)
/ Support Chat ADR-0011, both **Accepted** 2026-08-27):

- `channel_case_ref` now carries the **Support Chat conversation/case UUID**. Universal Telegram
  sends `ChannelBinding::support_conversation_uuid()` (an existing `CHAR(36) NOT NULL UNIQUE`
  column populated by both creation paths) at every send site; it resolves an inbound
  `channel_case_ref` via `find_by_conversation_uuid()`. `binding_uuid` stays UT-local and
  **never crosses the Contract v1 wire**.
- `CutoverReplayFailureClassifier` (`src/Migration/CutoverReplayFailureClassifier.php`, ADR-0043
  §3) makes `finish()` **exhaustive and fail-closed**: `404 not_found` → new closed incident
  `unresolved_case_reference`; the enumerated deterministic `400`/`409` refusals **and any
  unrecognised `ok:false` reason** → new closed incident `handoff_rejected`;
  `409 handoff_provenance_conflict` unchanged; only the frozen explicit transient set stays
  retryable. There is no generic transient fallback.
- Support Chat needed **no** runtime, schema, `universal_support_chat_db_version`, resolver, or
  Contract-operation change — comment corrections C1–C4 only. `Migrator::target_version()` = 36;
  SC `db_version` = 11.

Merge evidence:

| Repo | F1 runtime PR | F1 merge | F1 closure PR | Closure merge |
|---|---|---|---|---|
| universal-telegram | #53 | `7d4cc4fecb97f862721cea0fec427ade26b46ea7` | #54 | `32f17ea904a33cdd1f9b0225ba9638f95a09d883` |
| universal-support-chat | #26 (comments only) | `9144cb1e2362c2be8d4c74f1461bba7ffe236575` | #27 | `5d81b5b7795ee50f3a79e535a483d7677b36d1c0` |

Post-merge, the real dual-plugin interop harness — merged UT `main` + the SC F1 branch, fresh
disposable database per run — passed **OK (47 tests, 722 assertions)** on **both** supported
WP/PHP variants (WP 6.9 / PHP 8.1 and WP 7.1 / PHP 8.3), including
`CutoverTier1HandoffResolutionTest::test_handoff_resolves_a_real_legacy_bind_prepared_binding`
(the inverted F1 characterization test), the rewritten `CutoverHandoffIntegrationTest` (real
distinct-`binding_uuid` bindings throughout), and the new
`test_unresolvable_conversation_uuid_becomes_unresolved_case_reference_incident`,
`test_deterministic_sc_refusal_becomes_handoff_rejected_incident`, and
`test_wire_channel_case_ref_is_the_conversation_uuid_never_the_binding_uuid`.

The **immutable, Product-Owner-approved Tier 1 execution baselines** this runbook pins (§1) are
universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
`4f833c3344c3cff2adcc0227f93832c0c3a4427a`. Operators must fetch origin, verify these exact
commits exist, and check out these exact SHAs before execution. These commits include DEV
rehearsal runbook v2 and the corrected proposed Approval A addendum; their runtime trees remain
byte-identical to the F1 implementation commits (universal-telegram `7d4cc4f`,
universal-support-chat `9144cb1`) — no code, schema, `db_version`, test, configuration, workflow,
or runtime change occurred after F1, only documentation. **Future documentation merges must not
alter this authorised execution baseline unless a new Product Owner approval is recorded.**

**F1 is therefore no longer a code blocker.** The Tier 1 re-attempt is gated on a **separate
Approval A addendum**
([`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md))
— the original Approval A was consumed by the halted run. **That addendum is now RECORDED /
Product Owner accepted (2026-08-28): it authorizes exactly one (1) Tier 1 re-attempt at the two
immutable execution baseline SHAs and nothing else.** Tier 2 remains blocked on B1 and B2 and
pending Approval B.

## 1. Charter, ADRs, and pinned baselines (revised)

- Charter: [`docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md`](../milestones/ut-adapter-m1-universal-support-chat-adapter.md) §0d.
- This repository: [ADR-0042](../adr/0042-support-chat-adr-0010-pin-and-final-cutover-state-machine.md) (cutover state machine, activation/compensation saga, cohort-aware replay, incident record, `maybe_mark_topic_unavailable()` cross-talk fix), **as amended by [ADR-0043](../adr/0043-support-chat-adr-0011-pin-and-channel-case-ref-conversation-uuid-correction.md)** (§3 `channel_case_ref` sender identity; §4/§5 closed incident vocabulary).
- F1 remediation: [`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`](sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md) and its Support Chat companion; F1 implementation closure [`docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md`](../closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md).
- Support Chat: ADR-0010 + ADR-0011 and their Product Owner decision records.
- Companion (Support Chat): [`sc-m03-final-cutover-dev-rehearsal-plan-v2.md`](https://github.com/magpern/universal-support-chat/blob/main/docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md) and [`sc-m03-final-cutover-dev-rehearsal-po-decisions.md`](https://github.com/magpern/universal-support-chat/blob/main/docs/decisions/sc-m03-final-cutover-dev-rehearsal-po-decisions.md).

**Immutable Tier 1 execution baselines this runbook pins** (operators fetch origin, verify these
exact commits exist, and check out these exact SHAs before execution — see §0; future
documentation merges must not alter this baseline unless a new Product Owner approval is
recorded):

| Repository | Pinned SHA | Notes |
|---|---|---|
| `magpern/universal-telegram` | `6eed0228286e84b4e56e0119f242b483f138a58e` | Plugin version `0.19.0`; schema `target_version()` `36` (unchanged by F1). Contains DEV rehearsal runbook v2 and the corrected proposed Approval A addendum; `src/` + `tests/` + config + CI-workflow tree byte-identical to the F1 implementation commit (`7d4cc4f`) — documentation only added since. |
| `magpern/universal-support-chat` | `4f833c3344c3cff2adcc0227f93832c0c3a4427a` | Plugin version `0.6.0`; `universal_support_chat_db_version` `11` (unchanged by F1). Contains the companion runbook v2 and the Approval A addendum text; `src/` + `tests/` + config + CI-workflow tree byte-identical to the F1 implementation commit (`9144cb1`) — documentation only added since. |

The Contract v1 pins (`src/SupportChatAdapter/ContractConstants.php`:
`CONTRACT_VERSION_ID='support-channel-contract/v1'`,
`AUTH_PROFILE_ID='support-channel-contract-auth/v1'`,
`CONTRACT_PIN_SHA='dff2730e24b7d3f70f15f706305e12e14fdcc6c8'`) are unchanged by F1 — F1 changed
the *referent* of `channel_case_ref`, not its name, type, or the operation set.

## 2. Repository findings — corrected `channel_case_ref` wire identity

**This section replaces v1 §2's "Wire detail" bullet.** All other v1 §2 command-semantics
findings (the CLI table; `cutover begin` is mutating and inserts a `cutover_runs` row; `cutover
status` / `cutover recover` are the only read-only `cutover` actions; `cutover activate` is the
only binding-status write with strictly-monotonic `cas_version`; `replay-deferred-updates` is
the single cohort-aware drain against the widened backlog predicate `replayed_at IS NULL AND
handed_off_at IS NULL AND incident_resolved_at IS NULL`; deferred-update capture; quiescence
state machine; Phase B continuous re-check; the routing gate; the `maybe_mark_topic_unavailable()`
cross-talk fix; "do not use `support-chat-bindings import --apply` for cohort prep") **remain in
force verbatim from v1.**

### 2.1 `channel_case_ref` = the Support Chat conversation/case UUID (ADR-0043 / Support Chat ADR-0011)

- The Contract v1 wire field `channel_case_ref`, on **every** provenance-capable operation
  (`ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`,
  `report_channel_unavailable`), carries the **Support Chat conversation/case UUID**.
- Universal Telegram sends `$binding->support_conversation_uuid()` — column
  `wp_universal_telegram_support_chat_bindings.support_conversation_uuid`, `CHAR(36) NOT NULL`,
  `UNIQUE KEY support_conversation_uuid`, populated from the Support Chat conversation UUID by
  both `LegacyBindingImportServiceV1::import_one()` and `EnsureChannelCaseService::ensure()`.
- `EnsureChannelCaseService::ensure()` returns that same conversation UUID as the
  `channel_case_ref` it hands back to Support Chat, in every branch.
- Universal Telegram resolves an inbound `channel_case_ref` (SC→UT deliver/notify/backfill) via
  `ChannelBindingRepository::find_by_conversation_uuid()`; local-row writes still key on the
  resolved `$binding->binding_uuid()`.
- **`binding_uuid` is UT-local** — binding lookup, lifecycle, activation CAS, routing identity,
  idempotency keys (`record_ingest_update_id`, delivery keys), and every `cutover_*` / binding
  audit row — and **never appears in a Contract v1 request or response body.**
- Support Chat resolves `channel_case_ref` through its own `ConversationRepository::find_by_uuid()`
  and writes the resolved conversation UUID into `legacy_handoff_map.channel_case_ref`
  (unchanged — `dispatch_with_provenance()` was already passed `$conversation->uuid()`). **No
  SC-side binding→conversation resolver, shared map, or UT-binding-UUID fallback exists or is to
  be added.**

**Consequence for the rehearsal:** the v1 assumption that a fixture binding's `binding_uuid`
must equal the Support Chat conversation UUID is **void**. Every rehearsal binding is created by
the real `LegacyBindingImportServiceV1` / `EnsureChannelCaseService` with an independent
`binding_uuid`, and the rehearsal asserts `binding_uuid ≠ support_conversation_uuid` and that
the wire `channel_case_ref` equals the conversation UUID.

### 2.2 Exhaustive, fail-closed replay-failure classification (ADR-0043 §3)

`CutoverReplayDispatcher::finish()` delegates every `ok:false` result (after an **active**
binding has been selected) to `CutoverReplayFailureClassifier::classify( status, reason )`:

| Contract result | `finish()` outcome |
|---|---|
| `{ok:true}` (incl. resolve/reopen already-in-target-state short-circuit) | `OUTCOME_HANDED_OFF`; `handed_off_at` stamped |
| `404 not_found` | `OUTCOME_INCIDENT` — **`unresolved_case_reference`** (new closed code) |
| `400 invalid_body` / `invalid_operator` / `unsupported_operation`; `409 already_claimed` / `claimed_by_other` / `invalid_transition`; `sc_contract_unsupported_operation`; **any unrecognised `ok:false` reason** | `OUTCOME_INCIDENT` — **`handoff_rejected`** (new closed code) |
| `409 handoff_provenance_conflict` | `OUTCOME_INCIDENT` — `handoff_provenance_conflict` (existing, unchanged) |
| `503 request_failed`; `401 contract_auth_failed`; client `sc_authenticated_contract_unavailable` / `sc_contract_not_paired` / `sc_contract_discovery_incompatible` / `sc_contract_signing_unavailable` / `sc_contract_transport_failed` | `OUTCOME_RETRY_TRANSIENT` — the frozen explicit transient set, the only retryable results |
| caught `\Throwable` inside `dispatch()` | `OUTCOME_RETRY_TRANSIENT`, and the replay command reports the retryable count **every pass** — a non-decreasing count is an operator-visible escalation, never a silent infinite loop |
| pre-dispatch `decrypt_failed` / `parse_failed` / `unsupported_command` / `unmapped_sender` (Support Chat never called) | `OUTCOME_INCIDENT` — existing codes |

Both new incidents have the **identical durable semantics** as every other cutover incident:
they preserve the encrypted payload and audit trail, leave `handed_off_at` and `replayed_at`
unset, write **no** `legacy_handoff_map` row, and block `replaying → idle` and
`cutover confirm-complete` until resolved by a genuine successful retry (auto-stamping
`incident_resolution = 'retried_success'`) or the existing authority-gated
`cutover incident-acknowledge --po-decision-ref` terminal path. The terminal-acknowledgement
exception is **not** widened by F1.

**Operator rule (unchanged intent, restated for the new codes):** an `unresolved_case_reference`
or `handoff_rejected` incident, and any unknown/rejected Contract failure, **must remain a
durable incident**. It is never hand-edited, never reclassified as `retried_success` without a
real retry that genuinely succeeded, and never `incident-acknowledge`d to force a run to
`confirm-complete`. Acknowledgement is rehearsed only as the separate §7.5 scenario, with a
synthetic opaque `--po-decision-ref` and a Product-Owner-authority-simulation note.

## 3. Assumptions requiring DEV verification

v1 §3 assumptions **A1, A2, A5–A8 remain unchanged.** Revised:

| # | Assumption | Verify (in the disposable env, before it is relied on) |
|---|---|---|
| A1 | The rehearsal env's checked-out plugin SHAs equal the immutable Tier 1 execution baselines (`6eed022…` / `4f833c3…`) and running schema is UT `36` / SC `11`. | `git rev-parse HEAD`; `wp eval` on `Migrator::target_version()` / `get_option('universal_support_chat_db_version')`. |
| A3 | Whether `cutover begin` preflight enforces "mapping-complete on Support Chat's side" and "no blocking incident," or only the local `prepared` binding. UT `CutoverActivationService::preflight()` checks only for a `prepared`-status binding per candidate. | Read `CutoverActivationService::preflight()` at `6eed022…` (runtime tree identical to `7d4cc4f` — the F1 implementation commit); drive a candidate whose SC map row is not `migrated` and observe whether `begin` refuses it. |
| A4 | The exact Support Chat CLI used to confirm `status='migrated'` is `wp universal-support-chat legacy-migrate status` / `validate`. | Confirm against the pinned CLI; cross-check a known map row via `wp eval`. |
| **A9 (new)** | A real `legacy-bind`-prepared binding (independent `binding_uuid`) is now handed off successfully by `replay-deferred-updates` — i.e. F1's correction holds end-to-end in the disposable harness, not only in the committed interop suite. | Run 1 step 11a: activate one real `legacy-bind` binding, buffer one operator reply, run one `replay-deferred-updates` pass, assert `OUTCOME_HANDED_OFF` + one `legacy_handoff_map` row whose `channel_case_ref` = the conversation UUID ≠ `binding_uuid`. **A hard gate before `cutover begin` (§8.1 precondition 9).** |
| A6 | Synthetic deferred-update payloads injected via `DeferredUpdateRepository::buffer(...)` decrypt and drive `process_update()` / `CutoverReplayDispatcher` identically to a real webhook arrival. | Compare an injected-row replay against the interop suite's own fixtures (Tier 1) or a real authenticated webhook arrival (Tier 2). |

## 4. Rehearsal objective and boundary

**Unchanged from v1 §4.** Objective: prove the complete operational sequence — Phase A migration
evidence → UT quiescence → Phase B reconciliation → prepared-binding cohort activation →
cohort-aware deferred-update handling → validation → safe return to ordinary DEV operation.

**"Safe return" in DEV = teardown** (`docker compose … down -v` + explicit volume removal for
Tier 1; additionally, for Tier 2, `@BotFather` bot deletion + `deleteWebhook` + isolated-vhost
removal). It is **not** a production rollback and is never described as one. Production remains
forward-only.

**This rehearsal does not authorize** production cutover, retention or deletion, release,
deployment, route switch, soak, or removal of Universal Telegram legacy UI or data.

### 4.1 Tier boundary — Tier 1 is not the DEV rehearsal (unchanged from v1 §4.1)

| Tier | What it is | Status under v2 |
|---|---|---|
| **Tier 1** | A **required disposable automated operational-sequence / integration validation** in the container/PHPUnit interop harness (`docker/docker-compose.yml` + `docker/docker-compose.interop.yml`, `down -v` before and after), **zero Telegram traffic**. Proves data effects, state-machine sequencing, and CLI-equivalent service ordering of Runs 1, 2, 3. | **COMPLETE — the single authorised re-attempt ran 2026-08-28 and PASSED** on both WP/PHP variants at the immutable execution baseline SHAs (closure: [`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md)). The Approval A addendum's one-time authorisation is consumed; a second Tier 1 attempt needs a new Product Owner approval. |
| **Tier 2** | The **first actual disposable DEV rehearsal**: an isolated full-WordPress instance plus a **dedicated non-production Telegram bot + test forum supergroup + test topics**. | **Required. Blocked on B1 and B2.** |

**Tier 1 does NOT satisfy the accepted requirement for a disposable DEV rehearsal** — it lacks
real WP-Cron / Action Scheduler drain, the Redis object cache, authenticated Telegram webhook
ingress, real chat-widget traffic, and the DEV VPS runtime surface. **B1 and B2 block execution
of the DEV rehearsal itself.** Tier 1 must be completed first, green, as a precondition to Tier 2.

## 5. Isolation and data safety — carried forward from v1 §5 unchanged

All of v1 §5 applies verbatim: the Tier 1 / Tier 2 topologies (§5.1); non-production secrets and
dedicated Telegram resources for Tier 2 only (§5.2); **synthetic fixture data only** (§5.3) — no
customer/operator content, no production transcript, no production DB attached/mounted/dumped, no
production credential read; setup and cleanup evidence (§5.4); irreversible-external-effect
prevention (§5.5 — Tier 1 structurally prevents any `api.telegram.org` call via the
`pre_http_request` filter, no token, no network; no message is ever sent to a real user or a
real support group); and the **redaction rules** (§5.6):

- Retain only ids, UUIDs, fixed-vocabulary strings (`kind`, `incident_reason`,
  `incident_resolution`, quiescence/cutover state names — now including
  `unresolved_case_reference` and `handoff_rejected`), counts, timestamps, CLI stdout,
  `SHOW COLUMNS` output, before/after `cas_version`.
- **Never retain**: any `payload_ciphertext`, any decrypted payload, any `body_ciphertext`, any
  message text, any bot token / webhook secret, any WordPress admin credential, any
  `CredentialVault` key material.
- `SELECT *` dumps are filtered to drop ciphertext/body columns before saving; synthetic fixture
  text is elided too, to keep the rule uniform.

## 6. Blockers

| ID | Blocker | Blocks | Status under v2 |
|---|---|---|---|
| **B1** | No isolated full-WordPress rehearsal environment exists (the DEV VPS is one shared WordPress + MariaDB + Redis stack). | Execution of the DEV rehearsal (Tier 2). | Open. Infrastructure work. |
| **B2** | No dedicated non-production Telegram bot / test supergroup / test topics. | Execution of the DEV rehearsal (Tier 2). | Open. Product Owner / infrastructure. |
| **B3** | `cutover begin` (inserts a `cutover_runs` row) and `cutover activate` (writes binding status) have no dry-run. | Confidence that they can be "previewed." | Documented limitation; `status` + `recover` are the read-only pre-checks. |
| **B4** | Assumption A3 unresolved — a cohort could pass UT `begin` preflight while its Support Chat map row is not `migrated`. | Trusting `begin` alone as the migration-evidence gate. | Compensated: the rehearsal asserts `status='migrated'` via Support Chat CLI + `wp eval` before `begin` (Run 1 step 11). |
| **B5 (governance)** | Product Owner authorization to execute the rehearsal under v2. | Tier 2 (execution). | **Tier 1: DONE** — Approval A addendum recorded 2026-08-28 (§10); the single authorised re-attempt was executed 2026-08-28 and PASSED (closure `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`); the one-time authorisation is consumed. **Tier 2: still open** — Approval B unchanged, blocked on B1 + B2. |
| ~~**F1**~~ | ~~The cutover deferred-update handoff cannot resolve a real prepared binding.~~ | ~~Tier 1 and Tier 2.~~ | **CLEARED 2026-08-27** — corrected and merged in both repositories (§0); verified by the real dual-plugin interop suite on both WP/PHP variants. A new pre-`begin` gate (A9, §8.1 precondition 9) asserts the real-cohort handoff resolves in the disposable env before Tier 1 proceeds. |

## 7. Test matrix and sequencing

The first rehearsal is deliberately small: **one synthetic conversation / one-member cohort**,
minimum Telegram fixtures. Three separate disposable runs. Every mutating step is gated by its §8
stop-condition review.

### 7.1 Run 1 — authoritative happy path (REQUIRED) — revised

Fixtures, all with **distinct `(bot_id, update_id)`**: one deferred operator reply (→ handed
off, one Support Chat message + one `legacy_handoff_map` row + `handed_off_at`); the same
`(bot_id, update_id)` re-presented once (→ idempotent pre-`handed_off_at` retry convergence, no
duplicate); one supported command `/claim` (→ correct Support Chat op with provenance); one
`forum_topic_closed` lifecycle event (→ `report_channel_unavailable`, no legacy mutation); one
transient-transport-failure row that clears on the next `replay-deferred-updates` pass (→
non-incident retry, `retried_success`). **No unrecoverable incident fixture in Run 1** — Run 1
is expected to reach `confirm-complete`.

**Every binding in Run 1 is created by the real `LegacyBindingImportServiceV1` (via Support Chat
`legacy-bind run`); no fixture seeds `binding_uuid == support_conversation_uuid`.**

| # | Step | Mutating? | Command / action |
|---|---|---|---|
| 1 | Preflight | read-only | §8.1 checklist (Approval A **addendum** signed, v2 SHAs, schema, fresh env, pairing, discovery, `cutover status`, `quiescence status`, `legacy-bind status`) |
| 2 | Seed synthetic fixtures | writes to the disposable DB only | test bot profile + supergroup destination + topic; one legacy UT conversation `topic_creation_state='created'` + 2 messages + 1 note, owner = factory user; the one-line cohort file (a synthetic `support_conversation_uuid`) |
| 3 | Phase A dry-run | no | `wp universal-support-chat legacy-migrate run --phase=backfill --dry-run` |
| 4 | Phase A real | **yes** | `wp universal-support-chat legacy-migrate run --phase=backfill --assume-migration-authority` |
| 5 | Phase A validation | no | `wp universal-support-chat legacy-migrate validate`; `… status`; assert source UT rows unmutated; a second `--dry-run` backfill shows a stable high-water mark |
| 6 | Quiescence enter | **yes** | `wp universal-telegram quiescence enter --assume-quiescence-authority` |
| 7 | Drain observation | no | `wp universal-telegram quiescence status` (record the full drain breakdown); attempt one synthetic legacy write → expect `409 quiescence_active` |
| 8 | Quiescence confirm | **yes** | `wp universal-telegram quiescence confirm` → `quiescent`; `status` shows `is_quiescent(): true`, backlog 0 |
| 9 | Phase B dry-run | no | `wp universal-support-chat legacy-migrate run --phase=reconcile --dry-run` |
| 10 | Phase B real | **yes** | `wp universal-support-chat legacy-migrate run --phase=reconcile --assume-migration-authority` → cohort member `status='migrated'` |
| 11 | Migration-evidence gate | no | Support Chat CLI + `wp eval` prove `status='migrated'` for every cohort member (resolves B4 / A4) **before** any `begin` |
| 11a | **F1-correction gate (new — A9)** | **yes** — writes to the disposable test database only (activates a binding, buffers a deferred row, stamps `handed_off_at`, writes one `legacy_handoff_map` row; all torn down at `down -v`) | Activate one real `legacy-bind`-prepared binding (`activate_prepared`), buffer one operator reply for its topic, run one `replay-deferred-updates` pass, and assert: `OUTCOME_HANDED_OFF`; exactly one Support Chat message; exactly one `legacy_handoff_map` row whose `channel_case_ref` equals the **Support Chat conversation UUID** and **not** `binding->binding_uuid()`; `handed_off_at` stamped. **If this does not hold, HALT — F1's correction is not effective in this environment; do not run `cutover begin`.** (This may be performed as the committed interop assertion `CutoverTier1HandoffResolutionTest::test_handoff_resolves_a_real_legacy_bind_prepared_binding` re-run in the same disposable env.) |
| 12 | Binding prep dry-run | no | `wp universal-support-chat legacy-bind run --dry-run` |
| 13 | Binding prep real | **yes** | `wp universal-support-chat legacy-bind run --assume-binding-authority` → UT binding `status='prepared'`, independent `binding_uuid`; `legacy-bind status` shows `is_quiescent true` |
| 14 | Cutover status | no | `wp universal-telegram cutover status` (no open run) |
| 15 | **Cutover begin** | **YES — inserts a `cutover_runs` row** | `wp universal-telegram cutover begin --cohort-file=<path>` → prints run uuid, `state=prepared`. Gated on `quiescent` + no open run + backlog 0 + non-empty file |
| 16 | Inject deferred updates | writes buffered rows to the disposable DB | `DeferredUpdateRepository::buffer(...)` for the reply, its duplicate, `/claim`, `forum_topic_closed`, and the transient-failure row — all distinct `(bot_id, update_id)` |
| 17 | Cutover recover (diagnosis) | no | `wp universal-telegram cutover recover` (read-only) |
| 18 | Cutover activate | **yes** | `wp universal-telegram cutover activate --run=<uuid> --cohort-file=<path> --assume-cutover-authority` → cohort binding `prepared → active`, `cas_version` pre-run+1; run `state=activated` |
| 19 | Post-activation binding check | no | `SELECT binding_uuid, support_conversation_uuid, status, cas_version` before/after (assert `binding_uuid ≠ support_conversation_uuid`); `cutover_activation_audit` + `cutover_transitions` share one `cutover_run_id` |
| 20 | Quiescence exit | **yes** | `wp universal-telegram quiescence exit --assume-quiescence-authority` → `replaying` |
| 21 | Replay (repeat until settled) | **yes** | `wp universal-telegram quiescence replay-deferred-updates` — expect: handed off (reply), converged (duplicate), handed off (`/claim`), handed off (`forum_topic_closed` → `report_channel_unavailable`), transient row retried then `Replayed` / `retried_success`; re-run until `State is now: idle` |
| 22 | Backlog / idle assertion | no | `wp universal-telegram quiescence status` → `idle`, backlog 0; `cutover status` backlog 0 |
| 23 | Confirm-complete | **yes** | `wp universal-telegram cutover confirm-complete --run=<uuid> --assume-cutover-authority` → `state=complete` |
| 24 | Post-activation routing checks | no | fresh post-idle synthetic inbound for the cohort topic → routed via the Support Chat adapter (`try_handle` claims it, binding `active`); fresh inbound for a non-cohort legacy topic → still legacy |
| 25 | No-leak audit | no | `SHOW COLUMNS` + filtered `SELECT *` on `legacy_handoff_map`, `quiescence_deferred_updates` incident columns, `cutover_runs` / `cutover_transitions` / `cutover_activation_audit` |
| 26 | Controlled exit / return to idle | — | confirm quiescence `idle`, no open mutating run, `cutover status` `state=complete` — teardown, not a production rollback |
| 27 | Cleanup + evidence capture | **yes (teardown)** | `docker compose -f docker/docker-compose.yml -f docker/docker-compose.interop.yml down -v`; `docker volume ls` diff; `docker compose ps` empty; assemble the §9 evidence bundle |

**Run 1 handoff assertions (revised — the F1-corrected wire identity):**

- The wire request body for every handed-off row carries `channel_case_ref` ==
  `$binding->support_conversation_uuid()` == the cohort member's Support Chat conversation UUID,
  and **never** `$binding->binding_uuid()`.
- Exactly **one** Support Chat domain effect per handed-off row (a `conversation_messages` row
  for the reply; an assignment change for `/claim`; a `ChannelStatusRepository` degraded row for
  `forum_topic_closed`) **and** exactly **one** `legacy_handoff_map` row with server-derived
  `kind`, `channel_case_ref` = the conversation UUID, `target_message_uuid` populated only for
  `kind='message'`.
- `quiescence_deferred_updates.handed_off_at` is stamped **only after** `{ok:true}`.
- The re-presented `(bot_id, update_id)` converges silently — no second Support Chat message, no
  second map row.

### 7.2 Run 2 — Phase-B quiescence-loss recovery (REQUIRED, separate disposable run)

**Unchanged from v1 §7.2** — steps 1–9 as Run 1, then the mid-reconcile injection forcing
`REFUSED_NOT_QUIESCENT`, the hard gate (no `legacy-bind` / `cutover begin` / `cutover activate`),
`exit → replaying`, drain the injected row through **legacy `process_update()`** (not handed
off, not an incident, no `legacy_handoff_map` row), backlog 0 + `idle`, re-`enter` + `confirm`,
re-run Phase B successfully, then continue as Run 1 from step 11 (including the new step 11a
F1-correction gate). The injected update targets a topic with **no active binding**, so the
handoff path — and therefore F1's corrected classifier — is not exercised by the recovery
itself; the continuation into activate → replay → `confirm-complete` is.

### 7.3 Run 3 — incident detection and safe blocking (REQUIRED, separate disposable run) — extended

Steps 1–18 as Run 1 (through `cutover begin` + `activate`), then run the **incident family**.
Run 3 does **not** reach `confirm-complete` — it ends blocked-as-designed for each incident it
injects, and the incident row is never mutated to drain the backlog.

| # | Step | Mutating? | Command / action | Expected |
|---|---|---|---|---|
| A | Inject one **permanently-undecryptable** synthetic row | buffered-row write | `DeferredUpdateRepository::buffer(...)` with ciphertext that cannot decrypt under its own AAD, distinct `(bot_id, update_id)` | pre-dispatch incident `decrypt_failed` |
| A2 | **(new)** Inject one row whose active binding's `support_conversation_uuid` points at a Support Chat conversation that does not exist (e.g. deleted on the SC side) | buffered-row write | distinct `(bot_id, update_id)` | Support Chat `404 not_found` → `finish()` → `OUTCOME_INCIDENT` **`unresolved_case_reference`** |
| A3 | **(new)** Inject one row that produces a deterministic Support Chat refusal — a buffered operator reply > 4096 characters (`400 invalid_body`), and/or a buffered `/resolve` against an already-resolved conversation (`409 invalid_transition`) | buffered-row write | distinct `(bot_id, update_id)` each | `finish()` → `OUTCOME_INCIDENT` **`handoff_rejected`** |
| A4 | **(new)** Inject one row whose `200` Contract response is `ok:false` with an **unrecognised `reason` string** (test double / fault injection) | buffered-row write | distinct `(bot_id, update_id)` | `finish()` → `OUTCOME_INCIDENT` **`handoff_rejected`** (fail-closed), **never** `OUTCOME_RETRY_TRANSIENT` |
| B | Exit + replay | **yes** | `quiescence exit`; `quiescence replay-deferred-updates` | reports `N incident(s)` with the reasons above; `Replayed`/`handed off` counts for any non-incident fixtures only |
| C | Assert blocking | no | — | `replaying → idle` refused; `wp universal-telegram cutover confirm-complete …` refused; `cutover status` backlog > 0 |
| D | Assert evidence preserved | no | — | every incident row's ciphertext + `incident_recorded_at` + every `cutover_*` audit row **unchanged**; `incident_resolved_at` **NULL**; no `legacy_handoff_map` row for any incident row; **no mutation of any incident row is attempted** |
| E | Documented "blocked-as-designed" outcome | — | — | Run 3 does not reach `confirm-complete`; recorded in `NOTES.md`, not a failure |
| F | Teardown | **yes** | `docker compose … down -v` | the incident rows are destroyed with the disposable DB — the permanent-evidence rule holds *within the run's lifetime*; teardown is total, not selective |

**The incident row is permanent evidence for the lifetime of the run.** This runbook never
instructs mutating, overwriting, or replacing an incident row's ciphertext or any of its columns
to make replay or completion succeed. A remediable path is exercised only via (a) separate
disposable runs — incident-blocking in Run 3, a genuinely-remediable transient-failure retry in
Run 1 — or (b) distinct synthetic rows with different `(bot_id, update_id)` within one run. The
original incident row is never modified.

### 7.4 Optional later scenarios (each its own separately-approved run, under Approval B)

**Unchanged from v1 §7.4**, with one addition:

1. Compensation (two-member cohort, one forced `active` before `activate`; full compensation, `cas_version` pre-run+2, `state=activation_failed`, zero net binding change).
2. `incident-acknowledge` interface (§7.5, synthetic fixture only).
3. `unsupported_command` / `unmapped_sender` / `parse_failed` incidents — one fixture each.
4. `handoff_provenance_conflict` — pre-seed a mismatched `legacy_handoff_map` row; prove `409` + UT incident + no Support Chat write.
5. Crash-and-resume — interrupt `activate` mid-saga; re-run with the identical cohort file; idempotent resume.
6. Tier 2 realism run (only after B1/B2 resolved).
7. **(new)** `incident-acknowledge` over an `unresolved_case_reference` / `handoff_rejected` incident — the §7.5 synthetic scenario, proving acknowledgement stamps only `incident_resolved_at` / `incident_resolution='po_acknowledged_terminal'` / `incident_po_decision_ref`, never `replayed_at` / `handed_off_at`, and produces no `legacy_handoff_map` row.

### 7.5 Incident and terminal-acknowledgement handling

**Unchanged from v1 §7.5.** Run 3 must test incident detection and safe blocking and end
blocked-as-designed. `incident-acknowledge` is **NOT** used to make a run pass; it is rehearsed
only as an explicitly separate optional scenario, with a synthetic deferred-update fixture
genuinely unrecoverable by construction, an **opaque** `--po-decision-ref` matching
`/^[A-Za-z0-9._\/-]{1,191}$/` pointing at a synthetic pre-created rehearsal decision-record file
(e.g. `rehearsal/incident-ack-fixture-1`), an explicit Product-Owner-authority-simulation note
in `NOTES.md`, and proof afterward that `replayed_at` and `handed_off_at` remain NULL and no
Support Chat `legacy_handoff_map` row or false provenance stamp was produced anywhere.

### 7.6 Cases that stay in automated integration coverage (not repeated operationally)

**As v1 §7.6**, plus the F1 regression set, all CI-adjacent and run locally on both WP/PHP
variants via `bin/docker/test-integration-interop.sh`:

- `tests/integration/Interop/CutoverTier1HandoffResolutionTest.php` — the inverted F1
  characterization test (`test_handoff_resolves_a_real_legacy_bind_prepared_binding`) + the
  degenerate-case guard.
- `tests/integration/Interop/CutoverHandoffIntegrationTest.php` — 7 rewritten cases (real
  distinct `binding_uuid`) + `test_unresolvable_conversation_uuid_becomes_unresolved_case_reference_incident`,
  `test_deterministic_sc_refusal_becomes_handoff_rejected_incident`,
  `test_wire_channel_case_ref_is_the_conversation_uuid_never_the_binding_uuid`.
- `tests/unit/Migration/CutoverReplayFailureClassifierTest.php` — the exhaustive `(status, reason)`
  classification table, a totality guard, and "a novel reason never becomes retryable".

## 8. Preconditions and hard stop conditions

### 8.1 Preconditions that must ALL hold before any mutating rehearsal command (revised)

1. The applicable authorization is recorded — the **Approval A addendum** (§10; recorded 2026-08-28, authorizing exactly one Tier 1 re-attempt) for the single Tier 1 run under v2; **Approval B** (which itself requires B1 + B2 resolved and Tier 1 PASS) for a Tier 2 run. (B5)
2. Both checkouts `git rev-parse HEAD` == the immutable Tier 1 execution baselines (`6eed022…` / `4f833c3…`), those exact commits verified to exist on freshly-fetched origin; `git status` clean; the checkouts are the throwaway pair, never `/opt/biopentra/dev/*`. (A1)
3. Disposable env verified fresh: no plugin tables before install; after install, schema UT `36` / SC `11`; `cutover status` / `quiescence status` / `legacy-bind status` all report no open run / `idle` / no prepared bindings. (A8)
4. Both plugins mutually paired; discovery `channel_available:true` with the six cutover ops on the peer allow-list. (A2)
5. `docker volume ls` snapshot captured; `docker compose config` reviewed; for Tier 2, isolation of the instance and bot demonstrated. (A7)
6. The synthetic cohort file exists, is readable, and every line is a synthetic UUID created by this rehearsal's own fixtures.
7. Evidence-capture directory created (§9); redaction rules understood (§5 / v1 §5.6).
8. `cutover recover` re-confirmed read-only against source at the pinned SHA; `cutover begin` and `cutover activate` understood as mutating with no dry-run (B3).
9. **(new — A9 / former F1 blocker)** The Run 1 step 11a F1-correction gate has passed in *this* disposable environment: a real `legacy-bind`-prepared binding with `binding_uuid ≠ support_conversation_uuid` is handed off (`OUTCOME_HANDED_OFF`), producing exactly one `legacy_handoff_map` row whose `channel_case_ref` is the Support Chat conversation UUID. **`cutover begin` is not run until this holds.**

### 8.2 Hard stop conditions

**All of v1 §8.2 applies verbatim** (SHA/version mismatch; environment not provably isolated;
unexpected Phase A/B counts; `cutover begin` preflight/gate failure; quiescence failure or
stale/false provider response; `REFUSED_NOT_QUIESCENT`; recovery sequence incomplete; non-empty
backlog when a final state requires empty; unresolved incident; non-prepared/mismatched binding;
pairing/authentication failure; unexpected external Telegram traffic; `cas_version` not strictly
monotonic; any plaintext/content-derived value in a handoff-map/incident/audit row). For every
stop condition: **halt immediately, do not run the next mutating command, capture the listed
evidence, and escalate to the Product Owner.** No `force-idle`, no `discard`, no
`silent-abandon`, no hand-editing of an incident row — none exists in the code and none is to be
invented.

**Added stop conditions under v2:**

| Stop condition | Detected by | Safe immediate action | Evidence to retain |
|---|---|---|---|
| The F1-correction gate (§8.1 precondition 9 / Run 1 step 11a) does not hold — a real `legacy-bind` binding's handoff returns anything other than `OUTCOME_HANDED_OFF`, or the `legacy_handoff_map` row's `channel_case_ref` equals `binding_uuid` | Run 1 step 11a assertions; `CutoverTier1HandoffResolutionTest` re-run | Halt before `cutover begin`; the F1 correction is not effective in this environment — verify the checkout is at the v2 SHAs and rebuild; escalate | step 11a stdout, the `legacy_handoff_map` row (filtered), `git rev-parse`, the interop test result |
| An `unresolved_case_reference` or `handoff_rejected` incident is "resolved" by any means other than a genuine retry that succeeded or the §7.5 synthetic `incident-acknowledge` scenario | operator checklist; `incident_resolution` value inconsistent with the replay log | Halt; treat as a process violation; the run does not pass | the incident row's `incident_resolution` / `incident_resolved_at` / `incident_po_decision_ref`, the `replay-deferred-updates` stdout history |
| `finish()` maps an unrecognised `ok:false` reason to `OUTCOME_RETRY_TRANSIENT` (a non-decreasing retryable count with no matching transient reason in the frozen set) | `replay-deferred-updates` retryable count never decreases; `SELECT` shows a row neither handed off, nor replayed, nor an incident, after ≥ 2 passes | Halt; this contradicts ADR-0043 §3 (fail-closed) — treat as a correctness finding | per-pass `replay-deferred-updates` stdout, the offending row's disposition columns, the Contract response `reason` if captured |

## 9. Success criteria and acceptance evidence

**All of v1 §9 applies**, with these revisions:

- **Criterion 9** (UT→SC provenance handoff): the `legacy_handoff_map` row's `channel_case_ref`
  is the **Support Chat conversation UUID** (equal to `$binding->support_conversation_uuid()`),
  **never** `$binding->binding_uuid()`. Evidence must include a `SELECT binding_uuid,
  support_conversation_uuid` on the binding row and the wire request body showing
  `channel_case_ref` = the conversation UUID.
- **New criterion 9a — F1-correction gate**: Run 1 step 11a passed — a real
  `legacy-bind`-prepared binding (`binding_uuid ≠ support_conversation_uuid`) is handed off
  (`OUTCOME_HANDED_OFF`, one map row, `handed_off_at` stamped) in the disposable env before
  `cutover begin`.
- **New criterion 10a — classified fail-closed incidents (Run 3)**: an injected `404`
  → `unresolved_case_reference`; an injected deterministic `400`/`409` refusal and an injected
  unrecognised `ok:false` reason → `handoff_rejected`; each blocks `replaying → idle` and
  `confirm-complete`; each leaves `handed_off_at` / `replayed_at` unset and writes no
  `legacy_handoff_map` row; none is retryable; none is acknowledged to force a pass; every
  incident row is unchanged, verified again at teardown.
- **Criterion 2 / redaction**: the fixed-vocabulary allow-list now includes
  `unresolved_case_reference` and `handoff_rejected`.

### 9.1 Evidence bundle layout

**As v1 §9.1**, with `07-deferred-inject/` also holding the Run 1 step 11a F1-correction-gate
stdout + filtered `legacy_handoff_map` row, and `10-incident/` (Run 3) also holding the
`unresolved_case_reference` / `handoff_rejected` incident-row metadata (no ciphertext), the two
refusal captures per incident, and the "unchanged at teardown" proof.

### 9.2 Exact pass/fail evidence for the Tier 1 re-run under v2

A Tier 1 re-run PASSES only if **all** of the following are captured, redacted per §5:

1. **Preconditions** (`00-preconditions/`): the recorded Approval A addendum reference (recorded 2026-08-28);
   `git rev-parse HEAD` == `6eed0228286e84b4e56e0119f242b483f138a58e` (UT) and
   `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (SC) — the immutable Tier 1 execution baselines,
   those exact commits verified present on freshly-fetched origin; `git status` clean;
   `docker compose config`;
   `docker volume ls` before; `SHOW TABLES` empty of plugin tables before install; post-install
   `wp eval` schema assertion UT `36` / SC `11`; real two-way pairing + discovery
   `channel_available:true`.
2. **Both supported WP/PHP variants**, each in a **fresh disposable database**
   (`docker compose -f docker/docker-compose.yml -f docker/docker-compose.interop.yml down -v`
   before and after each):
   - **floor** — `bin/docker/test-integration-interop.sh --wp-version=6.9 --php-version=8.1`
   - **current** — `bin/docker/test-integration-interop.sh --wp-version=7.1 --php-version=8.3`
   Both must report `OK` with the full interop suite green (baseline expectation: the same 47
   tests / 722 assertions the F1 closure recorded, or higher as the suite grows), including the
   inverted `CutoverTier1HandoffResolutionTest` and the new `CutoverHandoffIntegrationTest`
   `unresolved_case_reference` / `handoff_rejected` / wire-identity cases.
3. **Runs 1, 2, 3** each reach their expected terminal state — Run 1 and Run 2 to
   `cutover confirm-complete` `state=complete` with `quiescence status` `idle` + backlog 0; Run 3
   ends **blocked-as-designed** at each injected incident, with `confirm-complete` refused and
   every incident row unchanged.
4. **F1-correction gate** (§9 criterion 9a): captured in every run that reaches `cutover begin`.
5. **No-leak audit** (§9 criterion 12): `SHOW COLUMNS` + filtered `SELECT *` on every
   cutover/handoff/incident/audit table; `Migrator::verify_step_11` green.
6. **Teardown proof** (`14-teardown/`): `docker compose … down -v` stdout; `docker volume ls`
   showing the run's DB volume gone; `docker compose ps` empty. (Tier 1 has no Telegram resource
   to delete.)

A Tier 1 re-run **FAILS** if any §8.2 hard stop condition is hit and not resolved within scope,
if either WP/PHP variant does not report `OK`, if the F1-correction gate does not hold, if any
incident row is mutated, or if any plaintext/content value is found in a handoff/incident/audit
row.

## 10. Approval texts

### 10.1 Approval A addendum — Tier 1 re-attempt under runbook v2 (RECORDED / accepted 2026-08-28)

The full text is in
[`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md)
(**Status: Accepted / recorded — Product Owner, 2026-08-28**; the "as proposed" text and decision
history are retained in that file under a clearly-labelled section, with the acceptance recorded
verbatim below it). It authorizes **exactly one (1)** Tier 1 re-attempt using **only** the
disposable container/PHPUnit interop harness against synthetic fixtures at the immutable Tier 1
execution baselines (universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` /
universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators fetch origin,
verify these exact commits exist, and check them out before execution) — including the ephemeral
Docker containers, networks, and named volumes that harness
creates intrinsically from `docker/docker-compose.yml` + `docker/docker-compose.interop.yml` for
fresh synthetic test databases and harness services, torn down by `docker compose … down -v`
after every run. It **explicitly excludes** Tier 2, any DEV VPS instance / WordPress site /
Redis service / SWAG configuration, any DNS record or TLS certificate, any Telegram network
traffic / bot token / webhook / group / topic, any credential or host-level persistent service,
any real user data, `/opt/biopentra/dev/*`, `dev.biopentra.eu`, production, and every
operational cutover action. Verbatim text reproduced in §10.3 below.

### 10.2 Approval B — Tier 2, the actual disposable DEV rehearsal (unchanged from v1 §10)

Approval B is unchanged and **cannot be signed until B1 and B2 are resolved and Tier 1 has
passed under this runbook**. Its text (v1 §10 "Approval B") applies verbatim, with the pinned
SHAs updated to the immutable Tier 1 execution baselines
(`6eed0228286e84b4e56e0119f242b483f138a58e` / `4f833c3344c3cff2adcc0227f93832c0c3a4427a`).

### 10.3 Verbatim Approval A addendum text (accepted / recorded 2026-08-28)

This is the text the Product Owner accepted verbatim on 2026-08-28. The acceptance is recorded in
`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md` under
"Product Owner acceptance — recorded 2026-08-28".

> **Product Owner authorization — SC-M03 final-cutover Tier 1 prerequisite validation, re-attempt under DEV rehearsal runbook v2 (Approval A addendum)**
>
> The original Approval A (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`)
> was consumed by the Tier 1 attempt of 2026-08-27, which was correctly halted by finding F1.
> F1 has since been corrected and merged in both repositories (universal-telegram #53 →
> `7d4cc4fecb97f862721cea0fec427ade26b46ea7`, closure #54 →
> `32f17ea904a33cdd1f9b0225ba9638f95a09d883`; universal-support-chat #26 →
> `9144cb1e2362c2be8d4c74f1461bba7ffe236575`, closure #27 →
> `5d81b5b7795ee50f3a79e535a483d7677b36d1c0`) and verified green by the real dual-plugin interop
> suite on both supported WP/PHP variants.
>
> The **immutable, Product-Owner-approved Tier 1 execution baselines** for this authorization are
> universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
> `4f833c3344c3cff2adcc0227f93832c0c3a4427a`. Before execution, operators must fetch origin,
> verify these exact commits exist, and check out these exact SHAs. These commits include DEV
> rehearsal runbook v2 and this corrected proposed Approval A addendum; their runtime trees
> remain byte-identical to the F1 implementation commits (universal-telegram `7d4cc4f`,
> universal-support-chat `9144cb1`) — no code, schema, `db_version`, test, configuration,
> workflow, or runtime change occurred after F1, only documentation. Future documentation merges
> must not alter this authorised execution baseline unless a new Product Owner approval is
> recorded.
>
> I authorize a **single Tier 1 re-attempt** of the SC-M03 final-cutover disposable automated
> operational-sequence / integration validation, exactly as described in DEV rehearsal runbook
> **v2** (`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md`) and its Support Chat
> companion, at the immutable Tier 1 execution baselines universal-telegram
> `6eed0228286e84b4e56e0119f242b483f138a58e` and universal-support-chat
> `4f833c3344c3cff2adcc0227f93832c0c3a4427a` — operators must fetch origin, verify these exact
> commits exist, and check out these exact SHAs before execution.
>
> This authorization is limited to:
> - the container/PHPUnit interop harness only — `docker/docker-compose.yml` +
>   `docker/docker-compose.interop.yml`, driven through `bin/docker/*.sh`, with
>   `docker compose … down -v` before and after every run;
> - the ephemeral Docker resources that harness creates intrinsically — Docker containers,
>   networks, and named volumes brought up by `docker/docker-compose.yml` together with
>   `docker/docker-compose.interop.yml`, solely for fresh synthetic test databases and harness
>   services, and removed by `docker compose … down -v` after every run;
> - fresh throwaway repository checkouts at the two immutable Tier 1 execution baseline SHAs
>   above — each contains DEV rehearsal runbook v2 and this Approval A addendum, and its `src/` /
>   `tests/` / configuration / CI-workflow trees are byte-identical to the F1 implementation
>   commits;
> - entirely synthetic fixture data created by the rehearsal's own code;
> - Runs 1, 2, and 3 of runbook v2 §7, including the Run 1 step 11a F1-correction gate and the
>   Run 3 `unresolved_case_reference` / `handoff_rejected` incident scenarios;
> - both supported WP/PHP variants, each in a fresh disposable database.
>
> It does **NOT** authorize, under any circumstance:
> - Tier 2 or any disposable DEV rehearsal;
> - any action against `/opt/biopentra/dev/*`, `dev.biopentra.eu`, its database, its Redis, its
>   bot(s), its webhook, its SWAG vhost, or any existing conversation;
> - any Telegram network traffic whatsoever — no bot token (real or dedicated), no `setWebhook`,
>   no `sendMessage`, no group or topic action, no `api.telegram.org` request; the harness
>   `pre_http_request` boundary must be confirmed in place before each run;
> - any real, dedicated, or newly-created Telegram bot, supergroup, or topic;
> - any real user, operator, or production conversation data in any fixture;
> - any infrastructure or resource creation beyond the ephemeral harness Docker resources named
>   above — in particular no DEV VPS instance, WordPress site, Redis service, SWAG configuration,
>   DNS record, TLS certificate, Telegram resource, credential, host-level persistent service, or
>   any resource under `/opt/biopentra/dev/*` or `dev.biopentra.eu`;
> - any production or DEV quiescence window, migration, binding preparation, cohort activation,
>   deferred-update replay outside the disposable harness, route switch, cutover, soak,
>   deployment, release, tag, rollback, deletion, or retention change;
> - any acknowledge, overwrite, hand-edit, or repair of an incident row to make a run pass, and
>   any use of `cutover incident-acknowledge` outside the explicitly synthetic §7.5 scenario;
> - any schema, `Migrator::target_version()`, `universal_support_chat_db_version`, plugin-version,
>   Contract-operation, configuration, CI-workflow, or test change.
>
> The operator must halt on any runbook v2 §8.2 hard stop condition and escalate to me. A Tier 1
> re-run is PASS only when every §9.2 evidence item is captured (redacted per §5) and teardown is
> proven; Run 3 legitimately ends "blocked-as-designed" without reaching `confirm-complete`.
>
> Approval B (Tier 2) remains a separate, later authorization and cannot take effect until this
> Tier 1 re-attempt passes and B1 and B2 are proven resolved.
>
> Signed: __________________________  Date: __________

### 10.4 Further separate authorizations

Each §7.4 optional scenario requires its own authorization referencing that scenario, and — for
the realism-dependent ones — runs only under Approval B.

## 11. Explicit non-authorizations

This document (the runbook itself) authorizes nothing; execution authority comes only from a
recorded Product Owner approval. As of 2026-08-28 the **Approval A addendum is recorded** and
authorizes **exactly one (1)** Tier 1 re-attempt at the two immutable execution baseline SHAs —
nothing more. This document does not authorize, and its existence must not be read as
authorizing: any second Tier 1 attempt; execution of Tier 2; any production or DEV quiescence window, migration,
binding preparation, cohort activation, deferred-update replay, Telegram webhook registration, or
any operational command against `dev.biopentra.eu` or production; creation of any infrastructure
— a DEV VPS instance, WordPress site, Redis service, SWAG configuration, DNS record, TLS
certificate, Telegram bot / group / topic, credential, or host-level persistent service; any
schema, plugin-version, `db_version`, configuration, test, CI-workflow, tag, release, or
deployment change; production cutover, route switch, soak, rollback, retention change, deletion,
or removal of Universal Telegram legacy UI or data.

Under the recorded Approval A addendum (§10.1 / §10.3), the single authorised Tier 1 re-attempt
may bring up only the ephemeral Docker containers, networks, and named volumes the disposable
`docker/docker-compose.yml` + `docker/docker-compose.interop.yml` harness creates intrinsically
for fresh synthetic test databases and harness services, torn down by `docker compose … down -v`
after every run — nothing else. Approval B (Tier 2) remains a separate, later Product Owner
approval, blocked on B1 + B2; a second Tier 1 attempt needs a new Product Owner approval.

## 12. Definition of done (for this documentation stage only)

- This runbook v2, its Support Chat companion v2, and the proposed Approval A addendum are
  committed on documentation-only branches, reviewed, CI-green, and merged (UT first, then SC).
- v1 is left unedited; its "Amendment A" footer already points here.
- Registries, plan indexes, and milestone §0d pages are updated **planning-only**.
- At the v2-freeze stage no acceptance record was added and the Approval A addendum was Proposed
  and unsigned. **Subsequently, on 2026-08-28, the Product Owner accepted the Approval A addendum
  verbatim** (recorded in `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`,
  "Product Owner acceptance — recorded 2026-08-28"); it authorizes exactly one (1) Tier 1
  re-attempt at the two immutable execution baseline SHAs and nothing else. No rehearsal has run.
- No code, schema, version, `db_version`, configuration, test, CI-workflow, tag, release,
  deployment, or infrastructure change is made.
