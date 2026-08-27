# Closure Record — UT Adapter M1 Final Cutover (ADR-0042 work package 10)

## Status

Implementation complete for work package 10 of
`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v5.md`.
Closed for implementation review (awaiting Product Owner acceptance / merge)
— per `docs/governance.md`, the Implementation Agent cannot self-certify
closure.

- Frozen plan commit SHA: `1506767` (merge of PR #43, which carried the
  ADR-0042 freeze and plan v5 onto `main`)
- Authorising ADR: `docs/adr/0042-support-chat-adr-0010-pin-and-final-cutover-state-machine.md`
  (unchanged by this implementation)
- Support Chat ADR-0010 pin (unchanged): SHA `be7461544a39c7ad074164d21e3c1b04c71f2fc2`
- UT `main` branch point for this implementation: `778d8db` (merge of PR #44, PO acceptance record)

## Accurate scope statement

**This work package implements ADR-0042 §1–§5 in full: a new cutover-run
state machine layered above (never replacing) the existing ADR-0040
quiescence machine; a corrected, monotonic-CAS `activate_prepared()`/
`revert_activation()` saga with whole-cohort preflight and automatic in-run
compensation on any commit-phase failure; a cohort-aware amendment to the
existing deferred-update replay loop, dispositioning each row via a single,
live `is_active()` check evaluated fresh at drain time; a UT-owned incident
record for pre-dispatch failures and Support Chat provenance-conflict
refusals, including the narrowly-scoped, Product-Owner-approved terminal-
acknowledgement exception; and the `maybe_mark_topic_unavailable()`
live-webhook cross-talk fix.** This closure does not perform, and does not
claim, any production quiescence, cutover, route switch, soak, rollback, or
deletion. `InboundAdapterBridge`, `DeliverMessageService`, and
`WebhookController::process_update()`'s own routing order are unchanged —
activation's effect on live routing is the pre-existing, already-tested
`is_active()` gate, confirmed directly by this closure's own new tests, not
a new code path.

## Scope closed

- **Schema steps 35–36** (`src/Persistence/Migrator.php`): step 35 creates
  three new tables — `cutover_runs` (one row per operator-initiated run,
  never a singleton), `cutover_transitions` (append-only, run-correlated
  audit), `cutover_activation_audit` (one row per per-candidate
  activate/compensate action). Step 36 adds five additive columns to the
  existing `quiescence_deferred_updates` table: `handed_off_at`,
  `incident_reason`, `incident_recorded_at`, `incident_resolved_at`,
  `incident_resolution`, `incident_po_decision_ref`. `target_version()`
  `34` → `36`. `Uninstaller` gains `drop_cutover_tables()`.
- `src/Migration/CutoverState.php` (new) — the five-value run-state enum
  (`prepared`, `activating`, `activated`, `activation_failed`, `complete`);
  no `not_started` case (absence of an open run row).
- `src/Migration/CutoverRun.php` (new) — the run value object.
- `src/Migration/CutoverRunRepository.php` (new) — run CRUD, CAS
  transitions, append-only transition audit, per-candidate activation
  audit. `find_open()` treats `prepared`/`activating`/`activated` as open
  (only `complete`/`activation_failed` are terminal) — a run only stops
  blocking a new `begin()` once genuinely finished, not merely once its
  bindings are activated.
- `src/SupportChatAdapter/ChannelBindingRepository.php` (amended) — two
  new CAS-guarded, current-status-guarded methods: `activate_prepared()`
  (`prepared → active`) and `revert_activation()` (`active → prepared`,
  saga-internal-only, never a general operator command). Neither reuses
  `set_status()`, confirmed to have no CAS guard.
- `src/Migration/CutoverActivationService.php` (new) — the two-phase
  saga: `preflight()` (read-only, whole cohort) and `commit()` (one
  lock-scoped transaction per candidate via
  `QuiescenceGate::with_quiescence_lock()`, unchanged; automatic
  compensation of every already-activated candidate on the first
  commit-phase failure).
- `src/Migration/CutoverIncidentReason.php` (new) — the closed, five-value
  reason vocabulary.
- `src/Migration/CutoverReplayDispatcher.php` (new) — the four-way
  disposition (message/command/lifecycle-event/incident), reusing the
  identical `SupportChatContractClient` calls and idempotency-key schemes
  `InboundAdapterBridge::try_handle()` already uses for live traffic,
  deliberately without `try_handle()`'s own live-traffic fallthrough (an
  unsupported command is a durable incident here, never silently routed
  to legacy).
