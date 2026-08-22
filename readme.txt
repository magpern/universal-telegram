=== Telegram Operations Hub for WordPress ===
Contributors: magpern
Tags: telegram, woocommerce, notifications
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bidirectional Telegram bot connectivity for WordPress: configure one or more bots, connect
destinations, and send and receive messages, with no separately deployed companion application.

== Description ==

This release adds a normalized WordPress event model and an administrator-configurable notification
rule engine on top of the Telegram connectivity delivered previously: an administrator connects a
Telegram bot, defines rules against a catalog of core WordPress events (logins, user lifecycle,
content publishing, plugin/update activity, scheduled-task and REST failures, bounded fatal-error
capture), and Telegram notifications fire deterministically, with no duplicate rule-engine handoff
across retries. Deduplication and cooldown, message templates, a PUBLIC-only durable event history,
and a rule-simulation tool with no live Telegram traffic are all included. This release adds
WooCommerce order/payment/refund/stock/cart/coupon/checkout event coverage and anonymous,
privacy-minimal visitor/browser events (page views, navigation, search, clicks, JavaScript errors,
and, when WooCommerce is active, product views and classic add-to-cart intent), collected only with
tracking explicitly enabled and delivered through the same event/rule/queue pipeline — no cookies,
fingerprinting, persistent visitor identity, or raw IP/user-agent transmission. This release also
adds the conversation backend: persistent, encrypted-at-rest conversations with a bearer-secret
visitor credential, a minimal public REST contract, and Telegram forum-topic-scoped bidirectional
routing. The chat widget UI, operator workflow, and AI assistance are not part of this release; they
arrive in later milestones.

== Delivery guarantees ==

Telegram message delivery is **at-least-once, not exactly-once**. If a send attempt fails at the
network-transport level — no response received at all from Telegram, as distinct from a definite
error response — it is genuinely unknown whether Telegram already received and processed the
message before the failure. Because the plugin's retry mechanisms may then send the identical
message again, a message affected by this specific failure mode may occasionally be delivered more
than once. The delivery log flags any message this happened to with a "possible duplicate delivery"
indicator, so administrators have an accurate signal rather than an unearned exactly-once guarantee.

== Changelog ==

= 0.6.1 =
* Interactive Telegram delivery (M06.2, ADR-0023): removes the avoidable multi-minute delivery
  delay for interactive Telegram operations. A visitor chat message is still persisted and
  durably enqueued exactly as before (no visitor-facing REST request ever calls Telegram directly
  or creates a topic synchronously); a new, guarded, non-blocking request now also asks Action
  Scheduler's own existing async runner to process the queue sooner, bypassing only the
  admin-context gate that runner's own convenience hook otherwise applies — every ordinary job
  keeps Action Scheduler's default priority, so this cannot starve normal notification/event
  traffic. If unavailable or declined, the durable job and normal cron cadence are entirely
  unaffected. The administrator's Test Message action is now a single bounded (<=8 second)
  synchronous send with an immediate, fixed, non-content result shown in wp-admin, instead of a
  silent queued send. No schema or database-version change.

= 0.5.0 =
* Chat widget core (M06 core slice, ADR-0022, ADR-0021 amendment): a lightweight, accessible,
  cache-safe frontend chat widget consuming M05's conversation REST contract only — open/close,
  first-explicit-send conversation creation, visitor text sending, and short-poll operator reply
  rendering. Enabled via one toggle on the existing Hub Settings tab, no new admin screen. Visitor
  state (a client-generated bearer secret and the conversation uuid) lives only in sessionStorage,
  bound to the browser tab's session; a new client-generated-secret start protocol (Idempotency-Key
  plus a per-request secret header, verified by password_verify(), never stored beyond its hash)
  makes safe automatic retry of the start and message-post requests possible without risking
  duplicate conversations or messages (db_version 12 -> 13, two nullable/uniquely-indexed
  idempotency columns on the existing conversation tables, no new table). Configuration reaches the
  browser only as a static, non-executable JSON data island — never a per-visitor value, never
  inline executable script — so full-page caching is unaffected. Deferred from this milestone's
  fuller master-plan.md charter: chat profiles/targeting, business hours, pre-chat form, visual/CSS
  controls, and page-builder embeds.

