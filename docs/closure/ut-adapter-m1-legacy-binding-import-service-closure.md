# Closure Record — UT Adapter M1 Legacy Binding Preparation Boundary (ADR-0041 work package 9)

## Status

Implementation complete for work package 9 of
`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v4.md`.
Closed for implementation review (awaiting Product Owner acceptance / merge)
— per `docs/governance.md`, the Implementation Agent cannot self-certify
closure.

- Frozen plan commit SHA: `bb16caa` (merge of PR #40, which carried the
  ADR-0041 freeze and plan v4 onto `main`)
- Authorising ADR: `docs/adr/0041-support-chat-adr-0009-pin-and-legacy-binding-preparation-follow-up.md`
  (unchanged by this implementation)
- Support Chat ADR-0009 pin (unchanged): SHA `590b53ba898aa4054ec65c65965c152a3612149b`
- UT `main` branch point for this implementation: `7abb4eb` (merge of PR #40)

## Accurate scope statement

**`LegacyBindingImportServiceV1::import_batch()` is implemented exactly to
ADR-0041 §2 / Support Chat ADR-0009 §2–§5: a plain PHP class, reachable
only from within a WP-CLI process, that re-validates every candidate
against this plugin's own live conversation/topic state and its own
binding table before writing anything, and writes only
`status = 'prepared'` — never `'active'` — under any condition. This
closure does not implement, and does not claim, any Support Chat-side
candidate identification, schema, or WP-CLI command; any
`prepared → active` activation mechanism; or any modification to
`BindingImportCommand`, `InboundAdapterBridge`, `DeliverMessageService`,
`WebhookController`, or `EnsureChannelCaseService` — all remain entirely
out of scope for this work package (ADR-0041 §6).**

## Scope closed (work package 9)

- **Schema step 34** (`src/Persistence/Migrator.php`) — additive:
  `universal_telegram_support_chat_bindings.status` becomes
  `ENUM('active','unavailable','closed','prepared')`, idempotent
  (`step_34_add_prepared_binding_status()` checks the live column
  definition via `SHOW COLUMNS` before altering; `verify_step_34()`
  confirms it). `Migrator::target_version()` bumped `33` → `34`.
  `ChannelBinding::is_active()` is unchanged — a `prepared` row falls
  into its existing "not active" branch, so no routing code required any
  change.
- `src/SupportChatAdapter/ChannelBinding.php` (amended) — new
  `STATUS_PREPARED = 'prepared'` constant.
- `src/SupportChatAdapter/ChannelBindingRepository.php` (amended) —
  `create()` gains an optional `string $status = ChannelBinding::STATUS_ACTIVE`
  trailing parameter, replacing the previously hardcoded literal at the
  same call site. `BindingImportCommand`'s and `EnsureChannelCaseService`'s
  existing call sites are unchanged (they omit the new parameter and get
  today's unchanged `'active'` default) — confirmed by the full existing
  test suite remaining green (see Validation).
- `src/Migration/QuiescenceGate.php` (amended) — new
  `with_quiescence_lock( callable $work ): string`, reusing the identical
  `START TRANSACTION` / `SELECT state FROM {quiescence_state} WHERE id = 1
  FOR UPDATE` / commit-or-rollback discipline `decide_webhook_disposition()`
  and `attempt_replaying_to_idle()` already establish against the same
  singleton row — a third caller contending for the same lock, not a
  second, independent implementation. Verifies `state === 'quiescent'`
  **and** the deferred-update backlog is empty while still holding the
  lock, then invokes `$work`; commits only if `$work` returns `true`,
  rolls back on `false` or any thrown exception (propagated after
  rollback). Four new `LOCK_RESULT_*` constants.
- `src/SupportChatAdapter/Migration/LegacyBindingImportServiceV1.php`
  (new) — `import_batch( array $candidates, bool $dry_run = false ):
  array`. Rejects every invocation outside a WP-CLI process by throwing
  `LegacyBindingImportContextRejectedException` (new). Enforces the
  100-candidate batch ceiling server-side. Per candidate: an early,
  cheap pre-check against `ChannelBindingRepository`; a live re-check of
  the source conversation's `topic_creation_state`/`topic_lifecycle_state`/
  `destination_id`/`telegram_topic_id` via `ConversationRepository::find()`
  (never trusting the caller's snapshot); then, only inside
  `QuiescenceGate::with_quiescence_lock()`, a second, race-safe existing-binding
  re-check followed by `ChannelBindingRepository::create( ..., status:
  ChannelBinding::STATUS_PREPARED )`. `$dry_run` exercises the identical
  pipeline, including the real lock acquisition and the real live
  re-check, but the lock closure always returns `false`, so nothing ever
  commits.
- `src/SupportChatAdapter/Migration/BindingImportOutcome.php` (new) — the
  subset of Support Chat ADR-0009 §4's outcome vocabulary this repository
  is responsible for determining: `CREATED`,
  `SKIP_TOPIC_STATE_CHANGED`, `RETRY_UT_UNAVAILABLE_OR_INDETERMINATE`,
  `SKIP_ALREADY_BOUND`, `CONFLICT_EXISTING_MISMATCHED`,
  `CONFLICT_EXISTING_ACTIVE`, `CONFLICT_EXISTING_STATUS_UNRESOLVED`,
  `RETRY_NOT_QUIESCENT`, `RETRY_TRANSIENT_ERROR`. The status-specific
  existing-binding rule (ADR-0009 §4) is implemented exactly: a matching
  identity is idempotent success only when the existing row's status is
  `prepared`; `active` maps to the elevated-priority
  `CONFLICT_EXISTING_ACTIVE`; `unavailable`/`closed` map to
  `CONFLICT_EXISTING_STATUS_UNRESOLVED`; any mismatched identity maps to
  `CONFLICT_EXISTING_MISMATCHED`.
- `src/SupportChatAdapter/Migration/LegacyBindingImportContextRejectedException.php`
  (new) — mirrors `LegacyExportContextRejectedException`'s identical role.
- `src/Core/Plugin.php` (amended) — wires `LegacyBindingImportServiceV1`
  into the composition root (constructed alongside the existing adapter
  bindings repository and quiescence gate) and exposes it via
  `Plugin::instance()->legacy_binding_import_service()`, the same access
  pattern `legacy_export_service()` already uses. No new WP-CLI command is
  registered by this repository (ADR-0041 §2) — invocation is Support
  Chat's own future `legacy-bind` command's responsibility.
- `universal-telegram.php` — version bump `0.17.0` → `0.18.0` (minor: a
  new binding status and write boundary become available for the first
  time; `db_version` `33` → `34`).

## Test evidence

- `tests/unit/SupportChatAdapter/Migration/LegacyBindingImportServiceV1Test.php`
  (new, 4 tests) — WP-CLI-context rejection across web/Ajax/REST/cron-representative
  invocation contexts, mirroring `LegacyExportServiceV1Test`'s identical
  unit-level shape. `ChannelBindingRepository` is `final` and cannot be
  doubled, but the gate throws before any collaborator method is called,
  so real, cheaply-constructed collaborators (no DB access at
  construction time) are used instead of mocks.
- `tests/integration/SupportChatAdapter/Migration/LegacyBindingImportServiceV1Test.php`
  (new, 11 tests) — against real `ConversationRepository`/
  `ChannelBindingRepository`/`QuiescenceGate` state: a prepared binding is
  created and is never `active`; a rerun against its own `prepared`
  binding is idempotent (no second row); a matching `active` binding
  produces `CONFLICT_EXISTING_ACTIVE`, never idempotent success, and
  writes no second row; a matching `unavailable` binding produces
  `CONFLICT_EXISTING_STATUS_UNRESOLVED`; a mismatched existing binding
  produces `CONFLICT_EXISTING_MISMATCHED`; a topic never `created`, and a
  topic whose lifecycle later became `unavailable` (source drift since a
  Phase-A-time snapshot), both produce `SKIP_TOPIC_STATE_CHANGED`; a
  non-quiescent gate produces `RETRY_NOT_QUIESCENT` and writes nothing; a
  candidate that is retried after quiescence is subsequently achieved
  succeeds (proving the terminal/retryable distinction is real, not
  merely documented); dry-run writes nothing and releases its lock (a
  following real call still succeeds); the 100-candidate batch ceiling is
  enforced server-side against 150 submitted candidates. Includes a
  documented `setUp()`/`tearDown()` cleanup of the conversations/bindings
  tables — `QuiescenceGate::enter()`/`confirm()`'s own CAS transitions
  each commit their own short transaction, which (on `WP_UnitTestCase`'s
  shared connection) also commits whatever this test itself inserted
  earlier, past `WP_UnitTestCase`'s own per-test rollback — the identical,
  already-documented hazard `QuiescenceGateTest`'s and
  `QuiescenceSupportChatNonInterferenceTest`'s own `tearDown()` methods
  already handle for their own tables.
- `tests/integration/SupportChatAdapter/Migration/InboundAdapterBridgeNonInterferenceTest.php`
  (new, 4 tests, one per ADR-0040 quiescence state) — **the single most
  load-bearing test this work package adds**: a real binding with
  `status = 'prepared'`, created via the real `ChannelBindingRepository`,
  is never claimed by a real `InboundAdapterBridge::try_handle()` call
  carrying a real inbound-update-shaped payload, across `idle`,
  `draining`, `quiescent`, and `replaying`. This is the direct,
  code-level proof — not a design-intent assertion — underlying Support
  Chat ADR-0009 §3's non-routing claim.
- `tests/integration/Migration/QuiescenceRaceInterleavingTest.php` (2 new
  tests, alongside the file's existing 2) — a genuine second `mysqli`
  connection holds Table 1's row lock open; `with_quiescence_lock()`'s own
  attempt against the identical lock is proven to genuinely block (a
  shortened `innodb_lock_wait_timeout` makes this a fast, deterministic
  failure) and then proceed once the second connection commits — proving
  this is the *same* lock the two pre-existing tests already prove
  `decide_webhook_disposition()`/`attempt_replaying_to_idle()` serialize
  on, not a second, independent one; a forced exception inside `$work`
  rolls back everything `$work` wrote and releases the lock (a second,
  immediate call does not block).
- Existing suites amended only where a hardcoded `db_version` expectation
  needed updating to the new target (`33` → `34`):
  `tests/integration/Persistence/MigratorTest.php`,
  `MigratorAiSchemaTest.php`, `MigratorClaimLeaseSchemaTest.php`,
  `MigratorConversationDisplayNameSchemaTest.php`,
  `MigratorConversationIdempotencySchemaTest.php`,
  `MigratorConversationOwnershipSchemaTest.php`,
  `MigratorConversationsSchemaTest.php`, `MigratorEventsSchemaTest.php`,
  and `tests/integration/SupportChatAdapter/ChannelBindingRepositoryTest.php`.
  No other line in any of these files was touched.

## Explicit confirmation of every excluded scope item

- **No `prepared → active` activation mechanism, in this repository or
  any other.** `create()`'s new parameter is never invoked with
  `STATUS_ACTIVE` by `LegacyBindingImportServiceV1` under any code path —
  confirmed by the permanent regression assertion in every integration
  test that inspects a created row's status.
- **No modification to `BindingImportCommand`, `InboundAdapterBridge`,
  `DeliverMessageService`, `WebhookController`, or
  `EnsureChannelCaseService`.** Confirmed by `git diff` against this
  branch's base — none of these five files appears in the changed-file
  list above.
- **No Support Chat-side code, schema, or WP-CLI command.** This
  repository's own boundary; Support Chat's `legacy-bind` command and
  migration-map schema are a separate, later implementation slice in the
  Support Chat repository.
- **No new REST route, Ajax handler, cron path, Contract v1 operation
  allow-list change, shared secret, or permanent cross-plugin SQL
  access.**
- **No production binding creation, cutover, route switch, soak, or
  rollback.**

## Validation

- `bin/docker/phpcs.sh` — clean (0 errors, 0 warnings) across all 535
  inspected files, including every new and amended file.
- `bin/docker/phpstan.sh` (level 5) — `[OK] No errors`.
- `bin/docker/test-unit.sh --php-version=8.3` — 395 tests, 1224
  assertions, OK (1 pre-existing unrelated skip); the 4 new
  `LegacyBindingImportServiceV1Test` unit tests pass in isolation.
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`
  — 1113 tests, 3580 assertions, 31 failures, 58 pre-existing unrelated
  skips. **This is not a clean run, and is not represented as one.**
  Confirmed by an isolated baseline run against unmodified `main`
  (`7abb4eb`, this work's own base commit, with every file this branch
  adds or touches removed/stashed): the baseline itself already shows
  **30 pre-existing failures** (`NotificationDispatcherTest`,
  `ChatWidgetAssetsTest`, `ChatWidgetAvailabilityTest`,
  `OperatorIdentityRepositoryTest`, `ConversationsControllerTest`,
  `OperationalSummarySweepTest`, and others), all pre-dating and unrelated
  to this work package. The one additional failure on top of that
  30-failure baseline
  (`BotCommandDispatcherFamilyFTest::test_confirm_from_a_different_mapped_operator_does_not_match`)
  passes cleanly when run in isolation (`--filter
  BotCommandDispatcherFamilyFTest`: 13 tests, 36 assertions, OK) — it is
  order-dependent flakiness within the full-suite run, not a regression
  this work package introduces. Every test this work package itself adds
  (`LegacyBindingImportServiceV1Test` ×2,
  `InboundAdapterBridgeNonInterferenceTest`, the two new
  `QuiescenceRaceInterleavingTest` methods) passes in both the full run
  and in isolation.
- `bin/docker/composer.sh run-script check-doc-links` — same pre-existing,
  unrelated failures already documented in the WP8 closure
  (`docs/plans/m07-1-conversation-topic-lifecycle-and-repair-plan-v1.md`),
  confirmed unchanged by `git diff` against this branch. Not wired into
  CI (confirmed by inspection of `.github/workflows/ci.yml`).

## Next

Per this milestone's frozen plan (§11, definition of done) and ADR-0041
§7: this repository's own obligation under ADR-0041 is now closed pending
Product Owner acceptance and merge. **Support Chat's SC-M03 work package 5
(candidate identification, schema, and the `legacy-bind` WP-CLI command,
consuming this service) may not begin implementation until this PR merges
to `main`** — the two-repository gate ADR-0041 §7 and Support Chat's own
ADR-0009 Compatibility/Migration Impact section both require.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.