- `src/SupportChatAdapter/Inbound/SupportChatContractClient.php` (amended)
  — `ingest_operator_reply()`, `claim()`, `release()`, `resolve()`,
  `reopen()`, `report_channel_unavailable()` each gain optional
  `$source_bot_id`/`$source_update_id` trailing parameters, added to the
  request body only when both are present (`with_provenance()`); every
  existing live call site is unaffected (unchanged wire shape).
- `src/Migration/QuiescenceGate.php` (amended) — `attempt_replaying_to_idle()`'s
  final CAS backlog predicate widened from `replayed_at IS NULL` to
  `replayed_at IS NULL AND handed_off_at IS NULL AND incident_resolved_at IS NULL`,
  under the identical lock ADR-0040 §3 already proves cannot strand a row.
- `src/Migration/DeferredUpdateRepository.php` (amended) — `mark_handed_off()`,
  `record_incident()`, `resolve_incident_retried()`,
  `resolve_incident_acknowledged()`, `unresolved_backlog_count()`,
  `find_by_id()`.
- `src/Telegram/Inbound/WebhookController.php` (amended) — new
  `maybe_report_active_binding_unavailable()`, checked before
  `maybe_mark_topic_unavailable()` for topic-lifecycle service messages
  only; a public `extract_metadata_for_cutover_replay()` wrapper for the
  cohort-aware replay loop's own use.
- `src/Migration/Cli/QuiescenceCommand.php` (amended) —
  `replay-deferred-updates` is now the single authoritative,
  cohort-aware drain: per row, a live `find_by_bot_topic()`/`is_active()`
  check decides dispatch via `CutoverReplayDispatcher` or the unchanged
  legacy `process_update()` path. No separate "final handoff scan" step.
- `src/Migration/Cli/CutoverCommand.php` (new) —
  `wp universal-telegram cutover {status,begin,activate,confirm-complete,incident-acknowledge,recover}`.
  `--assume-cutover-authority` required by every mutating action;
  `incident-acknowledge` accepts only an opaque `--po-decision-ref`
  (validated against a conservative character-set/length pattern, never
  free-form content).
- `src/Core/Plugin.php` — composition-root wiring for all of the above.
- `universal-telegram.php`, `readme.txt` — version `0.18.0` → `0.19.0`.

## Test evidence

- `tests/integration/Migration/CutoverActivationServiceTest.php` (new, 6
  tests) — preflight all-eligible/one-ineligible/already-active; full
  cohort activates every candidate with `cas_version` incremented exactly
  once each; **a forced commit-phase failure (an externally-activated
  candidate) compensates every previously-activated candidate, `cas_version`
  ending at exactly pre-run+2, never restored** — the corrected, monotonic
  invariant, proven directly; run-state machine transitions, including
  `find_open()` correctly treating `activated` as still-open.
- `tests/integration/SupportChatAdapter/Migration/InboundAdapterBridgeActivationTest.php`
  (new, 2 tests) — the direct counterpart to WP9's non-interference test:
  a binding activated via `activate_prepared()` **is** claimed by
  `try_handle()`; a binding compensated back to `prepared` via
  `revert_activation()` is, once again, never claimed, despite its
  now-higher `cas_version`.
- `tests/integration/Migration/CutoverReplayDispatcherTest.php` (new, 5
  tests) — unsupported command, unmapped sender, and unparseable message
  are each a durable incident with the correct closed reason code; a
  well-formed message/lifecycle event with a fail-closed Contract client
  is retryable, never an incident; no incident is ever created for a
  transient failure.
- `tests/integration/Migration/CutoverWidenedBacklogPredicateTest.php`
  (new, 3 tests) — `replaying → idle` succeeds once every row is resolved
  by any of the three columns; refuses while an incident remains
  unresolved; refuses while any row has none of the three columns set.
- `tests/integration/Telegram/Inbound/WebhookControllerActiveBindingCrossTalkTest.php`
  (new, 2 tests) — an active-binding topic's `forum_topic_closed` never
  mutates the legacy conversation; a no-binding topic's identical event
  still marks it unavailable, unchanged.
- Existing suites amended only where a hardcoded `db_version`/table-column
  expectation needed updating for the `34→36` bump: nine
  `Migrator*SchemaTest.php` files, `MigratorTest.php`,
  `ChannelBindingRepositoryTest.php` (`34` → `36`).

