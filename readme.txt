=== Telegram Operations Hub for WordPress ===
Contributors: magpern
Tags: telegram, woocommerce, notifications
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bidirectional Telegram bot connectivity for WordPress: configure one or more bots, connect
destinations, and send and receive messages, with no separately deployed companion application.

== Description ==

This release makes the plugin's core premise real: an administrator connects a Telegram bot and
confirms messages flow bidirectionally. It supports multiple independent bot profiles, destinations
across private chats, groups, supergroups (including forum topics), and channels, an outbound queue,
an authenticated inbound webhook, retry and rate limiting, circuit breaking, dead-letter handling,
and diagnostics. Event capture, rule automation, conversations, the chat widget, and AI assistance
are not part of this release; they arrive in later milestones.

== Delivery guarantees ==

Telegram message delivery is **at-least-once, not exactly-once**. If a send attempt fails at the
network-transport level — no response received at all from Telegram, as distinct from a definite
error response — it is genuinely unknown whether Telegram already received and processed the
message before the failure. Because the plugin's retry mechanisms may then send the identical
message again, a message affected by this specific failure mode may occasionally be delivered more
than once. The delivery log flags any message this happened to with a "possible duplicate delivery"
indicator, so administrators have an accurate signal rather than an unearned exactly-once guarantee.

== Changelog ==

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
