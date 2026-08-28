# Closure Record — SC-M03 Final-Cutover Disposable DEV Rehearsal, Tier 1 (re-attempt under runbook v2)

## Status

**PASS.** The single Product-Owner-authorised Tier 1 re-attempt was executed against the immutable
execution baselines, on both supported WP/PHP variants, from fresh throwaway checkouts and fresh
disposable databases, with `docker compose … down -v` before and after every run. Finding F1 is
confirmed cleared *in the disposable environment* by the real `legacy-bind` handoff-resolution
gate; the exhaustive fail-closed replay classifier (`unresolved_case_reference` / `handoff_rejected`)
is confirmed; every incident path blocks as designed and no incident row was mutated to force a
pass.

- **No production runtime code was altered.** The checkouts are the immutable baselines, detached
  HEAD, clean tree — runtime trees byte-identical to the F1 implementation commits.
- **No bypass, `force-idle`, `discard`, `incident-acknowledge`, or `binding_uuid == conversation_uuid`
  fixture shortcut was used to manufacture a pass.** Every binding exercised by the F1-correction
  gate is minted by the real `LegacyBindingImportServiceV1` with an independent `binding_uuid`.
- **No DEV VPS or production action, no Telegram network traffic, and no Tier 2 infrastructure.**
  Every write in every run occurred against disposable, per-run WordPress test databases; the
  `/opt/biopentra/dev/*` checkouts and `dev.biopentra.eu` were never mounted, read, or written.
- **This closure authorizes nothing.** Tier 2 remains blocked on B1 and B2 and pending Approval B.

## Authority

Product Owner **Approval A addendum**, recorded / accepted 2026-08-28 in
[`sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`](sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md)
(merged: Universal Telegram `4458ada28c25594a563d05559991e98d19598549`, Universal Support Chat
`9aaf2685bccc1655d501c7827986df1e18409f7f`). It authorises **exactly one (1)** Tier 1 re-attempt
at the two immutable baseline SHAs, in the disposable container/PHPUnit interop harness, with
synthetic fixtures and zero Telegram network traffic, on both supported WP/PHP variants — and
nothing else. This closure records that single authorised re-attempt as executed. A second Tier 1
attempt, or any change to the immutable baseline SHAs, requires a new Product Owner approval.

## Environment — proof no DEV checkout was touched

- Fresh throwaway checkouts under the operator scratchpad, never `/opt/biopentra/dev/*`:
  - `universal-telegram` @ `6eed0228286e84b4e56e0119f242b483f138a58e` (immutable Tier 1 execution
    baseline), detached HEAD, `git status --porcelain` clean.
  - `universal-support-chat` @ `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (immutable Tier 1
    execution baseline), detached HEAD, `git status --porcelain` clean.
  - Both immutable SHAs were verified to exist on `origin` (`git cat-file -t`) before checkout.
  - Runtime trees are byte-identical to the F1 implementation commits: `git diff --name-only
    7d4cc4f HEAD` (UT) and `git diff --name-only 9144cb1 HEAD` (SC) list `docs/` (and `.cursor/`)
    paths only — no `src/`, `tests/`, schema, `db_version`, configuration, or workflow change.
- Harness: the repository's own `docker/docker-compose.yml` + `docker/docker-compose.interop.yml`
  only, driven through the approved `bin/docker/*.sh` entry points, with
  `SUPPORT_CHAT_HOST_PATH` overridden to the throwaway `universal-support-chat` checkout.
  `docker compose config` was reviewed and confirms the two mounts resolve to the scratchpad
  checkouts — the default `/opt/biopentra/dev/universal-support-chat` mount is never used.
- Telegram: **zero network traffic.** The interop harness runs with WordPress' external-HTTP test
  group disabled ("Not running external-http tests"); the harness's own `pre_http_request`
  boundary (scoped to `api.telegram.org`) is in place; no bot token, `setWebhook`, `sendMessage`,
  group, or topic action occurs anywhere in the suite.
- `docker compose … down -v --remove-orphans` before and after every run; after both variants,
  `docker ps -a --filter name=t1re`, `docker volume ls | grep t1re`, and `docker network ls |
  grep t1re` are all empty, and `docker compose ps` shows no services. The only persistent host
  volumes (`wordpress_bridge_*`) belong to the unrelated DEV mail-worker stack and were never
  mounted or touched.

## What was executed — the disposable automated operational-sequence / integration validation

Per runbook v2 §4.1, Tier 1 is *"a required disposable automated operational-sequence / integration
validation in the container/PHPUnit interop harness … proving data effects, state-machine
sequencing, and CLI-equivalent service ordering of Runs 1, 2, 3"* — it is **not** the literal
manual WP-CLI operator walk-through (that realism is Tier 2, blocked on B1/B2). The re-attempt
executed the committed automated realisation of that validation on both supported variants:

| Suite (approved `bin/docker/*.sh` entry point) | floor — WP 6.9 / PHP 8.1 | current — WP 7.1 / PHP 8.3 |
|---|---|---|
| `test-integration-interop.sh` (dual-plugin real Contract v1) | **OK — 47 tests, 722 assertions** | **OK — 47 tests, 722 assertions** |
| `test-unit.sh` (incl. the exhaustive replay-failure classifier table) | **OK — 416 tests, 1265 assertions** (1 skipped) | **OK — 416 tests, 1265 assertions** (1 skipped) |
| `test-integration-wp-only.sh` (Cutover*/Quiescence*/PhaseB* service sequencing) | **OK — 1131 tests, 3758 assertions** (58 skipped) | **OK — 1131 tests, 3758 assertions** (58 skipped) |

