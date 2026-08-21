=== Telegram Operations Hub for WordPress ===
Contributors: magpern
Tags: telegram, woocommerce, notifications
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
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
fingerprinting, persistent visitor identity, or raw IP/user-agent transmission. Conversations, the
chat widget, and AI assistance are not part of this release; they arrive in later milestones.

== Delivery guarantees ==

Telegram message delivery is **at-least-once, not exactly-once**. If a send attempt fails at the
network-transport level — no response received at all from Telegram, as distinct from a definite
error response — it is genuinely unknown whether Telegram already received and processed the
message before the failure. Because the plugin's retry mechanisms may then send the identical
message again, a message affected by this specific failure mode may occasionally be delivered more
than once. The delivery log flags any message this happened to with a "possible duplicate delivery"
indicator, so administrators have an accurate signal rather than an unearned exactly-once guarantee.

== Changelog ==

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
