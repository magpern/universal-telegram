# Closure Record — SC-M03 Final-Cutover Disposable DEV Rehearsal, Tier 1

> **CLOSED — superseded by [ADR-0044](../adr/0044-universal-telegram-transport-only-retire-legacy-chat-and-cutover.md) (2026-08-28).** Universal Telegram becomes transport/adapter only; its legacy website chat is retired and **discarded, not migrated**. There is no UT→SC data migration, no cutover, and no Tier 2 rehearsal; the proposed Approval B is withdrawn unsigned. This document is retained unedited as a historical record.


> **Addendum 2026-08-28 (does not alter this record).** F1 was corrected and merged in both
> repositories; DEV rehearsal runbook v2 supersedes v1; and the single Product-Owner-authorised
> Tier 1 re-attempt under v2 **was executed on 2026-08-28 and PASSED** on both supported WP/PHP
> variants. See [`sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`](sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md).
> The run-by-run outcomes and finding detail below are retained unchanged as the historical
> record of the halted first attempt.

## Status

**HALTED at the UT→SC deferred-update handoff phase by finding F1** (a production-behaviour gap,
not a test-harness gap). The disposable harness itself is validated; the constituent phases up to
the handoff are proven by existing coverage plus one new characterization test; **the full Tier 1
operational sequence cannot reach a PASS** while F1 stands, and **Tier 2 is now blocked on F1 in
addition to B1 and B2.**

- **No production runtime code was altered.**
- **No bypass, force-idle, discard, incident-acknowledge, or `binding_uuid == conversation_uuid`
  fixture shortcut was used to manufacture a pass.**
- **No DEV VPS or production action, and no Tier 2 infrastructure, occurred.** Every write in
  every run occurred against disposable, per-run WordPress test databases, `docker compose … down
  -v` before and after.

## Authority

Product Owner **Approval A** — recorded in
[`sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`](sc-m03-final-cutover-dev-rehearsal-tier1-approval.md)
(merged: Universal Telegram `528a92e2f285f979626fe68620f531bcc2ca93a9`, Universal Support Chat
`ad3f8f2728571485405e02951f3caa2201609955`). Approval A authorizes only fresh throwaway
checkouts + the disposable container/PHPUnit interop harness, synthetic fixtures, and zero
Telegram network traffic. It does not authorize Tier 2.

## Environment (proof no DEV checkout was touched)

- Fresh throwaway checkouts, never `/opt/biopentra/dev/*`:
  - `universal-telegram` @ `31519ee3ae297369118bf2deda6eae05d13a3d8b` (accepted baseline), detached HEAD, clean tree.
  - `universal-support-chat` @ `ce4691241eb843485117b323516899df916fdaf7` (accepted baseline), detached HEAD, clean tree.
- Harness: the repository's own `docker/docker-compose.yml` + `docker/docker-compose.interop.yml`
  only, with `SUPPORT_CHAT_HOST_PATH` overridden to the throwaway `universal-support-chat`
  checkout (never the default `/opt/biopentra/dev/universal-support-chat`).
- Telegram: zero network traffic. The harness's own `pre_http_request` boundary (scoped to
  `api.telegram.org`) is in place; the test bot profile carries the literal non-secret token
  string `test-token-not-a-real-secret`; no `setWebhook`, no message send, no group/topic action.
- `docker compose … down -v` before and after every run; the only surviving named volumes on the
  host (`wordpress_bridge_config`, `wordpress_bridge_gnupg`, `wordpress_bridge_pass`) belong to
  the unrelated DEV mail-worker stack and were never mounted, read, or written.

## What was validated

### 1. Baseline — the disposable harness works, on the pinned SHAs

`bin/docker/test-integration-interop.sh --wp-version=7.1 --php-version=8.3` against the two
pinned baseline SHAs: **42 tests, 580 assertions, OK** — identical to the count both final-cutover
closure records record. Evidence: `evidence/baseline-interop.log`.

### 2. New characterization test (test-harness-only, no `src/` change)

`tests/integration/Interop/CutoverTier1HandoffResolutionTest.php` — **2 tests, 41 assertions,
OK**. Extends the existing `InteropTestCase` (real two-way Ed25519 pairing, both plugins' real
registered REST routes, real repositories — no fakes). Evidence: `evidence/f1-probe.log`.