The interop `OK (47 tests, 722 assertions)` count matches the F1 implementation closure exactly on
both variants.

### Run 1 — authoritative happy path (data effects + F1-correction gate)

Realised by `tests/integration/Interop/CutoverHandoffIntegrationTest` and
`CutoverTier1HandoffResolutionTest`, green on both variants:

- **F1-correction gate (Run 1 step 11a / §8.1 precondition 9)** —
  `CutoverTier1HandoffResolutionTest::test_handoff_resolves_a_real_legacy_bind_prepared_binding`:
  a real Support Chat conversation → real UT legacy conversation with an active topic → real
  quiescence `enter`/`confirm` → **real `LegacyBindingImportServiceV1::import_batch()`** mints a
  `prepared` binding whose `binding_uuid` is asserted **not equal** to the conversation UUID →
  real `ChannelBindingRepository::activate_prepared()` (the CAS write `cutover activate` performs)
  → real buffered operator reply → real `CutoverReplayDispatcher::dispatch()`. Result:
  `OUTCOME_HANDED_OFF`; `handed_off_at` stamped; `incident_reason` NULL; **exactly one**
  `legacy_handoff_map` row with `kind = 'message'` and `channel_case_ref` equal to the Support
  Chat conversation UUID and **not** `binding_uuid`; exactly one real Support Chat message.
  **F1's correction is effective in this environment — `cutover begin` would be permitted.**
- Deferred operator reply → one Support Chat message + one map row, then `handed_off_at` stamped.
- Re-presented `(bot_id, update_id)` before `handed_off_at` → converges, no duplicate effect.
- Supported command `/claim` → the correct real Support Chat operation with provenance.
- `forum_topic_closed` lifecycle event → real `report_channel_unavailable`, idempotent, with
  provenance; no legacy mutation.
- Wire assertion — `channel_case_ref` on every provenance-capable operation is the Support Chat
  conversation UUID, never `binding_uuid`
  (`test_wire_channel_case_ref_is_the_conversation_uuid_never_the_binding_uuid`).
- UT-only pre-dispatch incident makes no Contract request and writes no map row.

### Run 2 — Phase-B quiescence-loss recovery

Realised by the Support Chat `QuiescenceProviderIntegrationTest` sequence and the UT
`tests/integration` Quiescence*/PhaseB* suites (part of the 1131-test wp-only run, green on both
variants): Phase B promotes only while `is_quiescent()`; a mid-run buffered update forces
`REFUSED_NOT_QUIESCENT` with the in-progress row rolled back; `exit → replaying`; the injected
row drains through **legacy `process_update()`** (not handed off, not an incident, no
`legacy_handoff_map` row); backlog 0 + `idle`; re-`enter`/`confirm`; Phase B re-run promotes to
`migrated`. The continuation into activate → replay → handoff is the same corrected path proven
by Run 1's gate.

### Run 3 — incident detection and safe blocking (fail-closed classification)

Realised by `CutoverHandoffIntegrationTest`, `CutoverReplayDispatcherTest`,
`CutoverWidenedBacklogPredicateTest`, and `CutoverReplayFailureClassifierTest`, green on both
variants — Run 3 ends **blocked-as-designed** for every injected incident and no incident row is
mutated:

- **`unresolved_case_reference`** — an active binding whose `support_conversation_uuid` resolves
  to no Support Chat conversation yields a real `404 not_found` → `OUTCOME_INCIDENT`
  `unresolved_case_reference`; `handed_off_at` / `replayed_at` / `incident_resolved_at` all NULL;
  no `legacy_handoff_map` row
  (`test_unresolvable_conversation_uuid_becomes_unresolved_case_reference_incident`).
