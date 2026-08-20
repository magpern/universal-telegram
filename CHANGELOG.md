# Changelog

All notable changes to this project are documented in this file.

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
