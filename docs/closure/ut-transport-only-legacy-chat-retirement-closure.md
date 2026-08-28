# Closure Record — Universal Telegram becomes transport/adapter only (ADR-0044)

- **Governing ADR:** `docs/adr/0044-universal-telegram-transport-only-retire-legacy-chat-and-cutover.md` (Accepted 2026-08-28).
- **Frozen plan:** `docs/plans/ut-transport-only-retire-legacy-chat-plan-v1.md` (with §11 "Deviations recorded during implementation").
- **Starting baseline (`origin/main`):** `f8c090687a8b7aa3e6283dba78826c3b7d78e81b`.
- **Branch:** `feature/ut-transport-only-retire-legacy-chat`.
- **PR:** [magpern/universal-telegram#62](https://github.com/magpern/universal-telegram/pull/62) — **open, not merged.**
- **Dual-plugin interop pinned against Universal Support Chat `origin/main`:** `1a58e34c124a6eaeb17bc31b24a66f2a59cd47b9`.

## Status

**Technical PASS.** Universal Telegram is now a Telegram transport / Support Chat adapter plugin
only. Universal Support Chat is the chat system of record. Every applicable acceptance point of
the frozen plan is implemented, tested, and green locally on both supported WP/PHP variants.

## What was NOT done (explicit)

- **No SC-M03 migration or cutover was performed**, and the SC-M03 final-cutover disposable
  DEV-rehearsal track (Tier 1 and Tier 2) is **superseded by ADR-0044 and not performed**.
  There is no UT→SC data migration and no cutover. DEV legacy chat data is discarded, not
  migrated; production never ran a two-plugin split.
- **No DEV, production, Telegram configuration, webhook, bot, database, deployment, release,
  tag, or data-purge operation was performed.** `wp universal-telegram legacy-chat purge` was
  never run anywhere. Work was done only in fresh throwaway clones driven through
  `bin/docker/*.sh` against disposable containers (`docker compose … down -v` between runs).
- No change to Universal Support Chat. No version bump, tag, or release (a follow-up decides
  the version). No reduction of `Migrator::target_version()`; no renumbering or deletion of
  migration history; no binding-status schema simplification; no auto-promotion of existing
  `prepared` rows.

## Removed (retired) — legacy website chat + SC-M03 track

- **Conversation domain:** `src/Conversations/` conversation/message/note/operator-availability
  repositories and domain model; the `/universal-telegram/v1/conversations*` and
  `/universal-telegram/v1/visitor*` REST routes and their controllers.
- **Chat widget & visitor tracking:** the chat-widget assets/availability/config, the visitor
  tracker + ingest controller, `assets/js/{chat-widget,visitor-tracker}.js`,
  `assets/css/chat-widget-*.css`, `tests/js/`, `bin/docker/test-js.sh`, and the CI
  `js-behavioural` job. The visitor-tracking event surface
  (`visitor_activity` family, the two `visitor.*` presets, seven orphan visitor-only field
  paths and their labels, `VisitorCommerceEventCatalog`).
- **Chat AI:** AI draft/config repositories, AI-generated operational summaries and every
  AI-summary code path.
- **Hub chat surfaces:** the Conversations and AI grouped areas and the Visitor Tracking
  screen. (`src/Administration/Hub/` — the ADR-0020 generic nav shell — is retained.)
- **SC-M03 machinery:** quiescence (`src/Migration/` gate/CLI, `quiescence_*` tables),
  final-cutover state machine and CLI (`cutover_*` tables), deferred-update replay / cohort
  activation / incident machinery, `LegacyExportServiceV1` (ADR-0039), `LegacyBindingImportServiceV1`
  and the `support-chat-bindings import` CLI (ADR-0041).
- **Legacy commands:** the eight conversation-workflow bot commands
  (`conversations/here/presence/claim/release/resolve/reopen/confirm`) are gone from
  `CommandCatalogue`.
- **Tests:** ~137 legacy test files deleted; the interop suite deleted and rebuilt (below).

## Preserved and reclassified as Telegram transport / Support Chat adapter

- **Transport:** bot credentials & `bots` table, destinations, webhook ingress + inbound dedup,
  outbound delivery + queue/retry, circuit breaker, rate limiter, audit log & event history,
  generic notification rules, all non-chat WordPress/WooCommerce event emitters.
- **Operational-alert engine (PO decision):** `AlertEvaluator`, `AlertRepository`,
  `IntelligenceSettings`, `IntelligenceStateRepository`, the `operational_alert_state`
  (schema step 27) and `intelligence_settings_state` (step 26) tables, the settings/UI section,
  and a new `Automations\Intelligence\AlertSweep`. New `Automations\EventCountAggregator`
  carries the event-count helper the removed summary repository held. (JS-error-spike alert
  config is retained but inert without visitor tracking.)
- **Support Chat Contract v1 adapter:** pairing/discovery, `ChannelBindingRepository` +
  `support_chat_bindings` / `support_chat_delivery_keys` tables (including the retained
  `status` compatibility column with `prepared` in the ENUM — no cutover activation path),
  `InboundAdapterBridge`, `SupportChatContractClient`, `DeliveryIdempotencyRepository`,
  diagnostics.
- **Operator identity mapping** moved out of the `Conversations` domain into
  `SupportChatAdapter\Identity\OperatorIdentityMap{,Repository}`, backed by the new
  `universal_telegram_operator_identity_map` table. Sufficient for a Telegram operator reply to
  be attributed in Support Chat (proven by interop, below).
- **Forum-topic lifecycle** kept as neutral services: `Telegram\Topics\ForumTopicService`
  (create/delete with idempotency, failure, cleanup) and
  `Telegram\Topics\ForumTopicRemoteDeleter` — `EnsureChannelCaseService` uses the service, not
  ad-hoc inline API calls.

## Schema — before / after (monotonic, forward-only)

- `Migrator::target_version()` **36 → 37** (raised, never reduced).
- Retired step slots (11–25, 28, 29, 33, 35, 36) map to a shared inert
  `step_retired`/`verify_retired` no-op; steps 1–10, 26, 27, 30, 31, 32, 34 retained; retired
  step 14's `claim_expires_at` folded into step 4's `CREATE TABLE` + `verify_step_4`.
- New forward-only **`step_37_retire_legacy_chat()`**: creates
  `universal_telegram_operator_identity_map`; if a legacy `operator_identities` table exists,
  `INSERT IGNORE` copies each `(wp_user_id, telegram_user_id)` pair then runs
  `OperatorIdentityMapMigration::verify_bijection()` and **fails closed** (throws, legacy tables
  untouched) on any mismatch; sets `LEGACY_CHAT_RETIRED_OPTION` when any legacy table is present.
- **Legacy manifest** retained for the guarded purge: `Migrator::LEGACY_TABLES`,
  `LEGACY_OPTIONS`, `OPERATOR_IDENTITY_MAP_TABLE`, `LEGACY_CHAT_RETIRED_OPTION`,
  `table_exists()`.
- **Fresh v37 install** creates only transport/adapter schema — never a legacy table (proven by
  `MigrationLifecycleTest`, `LegacySurfaceAbsenceTest`, and package acceptance ×3).
- An upgraded database is distinguishable from a clean install: `db_version = 37` on both, but
  an upgraded DB carries the `LEGACY_CHAT_RETIRED_OPTION` marker and (until purge) the retired
  tables.

## Guarded purge & uninstall

- **`wp universal-telegram legacy-chat purge --assume-legacy-chat-removal-authority [--dry-run]`**
  (`LegacyChatPurgeCommand` → `LegacyChatPurge`): `--dry-run` (and the unauthorised path) lists
  the legacy objects and touches nothing; the real run **re-verifies the exact bijection
  immediately before dropping `operator_identities`** and, on any mismatch, aborts with
  `ABORTED` + `BLOCKER` lines and **zero destructive side effect**; on success drops only
  `LEGACY_TABLES`, deletes only `LEGACY_OPTIONS` + the retired marker, sets `db_version = 37`,
  and asserts the postcondition that `bots`, `operator_identity_map`, `support_chat_bindings`,
  and `operational_alert_state` still exist. **Bot credentials are never dropped by this path.**
- **`uninstall.php`** keeps normal `remove_data_on_uninstall` semantics: retired legacy data is
  removed only when that setting is true, never silently; bot credentials are never dropped.
  `Uninstaller` additionally drops `operator_identity_map` under the same setting.

## Test & CI evidence (local, branch head `535ee0e`)

| Gate | Result |
|---|---|
| `phpcs.sh` | PASS — 324 files, 0 errors/warnings |
| `phpstan.sh` | PASS — `[OK] No errors` |
| `test-unit.sh` ×(8.1, 8.3, 8.4) | PASS — OK (274 tests, 802 assertions), 1 skipped, each version |
| `test-integration-wp-only.sh` 6.9 / PHP 8.1 | PASS — OK (399 tests, 3283 assertions), 50 skipped |
| `test-integration-wp-only.sh` 7.1 / PHP 8.3 | PASS — OK (399 tests, 3062 assertions), 50 skipped |
| `test-integration-wc-present.sh` 7.1 / WC 11.0.1 | PASS — OK (399 tests, 5033 assertions), 3 skipped |
| `build-zip.sh` | PASS (run within each package variant) |
| `test-package.sh` — 6.9 / 8.1 | PASS |
| `test-package.sh` — 7.1 / 8.3 | PASS |
| `test-package.sh` — 7.1 / 8.3 / WC 11.0.1 | PASS |
| `test-integration-interop.sh` 6.9 / PHP 8.1 (SC `1a58e34`) | PASS — OK (14 tests, 917 assertions) |
| `test-integration-interop.sh` 7.1 / PHP 8.3 (SC `1a58e34`) | PASS — OK (14 tests, 935 assertions) |

### Retirement suite (`tests/integration/Retirement/`)

`MigrationLifecycleTest` (v37 monotonic; fresh-install has no legacy table; seeded-v36 upgrade
runs only step 37, copies mappings, sets the marker); `OperatorIdentityMapBijectionTest`
(clean copy; idempotent rerun; same-wp/conflicting-tg; same-tg/conflicting-wp; missing target
pair; unreachable-extra permitted + reported); `LegacyChatPurgeTest` (dry-run touches nothing;
real run drops only legacy + preserves transport/credentials; bijection-conflict aborts with no
destructive side effect); `LegacySurfaceAbsenceTest` (no legacy route/widget/AI/Hub workflow
class; cutover/quiescence/binding-import CLI gone, `legacy-chat purge` present; transport +
adapter + alert services wired).

### Dual-plugin interop (`tests/integration/Interop/`, rebuilt)

Real Contract v1 on both sides, real two-way Ed25519 pairing; the Telegram Bot API is the only
faked boundary.

- **PairingAndDiscoveryTest** — real mutual pairing stored each side's peer key; discovery
  `channel_available: true`; UT adapter resolves `Compatible`, `Disabled` when the setting is off.
- **EnsureChannelCaseInteropTest** — SC `ensure_channel_case` creates an **`active`** binding
  (never `prepared`) with a real forum topic id; `channel_case_ref` is the SC conversation UUID,
  never the UT binding UUID; idempotent per conversation.
- **TransportPathInteropTest** — `notify_operators` and `deliver_message` create a real
  encrypted `universal_telegram_outbound_messages` row; `deliver_message` is idempotent on the
  `message_uuid`.
- **OperatorReplyAttributionInteropTest** — a mapped Telegram operator reply reaches the **real
  Support Chat conversation** as an operator message; the SC-side audit row for the ingest
  carries `actor_id` = the WP user id the `OperatorIdentityMap` resolved from the Telegram
  sender; an **unmapped** sender is not ingested; no legacy UT conversation table exists.
- **NoLegacySurfaceInteropTest** — with both plugins active: no
  `/universal-telegram/v1/conversations*` or `/visitor*` route; the webhook and Support Chat
  Contract routes remain; SC owns the `/universal-support-chat/*` routes; no chat-widget asset;
  a full round trip creates no legacy `universal_telegram_conversation*` /
  `operator_identities` table.

## Deviations from the frozen plan

Recorded in the plan itself at §11 ("Deviations recorded during implementation"): operational-alert
engine retained (PO decision); JS-error-spike alert inert without visitor tracking; visitor
event surface fully retired; all browser JS + the `js-behavioural` job removed;
`support-chat-bindings import` CLI removed; topic lifecycle reduced to two neutral services;
step 14's `claim_expires_at` folded into step 4; `Administration\Hub\` retained.

## Follow-ups (not in this PR)

- Product Owner review and merge of PR #62.
- A separate decision on the version bump / tag for the transport-only release.
- Update of the SC-M03 milestone/registry status to "superseded by ADR-0044" in a
  documentation pass.