- **`handoff_rejected`** — a deterministic Support Chat refusal (an operator reply exceeding
  Support Chat's 4096-char limit → `400 invalid_body`) → `OUTCOME_INCIDENT` `handoff_rejected`;
  no map row; no Support Chat message
  (`test_deterministic_sc_refusal_becomes_handoff_rejected_incident`).
- **Unrecognised `ok:false` reason → `handoff_rejected` (never retryable)** —
  `CutoverReplayFailureClassifierTest` proves the full `(status, reason)` table:
  `404` (any reason) → `unresolved_case_reference`; `409 handoff_provenance_conflict` → its own
  code; every deterministic `400`/`409` refusal, `null` reason, and any unrecognised `ok:false`
  reason → `handoff_rejected`; only the frozen transient set (`503 request_failed`,
  `401 contract_auth_failed`, `sc_contract_not_paired`, `sc_authenticated_contract_unavailable`,
  `sc_contract_discovery_incompatible`, `sc_contract_signing_unavailable`,
  `sc_contract_transport_failed`) stays retryable; plus `Result is always retryable or a valid
  closed incident code` and `A novel reason never becomes retryable`.
- `decrypt_failed` pre-dispatch incident + the widened-backlog predicate blocking
  `replaying → idle` and `confirm-complete` — `CutoverReplayDispatcherTest` /
  `CutoverWidenedBacklogPredicateTest`.

### No-leak audit

`tests/integration/Interop/Privacy` (green on both variants): Support Chat delivered/ingested
bodies never persisted as plaintext; the Support Chat `conversation_messages` body column is
ciphertext; the UT audit never carries an ingested body. The `legacy_handoff_map` row is asserted
to carry only `id, bot_id, update_id, kind, channel_case_ref, target_message_uuid, created_at` —
no content column — and no column contains reply text
(`test_wire_request_carries_provenance_and_handoff_map_row_persists_no_content`). Support Chat's
`Migrator::verify_step_11` forbidden-column guard is part of its interop provisioning and passed.

## Evidence bundle (redacted — all data synthetic; no ciphertext, token, credential, or key retained)

Raw run logs (operator scratchpad, not committed): `scratchpad/t1re-evidence/`:

```
git rev-parse (UT / SC):                6eed0228286e84b4e56e0119f242b483f138a58e / 4f833c3344c3cff2adcc0227f93832c0c3a4427a
INTEROP  floor   wp6.9/php8.1:          OK (47 tests, 722 assertions)
INTEROP  current wp7.1/php8.3:          OK (47 tests, 722 assertions)
UNIT     floor   php8.1:                OK (416 tests, 1265 assertions, 1 skipped)
UNIT     current php8.3:                OK (416 tests, 1265 assertions, 1 skipped)
WP-ONLY  floor   wp6.9/php8.1:          OK (1131 tests, 3758 assertions, 58 skipped)
WP-ONLY  current wp7.1/php8.3:          OK (1131 tests, 3758 assertions, 58 skipped)
```

- `t1re-evidence/{floor,current}/10-interop.txt`, `11-unit.txt`, `12-wp-only.txt` + `*-exit.txt`
  (all `EXIT=0`).
- `t1re-evidence/interop-testdox.txt` — the full named-test list (47 interop tests, incl. the F1
  gate, the `unresolved_case_reference` / `handoff_rejected` incident cases, the wire-identity
  case, and the Privacy suite).
- `t1re-evidence/unit-classifier-testdox.txt` — the exhaustive classifier table (23 tests, incl.
  "A novel reason never becomes retryable").
- `t1re-evidence/{floor,current}/01-down-pre.txt`, `13-down-post.txt`, `14-volumes-after.txt` —
  teardown proof: `t1re_ut-db-1` and `t1re_ut_default` removed each time; no `t1re` container,
  volume, or network survives; `docker compose ps` empty.

Teardown, every run: `docker compose -f docker/docker-compose.yml -f
docker/docker-compose.interop.yml down -v --remove-orphans`.

## Scope boundary — what a PASS here does and does not mean

- Tier 1's automated operational-sequence / integration validation (runbook v2 §4.1, §9.2 point 2)
  is **complete and green** at the immutable baselines on both supported variants. §9.2 point 1's
  literal SHA text still cites the pre-baseline heads `33b042f` / `2000eaf`; the governing
  immutable baselines are `6eed022…` / `4f833c3…` (Approval A addendum / PO decision Addendum C),
  and the runtime trees at both pairs are byte-identical — documentation only differs.
- The literal manual WP-CLI operator walk-through of Runs 1–3 (`wp universal-telegram cutover
  begin` / `activate` / `confirm-complete` as shell invocations, real Action Scheduler drain,
  Redis object cache, authenticated webhook ingress) is **Tier 2**, which remains blocked on B1
  (no isolated full-WordPress instance) and B2 (no dedicated non-production Telegram bot) and
  pending Approval B. This closure does not change that.

## Next step

**Tier 1 is complete.** The single authorised re-attempt has been executed and passed. No further
Tier 1 run is authorised. The next possible activity is Tier 2 — the actual disposable DEV
rehearsal — which requires B1 and B2 resolved (infrastructure work) and a separate signed
Approval B. Nothing in this record authorises Tier 2, any DEV VPS action, any Telegram network
traffic, any production activity, or any operational cutover action.

## Non-authorization

This closure authorizes nothing. No DEV or production quiescence, migration, binding preparation,
cohort activation, deferred-update replay, Telegram webhook, route switch, cutover, soak,
rollback, deployment, release, tag, deletion, or retention change occurred or is authorized. No
Tier 2 infrastructure was created. No production runtime code was changed. The immutable Tier 1
execution baseline SHAs are unchanged.
