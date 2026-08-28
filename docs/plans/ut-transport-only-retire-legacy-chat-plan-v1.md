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
| `Persistence/Migrator` | steps 11–30, 33, 35, 36 | rewrite (tranche 2) |
| `Core/Plugin` | ~40 legacy classes + ~30 accessors | rewrite (tranche 7) |

## 3. Architecture decisions

Per ADR-0044 §§1–6. No new ADR needed beyond 0044.

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
  `OperatorIdentityRepository` → `OperatorIdentityMapRepository`. Strip availability/claim methods;
  keep create/find/`by_telegram_user_id`/account-deleted cleanup.

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

### Schema (`src/Persistence/Migrator.php`)

New step list (renumbered 1–13, contiguous):

| New | Was | Table / change |
|---|---|---|
| 1 | 1 | audit_log |
| 2 | 2 | bots |
| 3 | 3 | destinations |
| 4 | 4 | outbound_messages |
| 5 | 5 | inbound_updates |
| 6 | 6 | circuit_breaker_state |
| 7 | 7 | rate_limit_state |
| 8 | 8 | events + fatal_error_markers |
| 9 | 9 | notification_rules |
| 10 | 10 | notification_dispatch_log |
| 11 | 30 | notification_rules `match_mode` column (kept — generic engine) |
| 12 | 31 | support_chat_adapter tables (bindings, delivery_keys) |
| 13 | 32 | support_chat_contract auth tables (peers, nonces) |
| 14 | new | operator_identity_map table (was part of step 17; recreated standalone, chat columns dropped) |
| 15 | 34 | support_chat_bindings `prepared` status column — **review**: `prepared` status only mattered for cutover; if activation is always live via `ensure_channel_case`, collapse to `active`-only and drop this step |

`target_version()` → 13–15 depending on the step-15 review. Steps 11–29 (conversations …
topic-lifecycle columns), 33 (quiescence), 35 (cutover), 36 (handoff incident cols) are deleted
outright.

### Settings (`universal_telegram_settings`)

Remove keys: `chat_widget_*`, `visitor_*` (all), `visitor_digest_*`, `operational_summary_*`,
`operational_alert_*` **except** the generic order/checkout/JS-error alert keys (keep those —
they route through the notification engine, not chat), `alert_bot_id`/`alert_destination_id`
(keep — generic), AI keys (all), chat retention keys. Keep: transport retention keys,
`telegram_*`, `visitor_tracking_enabled` → removed. Migrate the option on upgrade by unsetting
removed keys (idempotent).

### CLI

- Delete `src/Migration/Cli/CutoverCommand.php`, `QuiescenceCommand.php` and their registration.
- Add `src/Administration/Cli/LegacyChatPurgeCommand.php` — `wp universal-telegram legacy-chat
  purge --assume-legacy-chat-removal-authority [--dry-run]` per ADR-0044 §5.
- Keep `src/SupportChatAdapter/Cli/BindingImportCommand.php`.

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
     `..._cutover_*`;
  6. `Migrator::target_version()` == the new value and a fresh migrate creates exactly the
     preserved table set;
  7. `legacy-chat purge --dry-run` on a seeded legacy DB lists the legacy tables and **not** the
     preserved ones; a real purge drops the former, keeps `..._bots` (with its ciphertext row
     intact) and every `..._support_chat_*` table, and resets `db_version`.
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
| `remove_data_on_uninstall` / purge drops a preserved table | dedicated purge postcondition test (§6.7); the preserved-table list is a single constant referenced by both purge and uninstall |
| Event catalog prune breaks non-chat notification rules | keep every non-chat event family; a notification-engine regression test covers order/checkout/JS-error rules |
| `prepared` binding status removal breaks the adapter | §4 step-15 review before touching it; if unsure, keep the column and the status, only remove the cutover *activation* path |

## 8. Work packages (tranches) — execution order

1. **Docs supersession/freeze** — ADR-0044; ADR-0039/0040/0041/0042/0043 Status amendments; SC-M03
   plan/closure CLOSED notes; this plan; ARCHITECTURE/master-plan/milestone/README updates.
   *(This PR.)*
2. **Schema** — rewrite `Migrator.php` to the new step list; `target_version()`; `SchemaHealth`
   and any `verify_step_*` references pruned.
3. **Transport core** — rewrite `WebhookController` (drop legacy routing + `QuiescenceGate`; keep
   `InboundAdapterBridge` path + topic-unavailable → adapter `report_channel_unavailable`);
   rewrite `BotCommandDispatcher` (drop chat commands).
4. **Reclassify** — move topic-lifecycle → `Telegram\Topics`; move operator identity →
   `SupportChatAdapter\Identity\OperatorIdentityMap*`; update `InboundAdapterBridge`.
5. **Delete migration/cutover** — `src/Migration/`, `src/SupportChatAdapter/Migration/`, their
   CLI, and drop the `QuiescenceGate` param from `ChannelBindingRepository` and every other
   consumer.
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
(a follow-up decides the version).

## 10. Definition of done

ADR-0044 accepted and merged; every tranche committed on the branch with CI green; the interop
suite green on both variants against real Support Chat with operator-attributed inbound replies;
the retirement closure record merged; the PR open for review, not merged.
