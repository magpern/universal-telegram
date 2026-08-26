# Closure Record — SC-M03 Work Package 2: Legacy-Chat Quiescence (ADR-0040)

## Final status

**PASS.** Implements ADR-0040 and `docs/plans/wp2-legacy-chat-quiescence-plan-v1.md` in full — every work package (WP2.0 through WP2.5) from the plan's §8 execution order, including the required non-interference regression proof and the two-transaction interleaving proof.

This closes Universal Telegram's own side of SC-M03 WP2 only. It does **not** claim:

- **No production quiescence operation was ever performed.** Every state transition, every encrypted deferred-update row, and every drain observation in this closure's own evidence ran against disposable, per-test-run WordPress databases, seeded only by this closure's own tests.
- **No cutover, route switch, soak, or rollback.** Those remain entirely out of scope, unauthorized by ADR-0040, and unimplemented.
- **No claim about Support Chat's own consumption of this signal being production-ready.** That is Support Chat's own closure to make (see the companion `sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md` addendum in the `universal-support-chat` repository), though this closure's own dual-plugin interop evidence (§ below) does prove the two sides interoperate correctly against each other's real code.

## Baseline

- Repository: `magpern/universal-telegram`
- Starting commit (`origin/main`): `609c64c` (merge of PR #38, ADR-0040 documentation freeze)
- Branch: `feature/wp2-quiescence-implementation`
- Frozen ADR: `docs/adr/0040-legacy-chat-quiescence-write-blocking-and-drain.md` (unedited by this implementation)
- Frozen plan: `docs/plans/wp2-legacy-chat-quiescence-plan-v1.md` (unedited by this implementation)
- Plugin version: `0.16.0` → `0.17.0` (minor bump: genuine new capability class, per this repository's own versioning convention)
- Schema version (`universal_telegram_db_version`): `32` → `33` (step 33: three new tables)

## Schema changes

| Step | Table | Purpose |
|---|---|---|
| 33 | `universal_telegram_quiescence_state` | Singleton (`id=1`, seeded at migration time) current-state row: `state` (idle/draining/quiescent/replaying), `token`, per-state-entry timestamps. CAS-only updates, never a second row. |
| 33 | `universal_telegram_quiescence_transitions` | Append-only audit trail — one row per successful transition, inserted in the same DB transaction as the CAS. |
| 33 | `universal_telegram_quiescence_deferred_updates` | Encrypted Telegram-webhook buffer: `payload_ciphertext` (`CredentialVault`, AAD bound to `bot_id`/`update_id`), `update_type` plaintext (non-sensitive), `received_at`/`replayed_at`, `UNIQUE(bot_id, update_id)`. |

## New source

- `src/Migration/QuiescenceGate.php` — the CAS state machine, the eight §2 entry-point/three §5 sweep gate checks, the job-type-and-`destination_id`-join drain-breakdown query (`drain_breakdown()`), the shared-row-lock serialization between webhook buffering and the final `replaying → idle` transition (`decide_webhook_disposition()`, the locked replay-completion CAS).
- `src/Migration/QuiescenceState.php` — the four-value state enum.
- `src/Migration/QuiescenceStatus.php` — the frozen value object `quiescence_status()` returns (ADR-0040 §8).
- `src/Migration/DeferredReplayContext.php` — the unforgeable, private-constructor, epoch-token-bound capability object; instantiable only via `QuiescenceGate::issue_replay_context()`.
- `src/Migration/DeferredUpdateRepository.php` / `DeferredUpdateRecord.php` — Table 3 persistence, encryption/decryption via `CredentialVault`, duplicate-insert idempotency.
- `src/Migration/QuiescenceTransitionRepository.php` — Table 2 persistence.
- `src/Migration/Cli/QuiescenceCommand.php` — `wp universal-telegram quiescence {enter,status,confirm,exit,replay-deferred-updates}`, self-registering under the existing `defined('WP_CLI') && WP_CLI` guard pattern (`BindingImportCommand` precedent).

## Amended source

- `src/Persistence/Migrator.php` — step 33 (above); `target_version()` `32` → `33`.
- `src/Core/Plugin.php` — constructs `QuiescenceGate` and its repositories in `init()`; registers `QuiescenceCommand`; adds the `quiescence_status()` accessor (mirroring `legacy_export_service()`'s exact shape); wires all eight entry-point gates and three sweep gates; both `deleted_user` closures gain the quiescence early-return guard as their first statement (entry point #7, PO-confirmed deferral trade-off).
- `src/Conversations/Rest/ConversationsController.php` — `handle_start()`/`handle_post_message()` gated first-statement, `409 quiescence_active`.
- `src/Telegram/Inbound/WebhookController.php` — `process_update()` extracted (shared by the live path and the replayer); the quiescence gate sits after auth, before the dedup insert; duplicate-delivery idempotency; encrypted buffering.
- `src/Telegram/Commands/BotCommandDispatcher.php` — `handle()` (public entry) and its private `execute()` both gain a trailing optional `?DeferredReplayContext $replay_context = null`, threaded through; the gate lives in `handle()`.
- `src/Administration/Conversations/ConversationActionHandler.php`, `src/Administration/AI/ConversationDraftPanel.php`, `src/AI/Draft/DraftRequestHandler.php`, `src/Administration/Telegram/BotManagementController.php` (`requeue_message()`, unconditional block per entry point #8) — each gated as the first statement.
- `src/Conversations/RetentionCleanupHandler.php`, `src/Telegram/Outbound/RetentionCleanupHandler.php`, `src/AI/Draft/AiDraftLeaseSweep.php` — sweep gates (skip-cycle, never marked failed); `Telegram\Outbound\RetentionCleanupHandler` additionally gains the unconditional 30-day deferred-row cleanup pass (never gated by quiescence state, since it only ever removes already-*replayed* rows).
- `universal-telegram.php`, `readme.txt` — version bump and changelog entry.

## Deviations from the ADR, with reasons

Both already flagged in-code and confirmed as correctness-preserving refinements of the ADR's intent, not deviations from it:

1. `WebhookController::process_update()` takes an explicit `BotProfile $bot` parameter, not the ADR's literal shorthand — the live path resolves the bot from `bot_uuid` before dedup/routing even begins, and the replayer resolves it from a deferred row's own `bot_id`; nothing in the decoded Telegram payload itself identifies the receiving bot.
2. The quiescence gate check in `handle_request()` sits after body-size/JSON-decode/metadata-extraction, not literally immediately after auth — required so there is decoded payload/`update_type` to encrypt and buffer at all. It remains strictly before the dedup insert and all routing, matching the ADR's actual invariant ("checks the gate immediately after auth, before the dedup insert").

## Two genuine production bugs found and fixed during implementation

Both discovered while writing the required tests, both real defects that would have shipped broken code, not test-only issues:

1. `QuiescenceGate`'s drain-breakdown query originally called Action Scheduler's `query_actions()` with an invalid `'ids'` return-type argument.
2. Action Scheduler moves large action-argument payloads out of the `args` column into a companion `extended_args` column past a size threshold; every job type this gate's drain query decodes exceeded that threshold in realistic test fixtures, so the original `JSON_EXTRACT(args, ...)` query silently matched nothing. Fixed to also check `extended_args`.

A duplicate `PRIMARY KEY` in the original step-33 DDL (MySQL "Multiple primary key defined") was also caught and fixed before any test run succeeded.

## Test and CI evidence

| Check | Command | Result |
|---|---|---|
| PHPCS | `bin/docker/phpcs.sh` | 0 errors |
| PHPStan | `bin/docker/phpstan.sh` | 0 errors (275 files) |
| Unit | `bin/docker/test-unit.sh` | 391 tests, 1208 assertions — OK |
| Integration (WP 7.1 / PHP 8.3) | `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` | 1096 tests, 3550 assertions — OK, verified stable across multiple independent fresh-database runs |
| **Interop (dual-plugin, against Support Chat's real WP2 implementation)** | `UNIVERSAL_TELEGRAM_HOST_PATH=<this checkout> bin/docker/test-integration-interop.sh --wp-version=7.1 --php-version=8.3` (run from the `universal-support-chat` checkout) | 13 tests, 99 assertions — OK, including the 5 new `QuiescenceProviderIntegrationTest` scenarios proving `UniversalTelegramQuiescenceStateProvider` and Support Chat's `PhaseBReconciliationService` continuous-recheck amendment against this repository's real, complete implementation (not a fake) |

New test files (20): `QuiescenceGateTest`, `QuiescenceRaceInterleavingTest` (the required two-real-DB-connection interleaving proof — no unreplayed deferred row can ever coexist with `state = idle`), `QuiescenceStatusTest`, `QuiescenceSupportChatNonInterferenceTest` (the required permanent regression proof that Support Chat adapter delivery is never counted by or paused by any quiescence drain query, exercised through the real `DeliverMessageService`), `DeferredUpdateRepositoryTest`, `DeferredReplayContextTest`, `WebhookControllerQuiescenceTest` (including duplicate-delivery idempotency across all three blocking states and AAD-context cross-row-decryption-failure proof), `BotCommandDispatcherQuiescenceTest` (including the forge/bypass proof: a command routed without a valid `DeferredReplayContext` during `replaying` is still refused), `ConversationsControllerQuiescenceTest`, `ConversationActionHandlerQuiescenceTest`, `DraftRequestHandlerQuiescenceTest`, `ConversationDraftPanelQuiescenceTest`, `BotManagementControllerRequeueQuiescenceTest`, `DeletedUserQuiescenceTest`, `RetentionCleanupHandlerQuiescenceTest` (×2, Conversations and Telegram\Outbound), `AiDraftLeaseSweepQuiescenceTest`.

## Explicit confirmation of every excluded scope item

- **No production quiescence operation, cutover, route switch, soak, or rollback.** Every write in every test occurred against disposable, per-test-run WordPress databases.
- **No data deletion beyond the ADR's own 30-day replayed-row cleanup policy.** Unreplayed rows are never auto-deleted anywhere in this implementation — confirmed by `DeferredUpdateRepositoryTest`'s own assertions and by the absence of any code path that deletes a row with `replayed_at IS NULL`.
- **No Telegram binding creation.** Untouched by this closure.
- **No removal of Universal Telegram's legacy Conversations, AI, widget, or settings UI.** Untouched.
- **No AI, availability, ticket, or launcher feature work.** Untouched.
- **No release, tag, deployment, or production environment mutation.** This branch is not tagged or deployed by this task.

## Product Owner acceptance

Pending. This PR is opened for review and is not merged by this task.

## Next task

**A future cutover work package** — undesigned, out of scope here, explicitly flagged in ADR-0040's Consequences section — will need its own handoff design for buffered updates arriving during a cutover-adjacent quiescence window, applying them into Support Chat's already-migrated data rather than replaying into a Universal Telegram legacy store being retired. Nothing in this closure authorizes starting that work; it requires its own plan, ADR, and Product Owner approval per `docs/governance.md`.
