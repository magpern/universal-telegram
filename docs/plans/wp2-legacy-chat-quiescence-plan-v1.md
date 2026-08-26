# WP2 — Legacy-Chat Quiescence Implementation Plan v1

## 1. Charter and ADRs

Implements ADR-0040 (this repository) in full. Fulfils the forward
commitment made by ADR-0039 §3. Depends on Support Chat PR #9 (`a61aa09`,
SC-M03 work packages 3-4, `QuiescenceStateProvider` frozen by Support Chat
ADR-0008 §6) already being merged — confirmed at plan-drafting time.
References Support Chat's required WP3-4 closure addendum (owned in the
Support Chat repository) for the `PhaseBReconciliationService` continuous-
recheck amendment ADR-0040 §5/Consequences depends on.

## 2. Repository findings at plan-drafting time

- `origin/main` @ `5d16119` (UT), `a61aa09` (SC). Both working trees clean.
- `Queue\Dispatcher`/`WorkerRunner` (hook `universal_telegram_run_job`,
  group `universal-telegram`) confirmed shared with
  `SupportChatAdapter\Outbound\DeliverMessageService`.
- `MessageDispatcher::JOB_TYPE` (`telegram_send_message`) confirmed enqueued
  from three call sites: `Conversations\ConversationOutboundHandler`,
  `SupportChatAdapter\Outbound\DeliverMessageService`, and
  `Administration\Telegram\BotManagementController::requeue_message()`.
- `conversations.destination_id` confirmed `UNIQUE` (`Migrator::step_29`,
  "exclusive destination ownership", ADR-0031) — the join key used to
  disambiguate legacy-origin `telegram_send_message` jobs from Support Chat
  adapter jobs of the identical job type (ADR-0040 §5).
- `Core\Security\CredentialVault` confirmed as the existing at-rest
  encryption mechanism, already used identically by
  `Conversations\MessageRepository` with a per-item AAD context string.
- `Persistence\MigrationLock` confirmed as the existing CAS-via-single-
  UPDATE mechanic this design reuses (not its staleness policy).
- `Persistence\Migrator` confirmed to use hand-written
  `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE` steps, never `dbDelta()`, with
  a schema-version option gating step execution — the new tables follow
  this exact convention as new numbered steps.
- All eight gate entry points (ADR-0040 §2) confirmed present at their
  named methods.
- `Telegram\Outbound\RetentionCleanupHandler` and
  `Conversations\RetentionCleanupHandler` confirmed as the existing daily-
  sweep handlers the new deferred-row 30-day cleanup and the two sweep
  gates attach to respectively.

## 3. Assumptions and open questions

- **Assumption, evidence-backed**: the exact next unused `Migrator` step
  number and schema-version constant will be confirmed immediately before
  writing the migration step, since `Migrator.php` is long-lived and could
  gain steps between planning and implementation within the same freeze
  window. Not a blocker — mechanical.
- **Assumption, PO-confirmed** (per the executing instruction that
  authorised this work): 30-day retention for replayed deferred rows; no
  force-idle/discard path; `deleted_user` cleanup deferred during any non-
  idle state; Phase B fails closed and is retried on quiescence loss
  mid-run; no release/tag/deploy/production quiescence operation is
  authorised for this work package.
- **Open question, deferred, not a blocker**: the future cutover work
  package's handoff design for buffered updates arriving during a cutover-
  adjacent quiescence window. Explicitly out of scope (ADR-0040
  Consequences).

## 4. Architectural decisions

All architectural decisions, their alternatives, and their tradeoffs are
recorded in ADR-0040 (Decision and Alternatives sections) and are not
restated here. This plan sequences their implementation.

## 5. Directory, namespace, schema, and API impact

- New namespace `UniversalTelegram\Migration` (new directory `src/Migration/`,
  sibling in spirit to `SupportChatAdapter\Migration`'s `LegacyExportServiceV1`
  but a separate class hierarchy): `QuiescenceGate.php`, `QuiescenceState.php`
  (enum: `Idle`, `Draining`, `Quiescent`, `Replaying`), `QuiescenceStatus.php`,
  `DeferredReplayContext.php`, `DeferredUpdateRepository.php`,
  `QuiescenceTransitionRepository.php`.
- New namespace `UniversalTelegram\Migration\Cli`: `QuiescenceCommand.php`.
- Modified: `Core\Plugin.php` (construct `QuiescenceGate` in `init()`, add
  `quiescence_status()` accessor, register `QuiescenceCommand` under the
  existing `WP_CLI` guard, wire the eight entry-point gates and three sweep
  gates via constructor injection at existing composition points).
