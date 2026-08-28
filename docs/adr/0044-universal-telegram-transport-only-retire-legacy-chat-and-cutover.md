# ADR-0044: Universal Telegram becomes transport/adapter only — retire legacy website chat and the SC-M03 migration/cutover track

## Status

**Accepted** — 2026-08-28, by the Product Owner. Universal Telegram is redefined as a **Telegram
transport / Support Chat adapter plugin only**. Universal Support Chat is the **sole owner** of
website chat, conversations, messages, notes, chat AI, operator workflow, and chat lifecycle.

**Supersedes ADR-0040, ADR-0042, and ADR-0043** (Status-field amendment only on each; their
Context/Decision/Consequences text is not edited, per the ADR immutability rule). **Closes the
SC-M03 final-cutover disposable DEV-rehearsal track** (Tier 1 and Tier 2): there will be **no
UT→SC data migration and no cutover**. DEV legacy chat data is discarded, not migrated;
production never ran a two-plugin split and has nothing to cut over.

Also updates the follow-up ADRs whose deliverables are removed by this decision: **ADR-0039**
(legacy export boundary, `LegacyExportServiceV1`) and **ADR-0041** (legacy binding preparation,
`LegacyBindingImportServiceV1`) — Status-field amendment to "Superseded by ADR-0044" (their
follow-up services are deleted; the Support Chat ADR pins they carried are historical).

Implementation is authorized within the scope of this ADR and its plan
`docs/plans/ut-transport-only-retire-legacy-chat-plan-v1.md`. **No DEV, production, Telegram
configuration, webhook, database, deployment, release, or tag change is authorized by this ADR or
its implementation.**

## Context

Universal Telegram began as a full website-chat product (ADR-0019 through ADR-0036: visitor
tracking, chat widget, conversation persistence, operator workflow, AI drafts, operational
summaries). **ADR-0037** superseded that product direction: Universal Support Chat is the
extracted, canonical chat system, and Universal Telegram's forward role is an **optional adapter
consumer** — the Telegram transport for Support Chat.

ADR-0038 through ADR-0043 built the bridge between the two: the signed Contract v1 client
(ADR-0038), and the SC-M03 migration/cutover machinery to move Universal Telegram's *existing*
legacy conversation store into Support Chat — legacy export (ADR-0039), quiescence write-blocking
(ADR-0040), legacy binding preparation (ADR-0041), the cutover state machine and cohort
activation (ADR-0042), and the `channel_case_ref` / fail-closed classifier correction after
finding F1 (ADR-0043).

That migration track was validated only in the disposable interop harness:

- The SC-M03 Tier 1 disposable prerequisite validation was executed and **PASSED** on 2026-08-28
  at the immutable baselines universal-telegram `6eed0228286e84b4e56e0119f242b483f138a58e` /
  universal-support-chat `4f833c3344c3cff2adcc0227f93832c0c3a4427a`
  (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-reattempt-closure.md`).
- Tier 2 (the actual disposable DEV rehearsal) never ran — it was blocked on B1/B2 and a
  proposed, unsigned Approval B (`docs/plans/sc-m03-final-cutover-tier2-dev-rehearsal-prerequisites-plan-v1.md`).

The Product Owner has now decided that **the DEV legacy chat data will be discarded rather than
migrated**, and that Universal Telegram carries **no legacy chat domain at all** going forward.
With no legacy conversation store to migrate, the entire migration/cutover track
(`src/Migration/`, `src/SupportChatAdapter/Migration/`, quiescence, deferred-update replay,
cohort activation, incident machinery, schema steps 33/35/36) has no remaining purpose and is
removed with the legacy chat it was built to migrate.

### What "legacy chat" means here (verified against `origin/main` `f8c090687a8b7aa3e6283dba78826c3b7d78e81b`)

Legacy website chat in Universal Telegram spans: the chat widget (`src/ChatWidget/`), visitor
tracking and browser ingestion (`src/Events/Visitor/`), conversation/message/note persistence and
delivery (`src/Conversations/`, minus the forum-topic-lifecycle pieces the adapter reuses), the
operator workflow and Hub inbox (`src/Administration/Hub/`, `src/Administration/Conversations/`,
operator identity/availability), chat AI drafts and configuration (`src/AI/`,
`src/Administration/AI/`), visitor-activity digests and operational-summary AI
(`src/Automations/Digest/`, `src/Automations/Intelligence/`), the `/universal-telegram/v1/conversations*`
REST routes, and schema migration steps 11–30. The migration/cutover track adds `src/Migration/`,
`src/SupportChatAdapter/Migration/`, and schema steps 33, 35, 36, plus the `wp universal-telegram
cutover` and `wp universal-telegram quiescence` CLI commands.

## Decision

### 1. Universal Telegram is transport/adapter only

Universal Telegram's supported surface after this ADR:

- **Telegram transport** — bot credentials, destinations, webhook ingress (authenticity, replay
  protection, dedupe), outbound delivery, retry/queue, circuit breaker, rate limiting, audit and
  event history, generic notification rules, and non-chat integrations (ADR-0012 through
  ADR-0018, ADR-0027 admin bot commands scoped to non-chat operations).
- **Support Chat adapter (Contract v1)** — pairing, bindings, discovery, the inbound bridge, the
  outbound Contract client and `ensure_channel_case` / delivery, diagnostics, and delivery
  idempotency (ADR-0037, ADR-0038, and Support Chat ADR-0005/0007/0010/0011 as pinned).
- **Telegram-user → WordPress-operator identity mapping**, retained as an adapter/transport
  concern (see §4).
- **Generic operational alerts/notifications through Telegram** — order-failure, checkout-failure,
  and JS-error-spike alerts and the notification rule engine (ADR-0014, ADR-0016, ADR-0032)
  remain; only the chat/visitor-specific automations are removed.

### 2. Retire, completely — no legacy read model, no shims

Removed from the plugin (code, schema, CLI, settings, assets, tests, and documentation status):

- **Legacy chat domain:** `src/ChatWidget/`; `src/AI/`; `src/Conversations/` except the
  forum-topic-lifecycle components reclassified in §3; `src/Administration/AI/`,
  `src/Administration/Conversations/`, `src/Administration/Hub/`, `src/Administration/Visitor/`;
  `src/Automations/Digest/`, `src/Automations/Intelligence/`, and the chat/visitor parts of
  `src/Administration/Automations/`; `src/Events/Visitor/`; the
  `/universal-telegram/v1/conversations*` REST routes; the chat widget, visitor, AI, digest,
  summary, and Hub admin pages, menu entries, and assets; the chat/visitor/AI/digest/summary
  settings; the conversation-retention, AI-draft-lease, and summary-AI-lease cron jobs.
- **SC-M03 migration/cutover track (code and CLI):** all of `src/Migration/`;
  `src/SupportChatAdapter/Migration/`; the `wp universal-telegram cutover` and `wp
  universal-telegram quiescence` CLI commands. The **cohort/cutover activation** services and CLI
  are removed; the Support Chat binding-status column and its schema step are **kept** as
  compatibility state (§4a).
- **Schema — forward-only, monotonic (§4b):** `Migrator::target_version()` is **raised from 36 to
  37**, never reduced. A new forward-only **step 37** performs the retirement. The obsolete legacy
  step methods (former 11–30, 33, 35, 36) are **kept in the migrator as inert no-ops** — they
  create nothing on a fresh install — and the migrator retains a **legacy-table/option manifest**
  (names only, no DDL) so the guarded purge command can recognise and drop the obsolete objects.
  Migration history is **not renumbered or deleted**; an upgraded database (which still has the
  obsolete tables and a retirement marker option until purge) remains distinguishable from a
  clean install.
- **Documentation:** ADR-0040/0042/0043 → Superseded by ADR-0044; ADR-0039/0041 → Superseded by
  ADR-0044 (Status-field only). SC-M03 plans and rehearsal/closure documents are marked
  **CLOSED — superseded by ADR-0044 (legacy chat retired, not migrated)**; they are retained
  unedited as historical records. `docs/ARCHITECTURE.md` and `docs/master-plan.md` are updated to
  describe the transport-only plugin.

There is **no** disabled-but-present legacy controller, **no** compatibility shim, and **no**
unreachable migration code left in the tree.

### 3. Reclassify — forum-topic lifecycle

- Forum-topic lifecycle components currently under `src/Conversations/`
  (`TopicCreationDispatcher`, `TopicCreationHandler`, `TopicDeletionDispatcher`,
  `TopicDeletionHandler`, `ForumTopicRemoteDeleter`, `ConversationTopicEligibility`,
  `TopicLifecycleState`) are **kept** and moved to `src/Telegram/Topics/` (or folded into the
  adapter's outbound package). The Support Chat adapter's `EnsureChannelCaseService` creates and
  deletes real Telegram forum topics through them; they carry no legacy conversation dependency
  after the move.

### 4. Telegram-user → WordPress-operator identity mapping is retained

`OperatorIdentity` / `OperatorIdentityRepository` are **kept** and moved out of
`src/Conversations/` into a narrowly named adapter component
`src/SupportChatAdapter/Identity/` as `OperatorIdentityMap` / `OperatorIdentityMapRepository`.
Only the mapping survives (`find_by_telegram_user_id() → wp_user_id`, `create`,
`find_by_wp_user_id`, `all`, `delete_for_wp_user`, and an account-deleted cleanup); legacy
operator **availability**, **assignment/claim workflow**, and the **Hub inbox** are removed.

- **New table** `..._operator_identity_map` (`id`, `wp_user_id` UNIQUE, `telegram_user_id`
  UNIQUE, `telegram_username` NULL, `created_at`, `created_by`) — created by **step 37**, not by
  the retired step 17.
- **Step 37 establishes the data path:** on an existing install it copies every row from the
  obsolete `..._operator_identities` table into `..._operator_identity_map` (idempotent
  `INSERT … ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`) and verifies the row count matches; it
  does **not** drop `..._operator_identities`.
- **Before `legacy-chat purge` drops `..._operator_identities`** it re-runs that copy
  idempotently and verifies the postcondition (every source mapping is present in
  `..._operator_identity_map`); only then does it drop the obsolete table.
- The mapping must remain **sufficient for a Telegram operator reply in a bound topic to be
  attributed to the correct WordPress user** when `InboundAdapterBridge` forwards it to Support
  Chat with provenance.

### 4a. Support Chat binding status remains compatibility state

- The Support Chat binding **status column and its schema step (former step 34) are kept**, along
  with the existing status compatibility handling.
- The **cutover / cohort-activation** services and CLI are removed
  (`CutoverActivationService`, `CutoverRun*`, `Cli/CutoverCommand`, cohort-file handling).
- Normal live `EnsureChannelCaseService::ensure_channel_case()` creates an **`active` binding
  directly**. No code path creates a new `prepared` binding.
- This task does **not** add a schema simplification, does **not** collapse the status vocabulary,
  and does **not** auto-promote any existing `prepared` row. Existing `prepared` rows (there are
  none in production; DEV data is discarded) are left as-is; a later task may address them.

### 4b. Forward-only retirement migration (step 37)

`step_37_retire_legacy_chat()` — runs exactly once per install to reach `target_version() = 37`:

1. Creates `..._operator_identity_map` (idempotent `CREATE TABLE IF NOT EXISTS`).
2. If the obsolete `..._operator_identities` table exists (an upgrade from ≤ 36), copies its
   mappings into `..._operator_identity_map` and verifies the count (§4).
3. Writes the retirement marker option `universal_telegram_legacy_chat_retired_at` (a timestamp)
   **only if** any obsolete legacy table is still present — so an upgraded-but-not-yet-purged
   install is distinguishable from a clean install, and the admin "run purge" notice is shown
   only while that marker is set and obsolete tables remain.
4. Does **not** drop, rename, or truncate any obsolete table. All destructive removal is deferred
   to the guarded `legacy-chat purge` command (or uninstall, per the data-removal setting).

On a **fresh install** the migrator runs steps 1–10, 31, 32, 34, and 37; the inert no-op methods
for the retired steps create nothing, so no legacy-chat or cutover table is ever created, the
marker option is never written, and no legacy route/widget/admin/CLI is registered.

### 5. Guarded legacy-chat cleanup path

A new, explicit, guarded WP-CLI command removes obsolete legacy-chat data on an existing install:

```
wp universal-telegram legacy-chat purge --assume-legacy-chat-removal-authority [--dry-run]
```

- **The `legacy-chat purge` command is the supported destructive path.** It drops **only** the
  obsolete legacy-chat and migration/cutover tables named in the migrator's legacy manifest
  (former steps 11–30, 33, 35, 36) and deletes **only** the chat/visitor/AI/digest/summary/
  quiescence/cutover options and the retirement marker.
- **Before dropping `..._operator_identities`** it re-runs the §4 mapping copy idempotently and
  verifies the postcondition (every source mapping present in `..._operator_identity_map`); it
  aborts without dropping anything if that check fails.
- **Preserves** `..._bots` (encrypted bot credentials — a hard invariant, checked in the
  postcondition), `..._destinations`, `..._outbound_messages`, `..._inbound_updates`,
  `..._audit_log`, `..._event_history`, `..._fatal_error_markers`, `..._circuit_breaker_state`,
  `..._rate_limit_state`, `..._notification_rules`, `..._notification_dispatch_log`,
  `..._operator_identity_map`, and every `..._support_chat_*` table.
- `--dry-run` (the mutating run requires `--assume-legacy-chat-removal-authority`) lists exactly
  what would be dropped/deleted and touches nothing.
- After a real run: sets `universal_telegram_db_version` to the current `target_version()` (37),
  clears the retirement marker, and its postcondition asserts no manifest table/option remains
  and every preserved table/option (bot ciphertext row included) is intact.
- **Uninstall (`uninstall.php`) preserves the project's normal uninstall-data semantics.** It
  removes the obsolete legacy tables/options **only when the existing `remove_data_on_uninstall`
  setting authorizes data removal** — exactly as it treats every other plugin table. It never
  silently deletes retired legacy data when that setting is off, and it never drops
  `..._bots`/credentials.

### 6. Upgrade and fresh-install behaviour

- **Existing upgrade (was at version ≤ 36):** the retired build simply **does not register** the
  chat widget, the `/conversations*` and visitor-ingest routes, the Hub/AI/visitor/digest/summary
  admin pages and menu entries, or the chat/AI cron jobs — legacy chat is inert the moment the
  new build loads, independent of the schema. Migrator step 37 then runs once (creates
  `..._operator_identity_map`, copies the operator mappings, writes the retirement marker) and
  advances `universal_telegram_db_version` to 37. The obsolete tables remain until the operator
  runs `legacy-chat purge` (or uninstalls with data-removal authorized). A one-time admin notice
  — shown only while the retirement marker is set and obsolete tables remain — points to the
  purge command.
- **Fresh install:** the migrator runs steps 1–10, 31, 32, 34, 37; the retired step methods are
  inert no-ops, so no legacy-chat or cutover table is ever created, no retirement marker is
  written, and no legacy route, widget, admin page, or CLI command is registered.
  `universal_telegram_db_version` is written at 37.

## Alternatives

1. **Keep a minimal legacy read model for migration (rejected).** Retain
   `ConversationRepository` / `MessageRepository` / `ConversationNoteRepository` and their tables
   as an internal, admin-less data layer used only by `LegacyExportServiceV1` /
   `LegacyBindingImportServiceV1`. Rejected: the Product Owner has decided there is **no
   migration**; a retained read model is dead weight, a permanent security surface (encrypted
   legacy content columns), and contradicts "UT owns no chat."
2. **Disable legacy chat but keep the code (rejected).** Set `chat_widget_enabled=false` and
   hide the admin. Rejected explicitly: the task requires no disabled controllers, shims, or
   unreachable code.
3. **Run the SC-M03 cutover to move DEV data first, then remove (rejected).** Rejected: the
   Product Owner authorized discarding DEV legacy data; Tier 2 is blocked and unapproved; the
   cutover exists only to serve a migration that is no longer wanted.
4. **Two plugins from a shared history via `git filter-repo` (out of scope).** This ADR removes
   code from Universal Telegram in place; it does not re-extract history.

## Consequences

- Universal Telegram's `src/` shrinks by roughly half (~25,000 lines; ~200 files) and its test
  suite by ~137 files. The remaining plugin is a focused Telegram transport + Support Chat
  adapter.
- The SC-M03 milestone's cutover scope is **closed as not-implemented-by-design**. The Tier 1
  re-attempt closure and Tier 2 prerequisites plan remain as historical records; the proposed
  Approval B is withdrawn (never signed).
- Support Chat is now the only place a website conversation exists. A Universal Telegram install
  with the adapter paired transports Telegram traffic for Support Chat conversations only —
  created live via `ensure_channel_case`, never migrated.
- Existing DEV/production installs that still have legacy tables keep them until an operator runs
  the guarded purge or uninstalls; the plugin no longer *uses* them.
- Downstream: any external code calling `Plugin::instance()->conversation_repository()` (and the
  other removed accessors) breaks. These were internal composition-root accessors, not a public
  API; the break is intentional and documented in the closure record.

## Security and privacy impact

- **Reduced surface.** Removing the chat widget, visitor ingestion, the `/conversations*` REST
  routes, bearer-secret visitor auth, and the AI provider integration removes a large
  public-facing and outbound-network surface.
- **Encrypted legacy content** (`conversation_messages.body`, AI drafts) is retained on disk only
  until the operator runs `legacy-chat purge` / uninstalls; the guarded command's postcondition
  proves it is gone afterward. No new decryption path is added; the removed repositories were the
  only decryptors and they go with the data.
- **Bot credentials are explicitly preserved** by the purge command and its postcondition check —
  a legacy-chat cleanup must never orphan or drop the encrypted bot token.
- The operator-identity **mapping** retained under §4 stores only a Telegram user id ↔ WP user id
  correspondence (no content); its privacy classification is unchanged from ADR-0026's identity
  row.

## Affected Documents/Milestones

- **ADR-0040, ADR-0042, ADR-0043** — Status → **Superseded by ADR-0044** (Status field only).
- **ADR-0039, ADR-0041** — Status → **Superseded by ADR-0044** (Status field only; their
  follow-up services are removed).
- **ADR-0037** — unchanged; this ADR completes the direction ADR-0037 set.
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` — §0d updated: SC-M03
  migration/cutover scope closed; UT is transport/adapter only.
- `docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md` / `-v2.md`,
  `sc-m03-final-cutover-tier2-dev-rehearsal-prerequisites-plan-v1.md`,
  `sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md` — marked **CLOSED —
  superseded by ADR-0044**; retained unedited.
- `docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-*.md`, `sc-m03-final-cutover-*` — a note
  added pointing to ADR-0044 and the retirement closure; historical records not rewritten.
- **New:** `docs/plans/ut-transport-only-retire-legacy-chat-plan-v1.md` (the implementation
  blueprint); `docs/closure/ut-transport-only-legacy-chat-retirement-closure.md` (on completion).
- `docs/ARCHITECTURE.md`, `docs/master-plan.md` — updated to the transport-only description.
- `docs/adr/README.md` — reserved-numbers list extended to 0044; next available 0045.

## Compatibility/Migration Impact

- **Schema — monotonic, forward-only.** `Migrator::target_version()` is **raised from 36 to 37**;
  it is never reduced. The step methods for the retired steps (former 11–30, 33, 35, 36) stay in
  the migrator as **inert no-ops** — a fresh install iterates 1 → 37 and creates only the
  retained transport/adapter tables plus `..._operator_identity_map` (new step 37). An existing
  install at 36 runs **only** the new forward-only **step 37** (§4b): create
  `..._operator_identity_map`, copy the operator mappings from the obsolete
  `..._operator_identities` table, write the retirement marker option if obsolete tables remain.
  Step 37 **never drops, renames, or truncates** an obsolete table. Migration history is not
  renumbered or deleted; the migrator keeps a **legacy-table/option manifest** (names only) that
  the guarded purge command uses to recognise and remove the obsolete objects.
- **`universal_telegram_db_version`:** an existing install advances from 36 to 37 when step 37
  runs; a fresh install is written at 37 directly. It never decreases. The `legacy-chat purge`
  command re-asserts it at `target_version()` after a successful purge.
- **Distinguishability:** an upgraded-but-not-yet-purged database has the obsolete legacy tables
  present **and** the `universal_telegram_legacy_chat_retired_at` marker option set; a clean
  install has neither. After a purge (or authorized uninstall data-removal) an upgraded database
  converges to the clean-install shape, which is the intended end state.
- **CLI:** `wp universal-telegram cutover` and `wp universal-telegram quiescence` are removed;
  `wp universal-telegram legacy-chat purge` is added. `wp universal-telegram support-chat-bindings`
  is retained.
- **REST:** `/universal-telegram/v1/conversations*` and the visitor ingest route are removed. The
  webhook route and the `/universal-telegram/v1/support-chat/*` Contract routes are unchanged.
- **No data migration.** No UT→SC data movement occurs, by design. Support Chat conversations are
  created live through the Contract v1 adapter, never imported.
- **DEV/production:** this ADR and its implementation make **no** DEV, production, Telegram,
  webhook, database, deployment, release, or tag change. Applying the retired build to DEV and
  running `legacy-chat purge` there is a separate, later, explicitly authorized operation.
