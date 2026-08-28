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
- **SC-M03 migration/cutover track:** all of `src/Migration/`;
  `src/SupportChatAdapter/Migration/`; the `wp universal-telegram cutover` and `wp
  universal-telegram quiescence` CLI commands; schema steps 33 (quiescence tables), 35 (cutover
  tables), 36 (deferred-update handoff incident columns).
- **Schema:** migration steps 11–30 and 33/35/36 are deleted from the migrator. `target_version()`
  is renumbered to the count of remaining steps (transport steps 1–10 + adapter steps 31, 32, 34
  → 13). The removed steps are not renumbered in place and are not retained as no-ops.
- **Documentation:** ADR-0040/0042/0043 → Superseded by ADR-0044; ADR-0039/0041 → Superseded by
  ADR-0044 (Status-field only). SC-M03 plans and rehearsal/closure documents are marked
  **CLOSED — superseded by ADR-0044 (legacy chat retired, not migrated)**; they are retained
  unedited as historical records. `docs/ARCHITECTURE.md` and `docs/master-plan.md` are updated to
  describe the transport-only plugin.

There is **no** disabled-but-present legacy controller, **no** compatibility shim, and **no**
unreachable migration code left in the tree.

### 3. Reclassify — forum-topic lifecycle and operator identity

- Forum-topic lifecycle components currently under `src/Conversations/`
  (`TopicCreationDispatcher`, `TopicCreationHandler`, `TopicDeletionDispatcher`,
  `TopicDeletionHandler`, `ForumTopicRemoteDeleter`, `ConversationTopicEligibility`,
  `TopicLifecycleState`) are **kept** and moved to `src/Telegram/Topics/` (or folded into the
  adapter's outbound package). The Support Chat adapter's `EnsureChannelCaseService` creates and
  deletes real Telegram forum topics through them; they carry no legacy conversation dependency
  after the move.

### 4. Telegram-user → WordPress-operator identity mapping is retained

`OperatorIdentity` and `OperatorIdentityRepository` are **kept** and moved out of
`src/Conversations/` into a narrowly named adapter/transport component
(`src/SupportChatAdapter/Identity/` — proposed `OperatorIdentityMap` /
`OperatorIdentityMapRepository`). Its schema table (currently created by step 17) is preserved
under a new, dedicated migration step in the transport range. It must remain **sufficient for a
Telegram operator reply to be attributed to the correct WordPress user when the inbound bridge
forwards that reply to Support Chat** (`InboundAdapterBridge` provenance). The legacy
operator **availability**, **assignment/claim workflow**, and **Hub inbox** are removed — only
the identity *mapping* survives.

### 5. Guarded legacy-chat cleanup path

A new, explicit, guarded WP-CLI command removes obsolete legacy-chat data on an existing install:

```
wp universal-telegram legacy-chat purge --assume-legacy-chat-removal-authority [--dry-run]
```

- Drops **only** the legacy-chat and migration/cutover tables (former steps 11–30, 33, 35, 36)
  and deletes **only** the chat/visitor/AI/digest/summary/quiescence/cutover options.
- **Preserves** `..._bots` (encrypted bot credentials), `..._destinations`, `..._outbound_messages`,
  `..._inbound_updates`, `..._audit_log`, `..._event_history`, `..._fatal_error_markers`,
  `..._circuit_breaker_state`, `..._rate_limit_state`, `..._notification_rules`,
  `..._notification_dispatch_log`, the operator-identity-map table, and every `..._support_chat_*`
  table.
- `--dry-run` (default off; the mutating run requires the authority flag) lists exactly what
  would be dropped/deleted and touches nothing.
- Has a postcondition check: after a real run, no legacy table or option remains and every
  preserved table/option is intact.
- The `uninstall.php` path drops the legacy tables unconditionally and the transport/adapter
  tables only when `remove_data_on_uninstall` is set (unchanged semantics for the preserved set).

### 6. Upgrade and fresh-install behaviour

- **Existing upgrade:** on activation/upgrade, before any drop, the plugin **stops registering**
  the chat widget, the `/conversations*` routes, the Hub/AI/visitor admin pages and menu entries,
  and the chat/AI cron jobs — legacy chat is *inert* immediately. The obsolete tables are left in
  place until the operator runs `legacy-chat purge` (or uninstalls). A one-time admin notice
  points to the purge command.
- **Fresh install:** the removed migration steps do not exist, so the legacy tables are **never
  created** and no legacy route, widget, admin page, or CLI command is ever registered.

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

- **Schema:** `Migrator::target_version()` drops from 36 to 13 (transport steps 1–10 + adapter
  steps 31/32/34, renumbered contiguously as 1–13; the operator-identity-map table gets a
  dedicated step in that range). A fresh install creates only those tables. An existing install
  at version 36 is recognised as "ahead" of the new target — the migrator treats `current >=
  target` as up-to-date and never *drops* tables automatically; the obsolete tables are removed
  only by the guarded `legacy-chat purge` command or uninstall.
- **`universal_telegram_db_version`:** on an existing install it stays at its stored value until
  `legacy-chat purge` runs, which resets it to the new target (13). Fresh installs are written at
  13.
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
