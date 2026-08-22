# Changelog

All notable changes to this project are documented in this file.

## [0.6.0] - Unreleased

### Added

- Bot setup wizard (M06.1): replaces the static "Set up a Telegram bot" guidance panel on
  Telegram Hub → Bots with a progress-driven, five-step wizard (create bot, create support
  group, add bot as administrator, connect group, activate chat widget), server-rendered with
  no JavaScript, SPA, or new build system. Progress is derived entirely from already-persisted
  state (`BotSetupWizardState`) — no new capability, table, migration, or settings field.
  Steps 2 and 3 (creating the Telegram group and adding the bot as its administrator) are
  labelled as external manual prerequisites and are never falsely marked complete, since
  WordPress has no way to observe or verify them. Step 5 links to the existing Settings tab
  to enable the chat widget rather than duplicating that form. A shared `TelegramFormFields`
  collaborator extracts the add-bot, create-destination, and single-op button forms so the
  manual Bots page and the wizard reuse one implementation. No ADR required (no architecture,
  persistence, or public-contract change); no database schema change.

## [0.2.0] - Unreleased

### Added

- Normalized events and notifications (M02): deterministic SHA-256 event identity derived from a
  mandatory, source-supplied idempotency key (`Events\EventIdentity`); an immutable event envelope
  with fail-closed per-field privacy classification (`Events\EventEnvelope`); a per-request event
  registry enforcing that every durable-history field is classified PUBLIC
  (`Events\Registry`, docs/adr/0017); a safety-wrapped emission facade reducing any failure anywhere
  in the event pipeline to one fixed diagnostic code, with no public `do_action()` emission hook
  (`Events\EventEmitter`, docs/adr/0015); a PUBLIC-only, idempotently-written durable event history
  with retention cleanup (`Events\EventHistoryRepository`, `Events\RetentionCleanup`); core
  WordPress event emitters (logins, user lifecycle, content publishing, plugin/update activity,
  scheduled-task and REST-request failures — both excluding the plugin's own queue group and REST
  namespace to prevent notification feedback loops — email failures); a bounded, privacy-safe
  two-phase fatal-error capture mechanism that never stores message text, a stack trace, or a raw
  file path (`Events\Emitters\FatalErrorMarkerWriter`, `Events\Emitters\FatalErrorPromotionJob`); an
  administrator-configurable notification rule engine with AND-only, non-nested conditions and a
  fixed operator set (`Automations\NotificationRule`, `Automations\ConditionOperator`); deterministic
  rule evaluation ordered by `(priority ASC, id ASC)` with per-rule failure isolation
  (`Automations\RuleEvaluator`); a fixed-grammar, MarkdownV2-escaping message template renderer
  (`Automations\TemplateRenderer`); an idempotent, honestly-scoped seven-state dispatch log —
  `claimed`, `rejected`, `skipped_duplicate`, `skipped_cooldown`, `skipped_disabled_reference`,
  `handed_off_to_m01`, `failed_before_handoff` — guaranteeing no second rule-engine handoff decision
  for the same `(rule_id, event_id)` pair, dispatching through M01's own unchanged
  `MessageDispatcher::send()` (`Automations\DispatchLogRepository`,
  `Automations\NotificationDispatcher`, docs/adr/0016); capability-gated event catalog, rule
  builder, rule simulator (no live Telegram traffic, no dispatch-log write), and event-history
  browser admin screens; a plugin-row "Settings" action link to the existing Diagnostics landing
  page; new Diagnostics fields for event/rule counts, dispatch failures, stuck claims, and stale
  fatal-marker drops. Database schema version 10 (three new migration steps, four new tables).
  Known limitation: M01's unmodified `MessageDispatcher::send()` does not return the created
  outbound message's own UUID, so `notification_dispatch_log.outbound_message_uuid` remains
  unpopulated on a successful handoff; the terminal result value itself remains the authoritative,
  honest signal. A stuck `claimed` row from a mid-request termination is a deliberately accepted,
  diagnosable, non-retried limitation, surfaced on the Diagnostics page.

## [0.1.0] - Unreleased

### Added

- Telegram connectivity (M01): multiple independent bot profiles, each with its own encrypted token
  and webhook secret; destinations across private, group, supergroup, and channel chats, including
  supergroup forum-topic routing; outbound sends exclusively through the queue, with message content
  durably encrypted outside the queue payload; an authenticated, replay-protected inbound webhook
  route with a failure-safe registration/rotation protocol (indefinite active/pending dual-secret
  acceptance, explicit retry and rollback, no automatic expiry of an unresolved rotation); per-bot
  and per-destination rate limiting and circuit breaking; dead-letter handling with an admin requeue
  action; retention-based cleanup of message content and delivery-log rows; a bot/destination
  management admin screen; queue-health alerting on the diagnostics page and as a site-wide admin
  notice (never a Telegram message); best-effort webhook deregistration on uninstall. Outbound
  delivery is at-least-once, not exactly-once — see readme.txt's "Delivery guarantees" section.

## [0.0.1] - Unreleased

### Added

- Product foundation (M00): composition root and module boundaries, persistence and atomic migration
  locking with safe degraded mode, durable queue abstraction with schema-aware worker execution,
  audit logging, privacy classification and redaction, AES-256-GCM credential vault, capability
  model, WooCommerce-presence detection, diagnostics page with a bounded self-test, Docker-only
  development and CI tooling.
