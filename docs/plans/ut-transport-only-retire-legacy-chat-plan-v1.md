# Plan — Universal Telegram transport-only: retire legacy website chat and the SC-M03 migration/cutover track (ADR-0044)

**Status: FROZEN implementation blueprint for ADR-0044.** Documentation-only at freeze; the code
removal it specifies is executed in the tranches below, each its own reviewed commit on branch
`feature/ut-transport-only-retire-legacy-chat`, no DEV/production/Telegram/database/release
change. Pinned baseline: `origin/main` `f8c090687a8b7aa3e6283dba78826c3b7d78e81b`.

## 1. Milestone charter reference and ADR

- Charter: `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` §0d.
- ADR: **ADR-0044** (this plan's authority) — supersedes ADR-0040/0042/0043, marks ADR-0039/0041
  superseded, closes the SC-M03 cutover/Tier-2 track.

## 2. Repository findings at plan time (verified against `f8c09068`)

`src/` = 286 files / ~53,100 lines; `tests/` = 262 files (~137 legacy-related). `Core/Plugin.php`
= 2,353 lines (an ~800-line `init()` composition root + ~90 accessor methods). Schema migrator =
36 steps. Legacy REST: `/universal-telegram/v1/conversations*` + visitor ingest. Legacy CLI:
`wp universal-telegram cutover`, `wp universal-telegram quiescence`.

Cross-namespace couplings from the preserve-set into the remove-set (must be severed):

| Preserve-set file | Uses (remove-set) | Resolution |
|---|---|---|
| `SupportChatAdapter/Inbound/InboundAdapterBridge` | `Conversations\OperatorIdentityRepository` | reclassify (§4 tranche) |
| `Migration/CutoverReplayDispatcher` | `Conversations\OperatorIdentityRepository` | file removed (tranche 5) |
| `SupportChatAdapter/Migration/LegacyExport/BindingImport` | `Conversations\{Conversation,*Repository}` | files removed (tranche 5) |
| `Telegram/Inbound/WebhookController` | `Conversations\*`, `Migration\QuiescenceGate` | rewrite (tranche 3) |
| `Telegram/Commands/BotCommandDispatcher` | `Conversations\*`, `Migration\QuiescenceGate` | rewrite (tranche 3) |
| `SupportChatAdapter/ChannelBindingRepository` | `Migration\QuiescenceGate` | drop the gate param (tranche 5) |
| `Core/Lifecycle/Uninstaller` | `Migration\QuiescenceGate`, legacy tables | rewrite (tranche 6) |
| `Persistence/Migrator` | steps 11–30, 33, 35, 36 | → inert no-ops; +step 37; +legacy manifest (tranche 2) |
| `Core/Plugin` | ~40 legacy classes + ~30 accessors | rewrite (tranche 7) |

## 3. Architecture decisions

Per ADR-0044 §§1–6, plus §4a (binding status = compatibility state; cutover activation removed; `ensure_channel_case` → `active`) and §4b (forward-only retirement step 37; `target_version()` 36→37, monotonic; retired steps kept as no-ops; legacy manifest retained). No new ADR needed beyond 0044.

## 4. Directory / namespace / schema impact

### Deleted directories (whole)

`src/ChatWidget/`, `src/AI/`, `src/Administration/AI/`, `src/Administration/Conversations/`,
`src/Administration/Hub/`, `src/Administration/Visitor/`, `src/Automations/Digest/`,
`src/Automations/Intelligence/`, `src/Events/Visitor/`, `src/Migration/`,
`src/SupportChatAdapter/Migration/`.

### `src/Conversations/` — split

- **Delete:** `ChatProfileResolver`, `Conversation`, `ConversationDisplay`, `ConversationMessage`,
  `ConversationNote`, `ConversationNoteRepository`, `ConversationOutboundDispatcher`,
  `ConversationOutboundHandler`, `ConversationPurgeService`, `ConversationRepository`,
  `ConversationStatus`, `ImmediateDeliveryAttempt`, `ImmediateDeliveryResult`, `MessageRepository`,
  `OperatorAvailability`, `OperatorAvailabilityRepository`, `OutboundDeliveryBridge`,
  `PromptDeliveryFallback`, `ResponseReason`, `Rest/ConversationsController`,
  `RetentionCleanupHandler`, `VisitorTokenGenerator`.
- **Move to `src/Telegram/Topics/`** (namespace `UniversalTelegram\Telegram\Topics`):
  `TopicCreationDispatcher`, `TopicCreationHandler`, `TopicDeletionDispatcher`,
  `TopicDeletionHandler`, `ForumTopicRemoteDeleter`, `ConversationTopicEligibility` →
  `TopicEligibility`, `TopicLifecycleState`.
- **Move to `src/SupportChatAdapter/Identity/`** (namespace
  `UniversalTelegram\SupportChatAdapter\Identity`): `OperatorIdentity` → `OperatorIdentityMap`,
  `OperatorIdentityRepository` → `OperatorIdentityMapRepository`, backed by the **new
  `..._operator_identity_map` table** (created by migrator step 37, not the retired step 17).
  Keep `create`, `find`, `find_by_wp_user_id`, `find_by_telegram_user_id`, `all`,
  `delete_for_wp_user`, and a minimal account-deleted cleanup hook; drop everything
  availability/claim/assignment. `InboundAdapterBridge` is retyped to `OperatorIdentityMapRepository`
  and still calls `find_by_telegram_user_id($sender_id)->wp_user_id()`.

### `src/Automations/` — split

- **Keep** (generic Telegram notification engine, ADR-0014/0016/0032): `ConditionClauseResult`,
  `ConditionOperator`, `DispatchLogRepository`, `DispatchLogResult`,
  `InvalidConditionFieldException`, `MarkdownV2Escaper`, `NotificationDispatcher`,
  `NotificationRule`, `NotificationRuleRepository`, `RuleEvaluator`, `RuleMatchTrace`,
  `TemplateRenderer`.
- **Delete:** `Digest/*`, `Intelligence/*`.

### `src/Administration/Automations/` — split

- **Keep:** `ConditionRowRenderer`, `EventCatalogLabels`, `EventCatalogPage`, `EventFamilyCatalog`,
  `EventHistoryPage`, `FailingConditionExplainer`, `FieldTypeCatalog`, `NotificationTestOutcome`,
  `NotificationTestResult`, `NotificationTester`, `NotificationTesterPage`, `PresetCatalog`,
  `PreviewRenderer`, `RuleBuilderPage`, `RuleBuilderRequestHandler`, `RuleEditor`.
- **Delete:** `IntelligencePanel` (operational-summary AI panel), and prune the event catalog of
  chat/visitor/conversation event families.

### `src/Events/` — prune

- **Delete:** `Visitor/*` (browser ingestion), and remove conversation/chat event types from
  `VisitorEventCatalog` / the registry. **Keep** the event identity/envelope/registry/history
  infrastructure and the non-chat emitters (`FatalError*`, `Login`, `MailFailure`,
  `PluginLifecycle`, `RestRequestFailure`, `ScheduledTaskFailure`, `Update`, `UserLifecycle`,
  `Content` — review `ContentEmitter` for chat coupling).

### `src/Administration/` — remaining

Keep `Diagnostics/`, `Shared/`, `Telegram/`, `PluginActionLinks`. Rework `Hub/` consumers: the
admin menu is rebuilt without the Hub inbox / AI / visitor / digest tabs. If the Hub navigation
shell (ADR-0020) is only meaningful with chat, replace it with a flat "Universal Telegram" menu
(Bots, Destinations, Notifications, Event History, Diagnostics, Support Chat Adapter).

### Schema (`src/Persistence/Migrator.php`) — monotonic, forward-only (ADR-0044 §4b)

**`target_version()` is raised 36 → 37. Step numbers are NOT renumbered and history is NOT
deleted.**

- **Steps 1–10, 31, 32, 34** — unchanged. Transport (1–10) + Support Chat adapter tables (31),
  contract-auth tables (32), and the **binding-status column (34) — kept as compatibility
  state**. Step 30 (`notification_rules.match_mode`) — kept (generic engine).
- **Steps 11–29, 33, 35, 36** — the step methods stay in `Migrator.php` but are rewritten to
  **inert no-ops** (`private function step_NN_*() : void {}` with a one-line comment "retired by
  ADR-0044; see step 37 / the legacy manifest"). Their `verify_step_NN()` become
  `return true;`. On a fresh install they create nothing; on an existing install they were
  already run and are skipped. They are kept (not deleted) so the migrator's step loop and any
  historical version-gate logic stay intact and monotonic.
- **New step 37 — `step_37_retire_legacy_chat()`** (forward-only, ADR-0044 §4b):
  1. `CREATE TABLE IF NOT EXISTS {prefix}universal_telegram_operator_identity_map` — columns
     `id, wp_user_id BIGINT UNSIGNED NOT NULL UNIQUE, telegram_user_id BIGINT UNSIGNED NOT NULL
     UNIQUE, telegram_username VARCHAR(255) NULL, created_at DATETIME NOT NULL, created_by
     BIGINT UNSIGNED NOT NULL` (identical shape to the retained subset of the old
     `operator_identities`).
  2. If `{prefix}universal_telegram_operator_identities` exists: `INSERT IGNORE INTO
     ..._operator_identity_map (wp_user_id, telegram_user_id, telegram_username, created_at,
     created_by) SELECT wp_user_id, telegram_user_id, telegram_username, created_at, created_by
     FROM ..._operator_identities` and assert `COUNT(*)` parity.
  3. If any legacy-manifest table still exists, `update_option(
     'universal_telegram_legacy_chat_retired_at', gmdate('c') )`.
  4. Drops nothing.
  - `verify_step_37()`: `..._operator_identity_map` has the six columns; if `..._operator_identities`
     exists, every `(telegram_user_id)` in it is present in the map.
- **Legacy manifest** — a new `Migrator::LEGACY_TABLES` / `Migrator::LEGACY_OPTIONS` constant
  (names only, no DDL) enumerating the former-step 11–29 / 33 / 35 / 36 tables and the
  chat/visitor/AI/digest/summary/quiescence/cutover option keys. Consumed by
  `LegacyChatPurgeCommand` and `uninstall.php`. This is the "historical migration knowledge
  required to recognize and safely purge old tables."

### Settings (`universal_telegram_settings`)

Remove keys: `chat_widget_*`, `visitor_*` (all), `visitor_digest_*`, `operational_summary_*`,
`operational_alert_*` **except** the generic order/checkout/JS-error alert keys (keep those —
they route through the notification engine, not chat), `alert_bot_id`/`alert_destination_id`
(keep — generic), AI keys (all), chat retention keys. Keep: transport retention keys,
`telegram_*`, `visitor_tracking_enabled` → removed. Migrate the option on upgrade by unsetting
removed keys (idempotent).

### Binding status (ADR-0044 §4a)

- **Keep** `..._support_chat_bindings.status`, schema step 34, and the existing status
  compatibility handling in `ChannelBindingRepository` / `ChannelBinding`.
- **Remove** `CutoverActivationService`, `CutoverRun`, `CutoverRunRepository`, `CutoverState`,
  `Cli/CutoverCommand` and all cohort-file / activation-saga / compensation logic.
- `EnsureChannelCaseService::ensure_channel_case()` creates the binding with `status = 'active'`
  directly (it currently mints the binding via `ChannelBindingRepository::create(...)`; the
  status argument/default is set to `active`). No path creates a `prepared` binding.
- **No** status-vocabulary simplification, **no** schema change to step 34, **no** automatic
  promotion of existing `prepared` rows in this task.

### CLI

- Delete `src/Migration/Cli/CutoverCommand.php`, `QuiescenceCommand.php` and their registration.
- Add `src/Administration/Cli/LegacyChatPurgeCommand.php` — `wp universal-telegram legacy-chat
  purge --assume-legacy-chat-removal-authority [--dry-run]` per ADR-0044 §5: iterates the
  `Migrator::LEGACY_TABLES` / `LEGACY_OPTIONS` manifest; **before** dropping
  `..._operator_identities` re-runs the step-37 mapping copy + parity assert; drops the manifest
  tables; deletes the manifest options + the retirement marker; sets `universal_telegram_db_version`
  to `target_version()`; postcondition asserts (a) no manifest table/option remains, (b) every
  preserved table exists, (c) `..._bots` still holds its ciphertext row(s), (d) every
  `..._support_chat_*` table and `..._operator_identity_map` intact. `--dry-run` prints the plan
  and touches nothing; the mutating run requires `--assume-legacy-chat-removal-authority`.
- Keep `src/SupportChatAdapter/Cli/BindingImportCommand.php`.

### Uninstall (`uninstall.php`) — normal semantics preserved

`uninstall.php` removes the `Migrator::LEGACY_TABLES` / `LEGACY_OPTIONS` and the retirement marker
**only when the existing `remove_data_on_uninstall` setting is true** — exactly as it already
gates removal of every other plugin table/option. It never drops `..._bots` or any credential,
and it never silently deletes retired legacy data when the setting is off.

### Cron / Action Scheduler

Delete: `ConversationRetentionCleanupHandler`, `AiDraftLeaseSweep`, `SummaryAiLeaseSweep`,
`VisitorDigestSweep`, `OperationalSummarySweep`, operator-identity account-deleted cleanup
(re-add a minimal identity-map cleanup under the adapter). Keep: `RetentionCleanupHandler`
(transport logs), `Events\RetentionCleanup`, `WorkerRunner`, `NonceReplaySweep`,
`FatalErrorPromotionJob`, `UpdateEmitter` check.

## 5. Security and privacy impact

Per ADR-0044 §"Security and privacy impact". Net reduction: public chat widget, visitor
ingestion REST, bearer-secret visitor auth, AI provider outbound calls, and encrypted
conversation-content columns all removed. `legacy-chat purge` postcondition proves encrypted
legacy content is gone and bot credentials are intact.

## 6. Test and CI impact

- **Delete** ~137 test files under `Conversation*`, `ChatWidget*`, `tests/**/AI/*`,
  `Operator{Availability,Workflow,Assignment}*`, `Visitor*`, `Automations/{Digest,Intelligence}*`,
  `Hub*`, `Migration/*`, `Interop/Cutover*`, `Interop/*Legacy*`, quiescence/cutover integration
  and unit tests.
- **Keep & verify** ~125: Telegram transport (webhook auth/dedupe/outbound/queue/circuit
  breaker/rate limiter), notification engine, event history, `SupportChatAdapter/*` except
  migration, package/build.
- **New absence tests** (`tests/integration/Retirement/`):
  1. no `/universal-telegram/v1/conversations*` route is registered;
  2. no chat widget script/style is enqueued on any front-end request;
  3. no `AI`, `Hub`, `Visitor`, conversation, or digest admin menu/page is registered;
  4. `wp universal-telegram cutover` / `quiescence` are not registered; `legacy-chat purge` is;
  5. a fresh install's `SHOW TABLES` contains no `..._conversations`, `..._conversation_messages`,
     `..._ai_*`, `..._visitor_digest_*`, `..._operational_summary_*`, `..._quiescence_*`,
     `..._cutover_*`, `..._operator_identities`; it **does** contain `..._operator_identity_map`
     and `..._support_chat_bindings`;
  6. **Migration lifecycle:** `Migrator::target_version() === 37`; a fresh migrate ends at
     `db_version` 37 and creates exactly the retained transport/adapter set + `..._operator_identity_map`;
     an install seeded at `db_version` 36 with legacy tables + a seeded `..._operator_identities`
     row runs **only** step 37, ends at 37, gains `..._operator_identity_map` with the copied row,
     gains the `universal_telegram_legacy_chat_retired_at` marker, and **still has** every legacy
     table (nothing dropped); `db_version` never goes below its stored value;
  7. **Purge:** `legacy-chat purge --dry-run` on the seeded-legacy DB lists every
     `Migrator::LEGACY_TABLES`/`LEGACY_OPTIONS` entry and **not** any preserved one; a real
     `--assume-legacy-chat-removal-authority` run drops the manifest tables, deletes the manifest
     options + the marker, keeps `..._bots` (ciphertext row asserted present), `..._destinations`,
     every transport table, every `..._support_chat_*` table, and `..._operator_identity_map`
     (with the migrated mapping), and sets `db_version` to 37; a purge run where the
     `..._operator_identities` → `..._operator_identity_map` parity check fails **aborts and drops
     nothing**;
  8. **Uninstall semantics:** with `remove_data_on_uninstall` **false**, `uninstall.php` leaves
     the legacy tables and the marker in place; with it **true**, `uninstall.php` removes the
     manifest tables/options; in neither case is `..._bots` or any credential dropped by the
     legacy path;
  9. **Binding status:** `ensure_channel_case` creates a `status='active'` binding; no test or
     code path produces a `prepared` binding; the `status` column and step 34 are unchanged.
- **New dual-plugin interop** (`tests/integration/Interop/`), kept/rewritten so **no** case
  depends on legacy conversations or cutover:
  - pairing + discovery `channel_available:true`;
  - `ensure_channel_case` creates a real UT binding + a real forum topic;
  - Support Chat `notify_operators` / `deliver_message` reach a real UT outbound message + queue
    job;
  - an inbound Telegram operator reply in a bound topic is forwarded to Support Chat via
    `InboundAdapterBridge` **with the mapped operator attribution** (the `OperatorIdentityMap`);
  - ordinary Support Chat chat activity is never mirrored to UT; UT writes nothing to any
    (now-absent) legacy store;
  - privacy: no plaintext body persisted on the UT side.
- CI jobs unchanged in shape (phpcs, static-analysis, unit ×3, integration-wp-only ×2,
  integration-wc-present, js-behavioural, build, package-acceptance ×3). The interop suite is run
  locally on both supported WP/PHP variants and its result recorded in the closure.

## 7. Risks and mitigations

| Risk | Mitigation |
|---|---|
| `Core/Plugin.php` rewire misses a dependency → fatal on boot | phpstan (level in `phpstan.neon.dist`) + the full integration bootstrap test must pass before each tranche is committed |
| A preserved component secretly needs a removed class | the §2 coupling table is the checklist; phpstan `identifier not found` is the backstop |
| `remove_data_on_uninstall` / purge drops a preserved table or the bot ciphertext | purge & uninstall both iterate the single `Migrator::LEGACY_TABLES`/`LEGACY_OPTIONS` manifest; postcondition tests §6.7/§6.8 assert `..._bots` and every `..._support_chat_*` table survive |
| operator mappings lost when `..._operator_identities` is dropped | step 37 copies them forward on upgrade; purge re-copies + parity-checks and **aborts without dropping** if the check fails (§6.7); test §6.6 covers the upgrade copy |
| non-monotonic `db_version` breaks an upgraded install | `target_version()` only ever rises (36 → 37); retired steps become no-ops, not deletions; history not renumbered; test §6.6 asserts monotonicity |
| Event catalog prune breaks non-chat notification rules | keep every non-chat event family; a notification-engine regression test covers order/checkout/JS-error rules |
| binding-status handling regresses | step 34, the `status` column, and `ChannelBindingRepository` status handling are untouched; only cutover *activation* code is removed; `ensure_channel_case` sets `active` explicitly (test §6.9) |

## 8. Work packages (tranches) — execution order

1. **Docs supersession/freeze** — ADR-0044 (corrected for monotonic schema + binding-status
   compatibility); ADR-0039/0040/0041/0042/0043 Status amendments; SC-M03 plan/closure CLOSED
   notes; this plan; ARCHITECTURE/master-plan/milestone/README updates. *(PR #62, this commit set.)*
2. **Schema (forward-only)** — `target_version()` 36 → 37; retired step methods 11–29/33/35/36 →
   inert no-ops + `verify_*` → `true`; new `step_37_retire_legacy_chat()` (create
   `..._operator_identity_map`, copy mappings, write marker); new `Migrator::LEGACY_TABLES` /
   `LEGACY_OPTIONS` manifest constants; `SchemaHealth` updated to the retained set.
3. **Transport core** — rewrite `WebhookController` (drop legacy routing + `QuiescenceGate`; keep
   `InboundAdapterBridge` path + topic-unavailable → adapter `report_channel_unavailable`);
   rewrite `BotCommandDispatcher` (drop chat commands).
4. **Reclassify** — move topic-lifecycle → `Telegram\Topics`; move operator identity →
   `SupportChatAdapter\Identity\OperatorIdentityMap` / `OperatorIdentityMapRepository` (mapping
   methods only), reading `..._operator_identity_map`; update `InboundAdapterBridge` to the new
   type.
5. **Delete migration/cutover** — `src/Migration/`, `src/SupportChatAdapter/Migration/`, the
   `cutover`/`quiescence` CLI, `CutoverActivationService` and the activation saga; drop the
   `QuiescenceGate` param from `ChannelBindingRepository` and every other consumer. **Keep**
   `ChannelBinding`/`ChannelBindingRepository` status handling and step 34; make
   `EnsureChannelCaseService` create `status='active'` directly.
6. **Delete legacy chat** — `ChatWidget/`, `AI/`, the deleted `Conversations/` files, `Events/Visitor/`,
   `Automations/{Digest,Intelligence}/`, the legacy admin dirs; rebuild the admin menu; prune the
   event catalog and settings; delete the legacy cron handlers.
7. **Bootstrap** — rewrite `Core/Plugin.php` (`init()` + accessors) and `Core/Lifecycle/Uninstaller.php`;
   add `LegacyChatPurgeCommand`.
8. **Tests** — delete ~137 legacy test files; add the §6 absence + retirement + purge tests;
   rewrite the interop suite.
9. **Validation** — `composer dump-autoload`; `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
   `bin/docker/test-unit.sh` ×(8.1/8.3/8.4); `bin/docker/test-integration-wp-only.sh` ×(6.9/7.1);
   `bin/docker/test-integration-wc-present.sh`; `bin/docker/test-js.sh`; `bin/docker/build-zip.sh`;
   `bin/docker/test-package.sh` ×3; `bin/docker/test-integration-interop.sh` ×(6.9/8.1, 7.1/8.3)
   against a fresh Support Chat `origin/main` checkout.
10. **Closure** — `docs/closure/ut-transport-only-legacy-chat-retirement-closure.md` with the
    exact removed/preserved manifest, schema before/after, purge behaviour, and the full test +
    dual-plugin evidence.

## 9. Explicit out of scope

Any DEV/production/Telegram/webhook/database change; running `legacy-chat purge` anywhere;
re-extracting git history; changes to Universal Support Chat; a version bump/tag/release
(a follow-up decides the version); reducing `target_version()`; renumbering or deleting
migration history; simplifying the binding-status schema; auto-promoting existing `prepared`
rows.

## 10. Definition of done (closure criteria)

1. ADR-0044 (as corrected) accepted; the frozen plan reflects the monotonic-schema and
   binding-status-compatibility decisions.
2. Every tranche committed on `feature/ut-transport-only-retire-legacy-chat` with CI green
   (phpcs, static-analysis, unit ×3, integration-wp-only ×2, integration-wc-present,
   js-behavioural, build, package-acceptance ×3).
3. `Migrator::target_version() === 37`, monotonic; retired steps present as no-ops; step 37
   forward-only; `Migrator::LEGACY_TABLES`/`LEGACY_OPTIONS` manifest present; the §6.6 migration
   lifecycle tests pass for both fresh-install and seeded-v36-upgrade.
4. `legacy-chat purge` (with `--dry-run` and real) behaves per §6.7 including the abort-on-parity
   -failure path; `uninstall.php` behaves per §6.8 for `remove_data_on_uninstall` on/off; bot
   credentials never dropped by the legacy path.
5. No legacy conversation REST route, widget, AI, operator inbox, or legacy chat admin/settings
   surface is registered on a fresh install or after upgrade (§6.1–§6.4).
6. Real dual-plugin interop green on both supported WP/PHP variants against Support Chat
   `origin/main`: SC owns the conversation; UT transports; an inbound Telegram operator reply
   reaches SC with the mapped operator attribution via `..._operator_identity_map`; no write to
   any (now-absent) UT legacy store; `ensure_channel_case` produces an `active` binding.
7. No regression to bot / destination / webhook storage or transport observability
   (existing transport test suites green, unchanged).
8. `docs/closure/ut-transport-only-legacy-chat-retirement-closure.md` merged with the exact
   removed/preserved manifest, schema before/after, migration-lifecycle walk-through, purge and
   uninstall behaviour, and the full test + dual-plugin evidence.
9. PR #62 open for review, not merged.