= 0.4.0 =
* Conversation backend (M05, ADR-0021): a persistent conversation and message store, a two-part
  visitor credential (public conversation_uuid plus a bearer secret verified only by
  password_verify(), never used as a lookup key), and a minimal, unauthenticated-at-the-WP-REST-layer
  contract for starting a conversation, posting a visitor message, and short-polling for replies —
  every response no-store, same-origin only, protected by independent per-client and site-wide rate
  limits. A Telegram forum topic is created only after a conversation's first accepted message,
  idempotently; visitor messages route into it through the existing outbound pipeline, and operator
  replies in that topic route back to the conversation only once dedup, chat-identity, and
  known-topic-mapping all hold — every other inbound update remains exactly as metadata-only as
  before. Message bodies are encrypted at rest via the existing credential vault; fixed-default
  retention nulls message bodies 30 days after a conversation is archived and permanently deletes an
  archived conversation 90 days after archival. Backend only: no chat widget UI, no operator workflow
  UI, no new administration tab or capability; those arrive in later milestones.

= 0.3.1 =
* Administration hub (M04.1, ADR-0020): the WordPress admin left menu now shows one entry,
  "Telegram Hub", with every previous screen (Overview, Bots, Events, Rules, Simulator, Event
  History, Visitor Tracking, Diagnostics) reached through deep-linkable, bookmarkable horizontal
  tabs (`admin.php?page=universal-telegram&tab=<id>`), plus a new Settings tab exposing plugin-wide
  configuration (uninstall data removal, Telegram/event/dispatch/fatal-marker retention) that
  previously had no admin UI. Every existing form, nonce, capability check, and handler is
  unchanged. Every retired admin page slug remains permanently reachable and redirects a `GET`
  request from an authorized user to its equivalent tab (temporary, 302 — never permanent); no
  redirect is ever issued for a non-`GET` request. The plugin-row Settings link now opens the
  Settings tab directly. Pure navigation restructuring: no event, rule, persistence, Telegram,
  WooCommerce, tracking, chat, or AI behavior change.

= 0.3.0 =
* Visitor and browser events: a dependency-free, cache-safe tracking client (page views, navigation,
  search, clicks, JavaScript errors) delivered through a strictly allow-listed, unauthenticated
  ingestion endpoint with a two-tier, non-bypassable rate limit; disabled by default at both a master
  switch and per-event-family toggles; client-side consent suppression (explicitly documented as
  non-verifiable by the server); no cookies, fingerprinting, persistent visitor identity, or raw IP/
  user-agent transmission. When WooCommerce is active, adds product-view and classic-checkout
  add-to-cart intent signals (block add-to-cart is intentionally unsupported) and a checkout
  page-entry signal, all distinct from and never duplicating WooCommerce's own confirmed events.
  Administration reuses the existing plugin-management capability; no new capability is introduced.

= 0.2.0 =
* Normalized events and notifications: deterministic event identity and a safety-wrapped emission
  facade; core WordPress event coverage (logins, user lifecycle, content publishing, plugin/update
  activity, scheduled-task and REST-request failures with feedback-loop exclusions, bounded
  privacy-safe fatal-error capture); a PUBLIC-only durable event history; an administrator-
  configurable notification rule engine with AND-only conditions, deterministic evaluation, message
  templates, an idempotent and honestly-scoped dispatch state model (no duplicate rule-engine
  handoff across retries), cooldown, event/rule administration screens, and a rule-simulation tool
  with no live Telegram traffic.

= 0.1.0 =
* Telegram connectivity: multiple bot profiles, destinations (private/group/supergroup/channel,
  forum-topic routing), outbound queue with encrypted message storage, authenticated and
  replay-protected inbound webhook with a failure-safe registration/rotation protocol, per-bot and
  per-destination rate limiting and circuit breaking, dead-letter handling with admin requeue,
  retention-based cleanup, bot/destination management screen, queue-health alerting, best-effort
  webhook deregistration on uninstall.

= 0.0.1 =
* Product foundation: composition root, module boundaries, persistence and migration framework, queue
  abstraction, audit logging, privacy classification, credential vault, capability model, diagnostics.