- Modified: `Conversations\Rest\ConversationsController`,
  `Telegram\Inbound\WebhookController`,
  `Administration\Conversations\ConversationActionHandler`,
  `Telegram\Commands\BotCommandDispatcher`, `AI\Draft\DraftRequestHandler`,
  `Administration\AI\ConversationDraftPanel`,
  `Administration\Telegram\BotManagementController` (deleted_user closure
  stays in `Core\Plugin.php`).
- Modified: `Conversations\RetentionCleanupHandler`,
  `Telegram\Outbound\RetentionCleanupHandler` (sweep gates + new 30-day
  deferred-row cleanup pass), `AI\Draft\AiDraftLeaseSweep`.
- Modified: `Persistence\Migrator` (three new `CREATE TABLE IF NOT EXISTS`
  steps, schema-version bump).
- No REST route added or modified in its externally reachable contract
  (existing routes gain an early-return 409, no new route).
- No public API/webhook contract change visible to Telegram or to visitors
  beyond the documented `409 quiescence_active` and buffered-`200` behavior.

## 6. Security and privacy impact

See ADR-0040 "Security and privacy impact" in full; not restated here.

## 7. Test and CI impact

WordPress-only configuration only — this work package has no WooCommerce-
gated behavior (no order/product/customer code path is touched). Full test
list is ADR-0040 §7 plus the extended list below (§9 acceptance criteria).
Existing CI workflow (`.github/workflows/ci.yml`) runs PHPUnit and
PHPCS/static analysis as configured; no new CI job is required, only new
test files and a bootstrap update if the new tables need PHPUnit fixture
registration (mirroring however existing per-table PHPUnit setup already
registers `Migrator` steps for test runs).

## 8. Work packages in execution order

**WP2.0 — job-type allow-list re-verification** (immediately before
WP2.1, mechanical): re-run the exact greps this plan's §2 findings are
based on against the implementation-time HEAD, to catch any job type added
between ADR freeze and implementation start. Acceptance: allow-list in
ADR-0040 §5 unchanged, or a documented, reviewed delta.

**WP2.1 — data model + state machine, no gates wired**
Files: `src/Migration/QuiescenceGate.php`, `QuiescenceState.php`,
`DeferredReplayContext.php`, `DeferredUpdateRepository.php`,
`QuiescenceTransitionRepository.php`, `Persistence/Migrator.php` (three new
steps). Validation: `QuiescenceGateTest` (CAS transitions, concurrent-
`enter()` resolution, audit-row insertion). Acceptance: all four states
reachable via direct `QuiescenceGate` calls in tests; no production code
path yet checks the gate.

**WP2.2a — visitor REST + webhook encrypted buffer (highest-sensitivity)**
Files: `Conversations\Rest\ConversationsController`,
`Telegram\Inbound\WebhookController` (`process_update()` extraction,
buffer-vs-process locking, `DeferredReplayContext` threading into
`BotCommandDispatcher::execute()`). Validation:
`ConversationsControllerQuiescenceTest`, `WebhookControllerQuiescenceTest`
(including duplicate-delivery idempotency and AAD-context isolation cases),
`DeferredReplayContextTest`, `BotCommandDispatcherQuiescenceTest` (forge/
bypass proof). Acceptance: every case in ADR-0040 §7's required list for
this surface passes; no plaintext assertion holds across all buffered-state
tests.

**WP2.2b — admin/Telegram-command/AI-draft/deleted_user/dead-letter gates**
Files: `Administration\Conversations\ConversationActionHandler`,
`Telegram\Commands\BotCommandDispatcher` (idle-state refusal branch, already
partly touched in WP2.2a), `AI\Draft\DraftRequestHandler`,
`Administration\AI\ConversationDraftPanel`, `Core\Plugin.php`
(`deleted_user` closures), `Administration\Telegram\BotManagementController::requeue_message()`.
Validation: `ConversationActionHandlerQuiescenceTest`,
`DraftRequestHandlerQuiescenceTest`, `DeletedUserQuiescenceTest`,
`BotManagementControllerRequeueQuiescenceTest`. Acceptance: all eight
entry points from ADR-0040 §2 refuse new work outside `idle` and behave
unchanged when `idle`.