- **`test_handoff_succeeds_when_binding_uuid_equals_conversation_uuid`** — positive control: with
  the fixture convention `CutoverHandoffIntegrationTest` relies on (`binding_uuid` seeded equal to
  the Support Chat `conversation_uuid`), the real `CutoverReplayDispatcher` → real
  `SupportChatContractClient` → real Support Chat `ContractOperationDispatcher` chain returns
  `OUTCOME_HANDED_OFF` and writes one real `legacy_handoff_map` row.
- **`test_handoff_does_not_resolve_a_real_legacy_bind_prepared_binding`** — the finding: a real
  `prepared` binding produced by the real WP5 `LegacyBindingImportServiceV1` (independent opaque
  `binding_uuid`), activated via the real `ChannelBindingRepository::activate_prepared()`, with a
  real buffered operator reply for its topic — the real handoff dispatch returns
  `OUTCOME_RETRY_TRANSIENT`. `handed_off_at` is never stamped, `incident_reason` is never set, no
  Support Chat message is created, no `legacy_handoff_map` row is written.

### 3. Constituent phases already covered by existing, CI-enforced tests

These need no operational repetition (per the frozen runbook §7.6):

| Phase | Existing coverage (green on the pinned SHAs) |
|---|---|
| Phase A backfill (per-conversation transaction, high-water mark, re-encryption, exclusion reasons) | Support Chat `tests/integration/Migration/PhaseABackfillServiceTest.php`; `tests/integration/Interop/LegacyExportClientIntegrationTest.php` |
| Quiescence `enter`/`confirm`/`exit`, drain proofs, CAS transitions, webhook-vs-idle interleaving | `tests/integration/Migration/QuiescenceGateTest.php`, `QuiescenceRaceInterleavingTest.php`, `QuiescenceStatusTest.php` |
| Phase B under continuous quiescence + **quiescence-loss recovery (Run 2 core)**: refuse → `exit` → replay every buffered row through **legacy `process_update()`** → backlog 0 + `idle` → re-`enter`/`confirm` → Phase B succeeds and promotes to `migrated` | Support Chat `tests/integration/Interop/QuiescenceProviderIntegrationTest.php` tests 4 and 5 — the exact Run 2 sequence, end-to-end against the real stack. **This path never invokes the handoff, so F1 does not apply to it.** |
| `legacy-bind` producing a non-routing `prepared` binding; `try_handle()` never claims a `prepared` binding and **does** claim an `active` one | Support Chat `tests/integration/Interop/LegacyBindingImportIntegrationTest.php`; `tests/integration/SupportChatAdapter/Migration/InboundAdapterBridgeActivationTest.php`, `InboundAdapterBridgeNonInterferenceTest.php` |
| `cutover begin` (mutating — inserts a `cutover_runs` row) gates; whole-cohort preflight; `cutover activate` all-or-nothing saga; forced-failure compensation with strictly-monotonic `cas_version` (pre-run+2, never restored); idempotent resume | `tests/integration/Migration/CutoverActivationServiceTest.php` |
| **Run 3 core** — incident detection + safe blocking: a `decrypt_failed` buffered row → `record_incident`; `replaying → idle` refused; `confirm-complete` refused; ciphertext + audit retained | `tests/integration/Migration/CutoverReplayDispatcherTest.php`, `CutoverWidenedBacklogPredicateTest.php`. **The decrypt failure is pre-dispatch, so F1 does not apply.** |
| Cohort-aware handoff round-trip **with a fixture-seeded `binding_uuid == conversation_uuid`** | `tests/integration/Interop/CutoverHandoffIntegrationTest.php` (7 cases) — green, but see F1: this passes only because of the fixture convention, not with a real binding. |

## Finding F1 — cutover deferred-update handoff cannot resolve a real prepared binding

**Severity: blocks the DEV rehearsal (Tier 1 and Tier 2). Not introduced by the cutover work
package — a pre-existing seam surfaced by the rehearsal.**

**What.** The cohort-aware handoff (`CutoverReplayDispatcher`, and the identical live path in
`InboundAdapterBridge::try_handle()`) sends `$binding->binding_uuid()` as the Contract v1
`channel_case_ref` for every operation:

- `src/Migration/CutoverReplayDispatcher.php:135` (`ingest_operator_reply`), `:189-192`
  (`claim`/`release`/`resolve`/`reopen`), `:214` (`report_channel_unavailable`).