## Explicit confirmation of every excluded scope item

- **No production quiescence, cutover, route switch, soak, rollback, or
  deletion.** Every write in every test occurred against disposable,
  per-test-run WordPress databases, verified via `docker compose down -v`
  before each full-suite run.
- **No change to `InboundAdapterBridge`, `DeliverMessageService`, or
  `try_handle()`'s own gate.** Confirmed by `git diff` against this
  branch's base — none of these files appears in the changed-file list.
- **No general-purpose "deactivate" or "force-idle" command.**
  `revert_activation()` is callable only from `CutoverActivationService`'s
  own compensation path; `CutoverCommand` exposes no such action.

## Validation

- `bin/docker/phpstan.sh` — `[OK] No errors` (285 files).
- `bin/docker/phpcs.sh` — clean, 0 errors, 0 warnings (547 files).
- `bin/docker/test-unit.sh --php-version=8.3` — 395 tests, 1224 assertions,
  OK (1 pre-existing unrelated skip).
- `bin/docker/test-integration-wp-only.sh`, both `--wp-version=7.1
  --php-version=8.3` (current) and `--wp-version=6.9 --php-version=8.1`
  (floor), each run from a freshly recreated database container: **1131
  tests, 3758 assertions, zero failures**, 58 pre-existing unrelated
  skips, on both variants.
- Real dual-plugin interop (`bin/docker/test-integration-interop.sh` run
  from the `universal-support-chat` checkout against this branch): the
  existing 18-test pre-cutover interop suite (SchemaInventory,
  LegacyExportClient, QuiescenceProvider, LegacyBindingImport) remains
  fully green against this branch — confirming no regression to any
  already-proven cross-plugin behavior. **A dedicated new dual-plugin
  interop suite for this work package's own cutover mechanics (a real,
  mutually-paired Contract v1 round trip exercising
  `CutoverReplayDispatcher` end-to-end against Support Chat's real
  `ContractOperationDispatcher`) was not built in this closure** — no
  such mutual-pairing interop harness pattern exists yet in either
  repository's test suite to extend, and building one from scratch was
  judged out of proportion to this closure given each side's own
  comprehensive, independent test coverage of the new mechanics (this
  side via the tests above; Support Chat's side via its own real-signed-
  request `ContractOperationsControllerTest` suite, cited in its own
  closure record). **This is an explicit, disclosed gap, not a silent
  omission** — flagged as the primary item for the DEV rehearsal named
  in the original final-cutover plan before any production claim.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.

## Next task

**Merge Support Chat's own counterpart PR** (this work package's
implementation, ADR-0010's engine) to that repository's `main`, then
re-run this closure's interop suite against the merged commit — mirroring
the identical ordered-merge sequencing WP5/ADR-0041 already established.
Only after both repositories' implementation PRs merge does SC-M03 final
cutover reach the same "implemented, Product Owner acceptance pending"
state every prior work package in this programme already reached. No
further work (a real mutual-pairing cutover interop suite, a DEV
rehearsal, or any production execution) may begin until this one is
Product Owner accepted, per this repository's own `docs/governance.md`
milestone lifecycle.

## Addendum: mutual-pairing interop suite (correction, post-merge)