**WP2.2c — three recurring sweeps**
Files: `Conversations\RetentionCleanupHandler`,
`Telegram\Outbound\RetentionCleanupHandler` (sweep gate + new deferred-row
30-day cleanup), `AI\Draft\AiDraftLeaseSweep`. Validation:
`RetentionCleanupHandlerQuiescenceTest` ×2, `AiDraftLeaseSweepQuiescenceTest`,
a cleanup-pass test proving only `replayed_at`-stamped rows older than 30
days are deleted and unreplayed rows are never touched. Acceptance: sweeps
skip-cycle outside `idle` without ever marking themselves failed; the
deferred-row cleanup pass keeps running regardless of quiescence state.

**WP2.3 — WP-CLI surface + `replaying`-state drain logic**
Files: `src/Migration/Cli/QuiescenceCommand.php`, the final locked
`replaying → idle` transition in `QuiescenceGate`. Depends on WP2.1 and
WP2.2a (needs `process_update()` extraction and `DeferredReplayContext`).
Validation: `QuiescenceReplayCommandTest` (failure/retry/deterministic
ordering), `QuiescenceRaceInterleavingTest` (required, two concurrent DB
transactions proving the never-strand-a-row invariant). Acceptance: `enter`/
`status`/`confirm`/`exit`/`replay-deferred-updates` all behave exactly per
ADR-0040 §6.1; the interleaving test passes under both orderings.

**WP2.4 — `quiescence_status()` accessor**
Files: `src/Migration/QuiescenceStatus.php`, `Core\Plugin.php` accessor.
Depends on WP2.1. Validation: `QuiescenceStatusBacklogTest` (`is_quiescent`
flips false the instant a row is buffered, true again once replayed and
otherwise `quiescent`). Acceptance: accessor signature matches ADR-0040 §8
exactly, frozen for Support Chat's provider to depend on.

**WP2.5 — non-interference regression proof**
Depends on WP2.2c (drain queries exist). Validation: end-to-end test
exercising `DeliverMessageService`'s full enqueue/delivery path while
`state = 'quiescent'`, asserting completely unaffected — required
permanently, not just at implementation time.

## 9. Definition of done (one-to-one with ADR-0040's requirements)

- All eight ADR-0040 §2 entry points gated and tested.
- Webhook buffer-and-replay fully implemented per ADR-0040 §3, including
  duplicate-delivery idempotency and the locked interleaving invariant.
- Three-table data model per ADR-0040 §4, added via `Migrator` steps
  following existing repository convention.
- Async/sweep drain proofs per ADR-0040 §5, including the `destination_id`-
  join refinement for `telegram_send_message` and the non-interference
  proof.
- Four-state machine and WP-CLI surface per ADR-0040 §6/§6.1.
- `quiescence_status()` accessor per ADR-0040 §8, frozen for Support Chat.
- Full test list from ADR-0040 §7 and this plan's §8 green in CI.
- No REST route, Ajax handler, shared secret, or cross-plugin SQL access
  introduced.
- No cutover, route switch, soak, rollback, production migration execution,
  Telegram binding creation, or legacy-UI removal performed.

## 10. Risks and mitigations

- **Job-type allow-list drift between freeze and implementation** —
  mitigated by WP2.0's mandatory re-verification step.
- **`telegram_send_message` origin-disambiguation query cost** (a join
  against `conversations` on every `confirm()` call) — bounded by the fact
  `confirm()` is an infrequent, operator-triggered, non-hot-path call;
  no performance mitigation required at this scale.
- **Operator forgets `replay-deferred-updates` after `exit()`** — mitigated
  by the 24-hour unreplayed-row health signal in `status` (ADR-0040 §3);
  no automatic recovery exists by design.
- **SC-side amendment to `PhaseBReconciliationService` not yet reviewed by
  the Support Chat Product Owner at the time this plan is frozen** —
  tracked as a dependency of ADR-0040's Consequences section; UT
  implementation of WP2.1-2.5 does not require that amendment to exist
  first (the accessor works standalone), but Phase B is not safe to run
  against this provider until it does.

## 11. Explicit out-of-scope list

No cutover, route switch, soak, rollback, or production migration
execution. No data deletion beyond the ADR-0040 §3 30-day replayed-row
cleanup. No Telegram binding creation. No removal of Universal Telegram's
legacy Conversations, AI, widget, or settings UI. No AI, availability,
ticket, or launcher feature work. No production quiescence operation, tag,
release, or deployment.

## 12. Definition of done — traceability

Every item in §9 above maps one-to-one to a named ADR-0040 section and a
named test in §8's work packages; there is no acceptance criterion in this
plan without a corresponding test file named above.