- `src/SupportChatAdapter/Inbound/SupportChatContractClient.php` passes it straight to the wire
  as `'channel_case_ref' => $channel_case_ref` — no translation.
- `src/SupportChatAdapter/Inbound/InboundAdapterBridge.php:133-134,118,162-165` — the live path
  does the same.

Support Chat's `ContractOperationDispatcher::resolve_conversation()`
(`src/ChannelContract/Rest/ContractOperationDispatcher.php:545-552`) resolves `channel_case_ref`
**only** as its own `conversation_uuid` — `$this->conversations->find_by_uuid( $ref )` — with the
docblock "Interim convention: `channel_case_ref` is the Support Chat `conversation_uuid`" and
"`conversation_uuid` for this work package — no adapter binding/… resolution" (line 26). There is
no binding→conversation resolution anywhere on the Support Chat side.

Every real binding-creation path mints an **independent** `binding_uuid` distinct from
`support_conversation_uuid`:

- `LegacyBindingImportServiceV1::import_one()` — the **only** path that produces the `prepared`
  bindings a cutover cohort activates —
  `src/SupportChatAdapter/Migration/LegacyBindingImportServiceV1.php`:
  `$this->bindings->create( wp_generate_uuid4(), (string) $candidate['support_conversation_uuid'], … )`.
- `EnsureChannelCaseService::ensure_channel_case()` (live escalation) —
  `src/SupportChatAdapter/Outbound/EnsureChannelCaseService.php:139-142`:
  `$binding_uuid = wp_generate_uuid4();` then `create( $binding_uuid, $conversation_uuid, … )`.

**Consequence for the rehearsal.** For a cohort activated from real `legacy-bind`-prepared
bindings, `replay-deferred-updates` sends the opaque `binding_uuid` as `channel_case_ref`; Support
Chat's `resolve_conversation()` returns `null`; the handler returns `error( 404, 'not_found' )`;
`CutoverReplayDispatcher::finish()` — since `'not_found' !== 'handoff_provenance_conflict'` —
returns `OUTCOME_RETRY_TRANSIENT`. The row is **never handed off and never an incident** — it is
silently retryable forever. The widened backlog predicate
(`replayed_at IS NULL AND handed_off_at IS NULL AND incident_resolved_at IS NULL`) therefore never
reaches zero, `replaying → idle` never completes, and `cutover confirm-complete` is permanently
blocked. `incident-acknowledge` does not apply (no incident is recorded).

**Why existing tests did not catch it.** `CutoverHandoffIntegrationTest` and `UtToScOperationsTest`
deliberately seed `binding_uuid == conversation_uuid` — its own docblock and the final-cutover
closure addendum call this "test-fixture-only", noting `EnsureChannelCaseService`'s production
path "mints an independent opaque `binding_uuid` … unrelated and unchanged." The rehearsal is the
first exercise of the *real* `legacy-bind` → cohort → handoff path.

**Contradiction with ADR-0010 §4.** ADR-0010 §4's schema comment says `channel_case_ref` is "the
binding UUID this call resolved to; always populated" — but the Support Chat resolver treats it as
the conversation UUID. The two halves of the frozen design disagree at the wire.

**Proposed resolution directions (require separate ADR-level review — NOT implemented here).**
Each touches the Contract v1 wire contract and/or ADR-0010 §4:

1. Support Chat `resolve_conversation()` resolves `channel_case_ref` through a binding→conversation
   mapping it owns (requires Support Chat to persist the binding UUID it minted at
   `ensure_channel_case` time — it currently does not).
2. The adapter sends `support_conversation_uuid` (which `ChannelBinding` already stores) as
   `channel_case_ref` for cutover-replay calls, and ADR-0010 §4's schema comment is corrected.
3. `LegacyBindingImportServiceV1` / `EnsureChannelCaseService` mint
   `binding_uuid == support_conversation_uuid` (collapses the two identifiers; simplest, but
   removes the opaque-ref indirection the design intended and needs a WP5/ADR-0009 amendment).

## Run-by-run outcome