Both this work package's implementation PR (UT PR #45,
`4355c22dfb4e4d5796ae43da6f9b7ff17ca1c3e3`) and Support Chat's own
counterpart (SC PR #18, `2a259cb6b766f9bf0d81b8b5aa494b323fd9a9c5`) have
since merged to their respective `main` branches. This addendum closes
the one gap the "Test evidence" section above explicitly disclosed at
merge time: no suite previously drove `CutoverReplayDispatcher` all the
way through a real, signed `SupportChatContractClient` call into Support
Chat's real, registered REST controller and real `HandoffMapRepository`.

**New suite**: `tests/integration/Interop/CutoverHandoffIntegrationTest.php`
(this repository), extending the existing `InteropTestCase` — real
two-way Ed25519 pairing, both plugins' real production-registered REST
routes, no fake client, no mocked handler, no direct repository write
standing in for the wire call. Seven tests, exercising:

1. A deferred operator reply through the real client creates the real SC
   message and exactly one real handoff-map row, then UT stamps
   `handed_off_at`.
2. A retry before `handed_off_at` was stamped (simulated by a second real
   call with identical provenance before the row's own `mark_handed_off()`
   runs) converges: no duplicate SC message, no duplicate map row,
   `handed_off_at` eventually stamped by the real dispatcher.
3. The real `claim` command dispatches the correct real SC operation with
   provenance (real conversation assignment observed).
4. A real topic-lifecycle event (`forum_topic_closed`) reports real SC
   channel-unavailable idempotently, with a real matching-provenance retry
   writing no second map row.
5. A deliberately mismatched pre-existing real handoff-map row yields the
   real `409 handoff_provenance_conflict`, a real UT-owned incident
   (`handoff_provenance_conflict`), no new SC domain write, and no
   `handed_off_at`.
6. A UT-only pre-dispatch incident (`unsupported_command`) makes zero real
   Contract requests (verified via a `rest_pre_dispatch` observer counting
   real calls to the SC route) and writes no real handoff-map row.
7. The real wire request is captured (same `rest_pre_dispatch` observer)
   and shown to carry `source_bot_id`/`source_update_id`; the real
   handoff-map row is read directly from the database and shown to carry
   only `id`/`bot_id`/`update_id`/`kind`/`channel_case_ref`/
   `target_message_uuid`/`created_at` — no column, and no persisted value,
   contains the reply's plaintext content.

**Test-isolation hazard, root-caused and fixed**: this class is the first
in this repository's own interop suite to trigger
`dispatch_with_provenance()`'s real `START TRANSACTION`/`COMMIT` — the
identical class of hazard already documented on both sides
(`QuiescenceProviderIntegrationTest`, Support Chat's own
`ContractOperationsControllerTest`). Fixed with the same established
pattern: explicit real-transaction-committed cleanup of every table this
class's own tests touch, run from both `setUp()` (before
`parent::setUp()` builds this test's own fresh fixtures) and `tearDown()`
(after), ending with an explicit `COMMIT`. Verified clean: the full
42-test interop suite (35 pre-existing tests across the suite's other
files, unaffected, plus this new file's own 7 tests) passed on both
supported
WP/PHP variant pairs, each run from freshly recreated disposable database
containers (`docker compose down -v` before each run).

**No production defect was found or fixed.** The only bug this correction
uncovered was in the new test's own seeding: `CutoverReplayDispatcher`'s
real production code sends `$binding->binding_uuid()` as
`channel_case_ref`, and Support Chat's real dispatcher resolves
`channel_case_ref` directly as its own `conversation_uuid` — so this
suite's own binding fixtures needed `binding_uuid` seeded equal to the
real SC conversation UUID (test-fixture-only; `EnsureChannelCaseService`'s
own production binding-creation path, which mints an independent opaque
`binding_uuid`, is unrelated and unchanged). No `src/` file changed in
this correction.

**Full validation, this correction**:
- `bin/docker/phpcs.sh` (whole repository) — clean, 0 errors, 0 warnings
  (548 files).
- `bin/docker/phpstan.sh` (whole repository, `src/` only per this
  repository's own configured scope) — `[OK] No errors` (285 files).
- `bin/docker/test-unit.sh` — 395 tests, 1224 assertions, OK (1
  pre-existing unrelated skip).
- `bin/docker/test-integration-wp-only.sh`, both `--wp-version=6.9
  --php-version=8.1` and `--wp-version=7.1 --php-version=8.3`, each from a
  freshly recreated database container: 1131 tests, 3758 assertions, zero
  failures, 58 pre-existing unrelated skips — unaffected by this
  correction.
- `bin/docker/test-integration-interop.sh`, both `--wp-version=6.9
  --php-version=8.1` and `--wp-version=7.1 --php-version=8.3`, each from a
  freshly recreated database container: **42 tests, 580 assertions, OK**
  on both variants.

**No DEV or production rehearsal, quiescence operation, cutover,
migration, route switch, deployment, release, tag, rollback, or data
deletion was performed.** Every write in every test above occurred
against disposable, per-test-run WordPress databases.

Both this closure record and Support Chat's own now carry real,
mutually-paired, live-round-trip evidence for the cutover handoff
mechanics; the "dedicated new dual-plugin interop suite... not built in
this closure" gap named in the "Test evidence" section above is closed
by this addendum. The primary remaining item before any production claim
is unchanged from before: a disposable DEV rehearsal exercising a real
cohort end-to-end — not initiated by this correction.