| Run | Runbook target | Outcome |
|---|---|---|
| **Run 1** — authoritative happy path | Phase A → quiescence → Phase B → prepared binding → `cutover begin` → `activate` → cohort-aware replay → idempotent retry → `confirm-complete` → routing checks → teardown | **BLOCKED at cohort-aware replay by F1.** Everything through `cutover activate` is executable and proven (existing `CutoverActivationServiceTest`; the new probe activates a real `legacy-bind` binding for real). The handoff returns `OUTCOME_RETRY_TRANSIENT`; `confirm-complete` cannot be reached. Success criterion 9 ("actual UT-to-SC provenance handoff") **fails**, proven with a real assertion, not worked around. |
| **Run 2** — Phase-B quiescence-loss recovery | refuse → no bind/begin/activate → `exit` → legacy replay → `idle`/backlog 0 → re-`enter`/`confirm` → Phase B rerun succeeds → then complete the sequence | **Recovery core PASSES** (fully proven end-to-end against the real stack by `QuiescenceProviderIntegrationTest` tests 4/5 — that path drains through legacy `process_update()`, not the handoff). The *continuation* into activate → handoff → `confirm-complete` is **BLOCKED by F1**, identically to Run 1. |
| **Run 3** — incident detection + safe blocking | `decrypt_failed` fixture → incident recorded → `replaying → idle` refused → `confirm-complete` refused → incident row unchanged through teardown → blocked-as-designed | **Core PASSES** (fully proven by `CutoverReplayDispatcherTest` + `CutoverWidenedBacklogPredicateTest`; decrypt failure is pre-dispatch, F1 does not apply). No incident row was acknowledged, overwritten, or repaired. End-to-end chaining through a real activated cohort is **BLOCKED by F1** for the same reason as Run 1. |

## Quality gates (this checkout, with the new test file present)

- `bin/docker/phpcs.sh` — clean (the new test file needed 10 whitespace/array-formatting auto-fixes from `phpcbf`, applied; re-checked clean).
- `bin/docker/phpstan.sh` — `[OK] No errors` (285 files; `src/` only — the new file is under `tests/` and out of scope, matching every other test file).
- `bin/docker/test-integration-interop.sh --wp-version=7.1 --php-version=8.3` — **44 tests, 621 assertions, OK** (42 pre-existing + 2 new).
- `bin/docker/test-integration-interop.sh --wp-version=6.9 --php-version=8.1` (fresh database container) — **44 tests, 621 assertions, OK**.

### Evidence bundle (redacted — all data synthetic; no real ciphertext, token, credential, or key retained)

Raw run logs (operator workstation, not committed): `scratchpad/evidence/{baseline-interop,f1-probe,quality-gates,interop-69-fresh}.log`. Verbatim result lines:

```
BASELINE (pinned SHAs, no new test):   OK (42 tests, 580 assertions)          wp7.1/php8.3
F1 PROBE (new test only):              OK (2 tests, 41 assertions)            wp7.1/php8.3
FULL SUITE + new test:                 OK (44 tests, 621 assertions)          wp7.1/php8.3
FULL SUITE + new test (fresh DB):      OK (44 tests, 621 assertions)          wp6.9/php8.1
phpstan:                               [OK] No errors (285 files)
```

Teardown, every run: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.interop.yml down -v --remove-orphans` — container `docker-db-1` and network `docker_default` removed; `docker volume ls` shows no surviving harness volume; the only persistent host volumes (`wordpress_bridge_config`, `wordpress_bridge_gnupg`, `wordpress_bridge_pass`) belong to the unrelated DEV mail-worker stack and were never mounted or touched.

## Impact on the frozen runbook

The runbook's §1.3 / §2 "wire detail" note treated `binding_uuid == conversation_uuid` as a
fixture-seeding nuance. F1 shows it is a **structural precondition**: no real `legacy-bind` cohort
can be handed off until F1 is resolved. A runbook v2 must add F1's resolution as a hard
precondition (a new blocker, gating both tiers), and add an explicit pre-flight assertion that a
real cohort's handoff actually resolves before `cutover begin` is permitted.

## Next step

1. F1 is raised for **separate ADR-level review** (it changes the Contract v1 wire contract and/or
   ADR-0010 §4). No production code is changed by this closure.
2. Tier 1 is **re-attempted only after F1 is resolved** and the runbook is revised (v2).
3. **Tier 2 remains blocked on B1, B2, and F1.** Approval B cannot take effect until Tier 1
   passes and all three are resolved.

## Non-authorization

This closure authorizes nothing. No DEV or production quiescence, migration, binding preparation,
cohort activation, deferred-update replay, Telegram webhook, route switch, cutover, soak,
rollback, deployment, release, tag, deletion, or retention change occurred or is authorized. No
Tier 2 infrastructure was created. No production runtime code was changed.
