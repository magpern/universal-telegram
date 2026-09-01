=== Telegram Operations Hub for WordPress ===
Contributors: magpern
Tags: telegram, woocommerce, notifications
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.19.1
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
routing. This release adds an operator-assist-only AI draft assistant (M09, ADR-0028): an authorized
operator may explicitly request an AI-generated draft reply for a conversation, grounded only in
administrator-approved published content, with a visitor acknowledgement gate, race-safe bounded
concurrency, and a structural guarantee that a draft can never be sent to a visitor or Telegram
automatically. AI-first/automatic customer response is not part of this release; it arrives in a
later milestone (M10), gated on this one's own safety and quality record.

== Delivery guarantees ==

Telegram message delivery is **at-least-once, not exactly-once**. If a send attempt fails at the
network-transport level — no response received at all from Telegram, as distinct from a definite
error response — it is genuinely unknown whether Telegram already received and processed the
message before the failure. Because the plugin's retry mechanisms may then send the identical
message again, a message affected by this specific failure mode may occasionally be delivered more
than once. The delivery log flags any message this happened to with a "possible duplicate delivery"
indicator, so administrators have an accurate signal rather than an unearned exactly-once guarantee.

== Changelog ==

= 0.19.1 =
* Automatic updates from a private update server (bundled Plugin Update Checker v5); base URL read from the PRIVATE_UPDATE_SERVER constant, inert when it is not defined.

= 0.19.0 =
* SC-M03 final cutover (ADR-0042): a new cutover-orchestration state
  machine layered above (not replacing) legacy-chat quiescence; a
  monotonic-CAS `prepared → active` binding activation saga with
  whole-cohort preflight and automatic in-run compensation on any
  commit-phase failure; a cohort-aware amendment to the existing
  deferred-update replay loop dispositioning each buffered row through
  either the existing Support Chat Contract handoff path or the existing
  legacy replay path, decided live at drain time; a UT-owned incident
  record for pre-dispatch failures and Support Chat provenance conflicts,
  including a narrowly-scoped, Product-Owner-approved terminal-
  acknowledgement exception; and a correction to inbound topic-lifecycle
  service-message handling so an active-binding topic's event reaches
  Support Chat's existing `report_channel_unavailable` path instead of
  legacy conversation mutation. New
  `wp universal-telegram cutover {status,begin,activate,confirm-complete,incident-acknowledge,recover}`
  command. No production quiescence, cutover, route switch, soak,
  rollback, or deletion is performed by this release — the engine only.

= 0.17.0 =
* Legacy-chat quiescence (SC-M03 Work Package 2, ADR-0040): a WP-CLI-only,
  four-state (idle/draining/quiescent/replaying) write-blocking and drain
  mechanism for every legacy conversation mutation surface, so Support
  Chat's migration engine can safely read a frozen snapshot. New
  `wp universal-telegram quiescence {enter,status,confirm,exit,replay-deferred-updates}`
  command. Telegram webhook updates arriving during a blocking state are
  captured encrypted (never dropped) and replayed in order once the window
  closes. New in-process `quiescence_status()` accessor exposes the signal
  to Support Chat's `QuiescenceStateProvider`, with no REST route, Ajax
  handler, or shared secret. No existing entry point behaves differently
  while quiescence is not in use (default state is always `idle`).
* Database change: db_version 32 -> 33 (three new tables: quiescence
  current-state singleton, an append-only transition audit trail, and an
  encrypted deferred-Telegram-update buffer).

= 0.16.0 =
* Friendly notification tester and grouped Hub navigation (M08.2): the developer-oriented Simulator
  tab is replaced by "Test notifications" — pick an existing notification or a custom event scenario,
  fill in plain-language example values, and see a friendly would-send/would-not-send result with a
  rendered preview, inline beside the form, never a raw event id, field path, or `{{token}}` syntax.
  No Telegram message is ever sent and no dispatch log, event history, or audit log row is ever
  written by a test. The Hub's flat top-level tab row is also reduced to seven grouped areas
  (Overview, Bots, Notifications & activity, Conversations, AI, Settings, Diagnostics), with every
  existing screen reused unchanged as an accessible secondary tab within its new area; every old
  direct tab link, including a bookmarked Simulator URL, still resolves to its own content.
* No database change (db_version stays 30).

= 0.15.0 =
* Friendly rule builder and notification presets (M08.1, ADR-0032): the Rules tab is now a
  "Notifications" page — an active-notification list (name, event, destination, status), a
  Store-essentials recommendation panel, three popular starting templates, and the full
  template catalog behind per-family accordions, rather than one long page of equally-weighted
  options. Creating or editing a notification opens a dedicated, plain-language builder: a
  grouped event picker, a visual "Only when…" condition builder (typed operators, all/any
  matching), a friendly message editor with a field-insert menu and an "Example notification
  preview" — no JSON, schema field paths, technical event names, or template syntax required
  anywhere. Existing rules keep working unchanged; a rule the visual builder cannot represent
  stays editable with its conditions preserved exactly and a read-only compatibility notice,
  never silently altered. Condition evaluation gains an explicit all/any match mode and three
  additional comparison operators, defaulting to every existing rule's own current behavior.
* Fixed: a notification's own literal message text (e.g. "Product #123 added (in stock).") was
  never escaped for Telegram's MarkdownV2 parse mode, only the values it substituted — any literal
  `.`, `#`, `(`, `)`, etc. caused Telegram to reject the message outright, dead-lettering it. Every
  built-in preset was affected. Literal template text is now escaped exactly like a substituted
  field value.
* The "Added to cart" preset now names the product (a new `payload.product_name` field on the
  `woocommerce.cart_item_added` event) instead of showing only its numeric product ID.
* The "New user registered" preset and event now include the account's username, name, and email
  address (new `subject.username`, `subject.name`, `subject.email` fields), not just its numeric
  ID. These are usable in message templates and conditions but, like other personal data, are
  never written to the durable event history.
* `wordpress.user_registered` also gains `subject.country` and `subject.region`, resolved from
  the Universal Geo Context plugin when it is active (silently absent otherwise) - never the raw
  IP address itself.
* The message editor's field-insert menu now has a companion "Insert emoji" menu, for admins who
  want emoji in their own notification text. Built-in presets remain plain professional text.

= 0.14.1 =
* When WordPress deletes a destination (or purges a conversation that exclusively
  owns a forum-topic destination), best-effort `deleteForumTopic` runs first so
  Telegram is not left with orphan topics. Destinations list exposes Delete again.

= 0.14.0 =
* Conversation topic lifecycle and repair (M07.1, ADR-0031): local Archive (secret
  revoked, Telegram topic retained); permanent delete only from the archived Operator
  Inbox detail with a second confirming POST; queued `deleteForumTopic` for eligible
  plugin-created topics only (exclusive destination ownership); topic-unavailable
  recognition via exact `(bot_id, chat_id, message_thread_id)` identity; truthful
  visitor `delivery_state` (`routed` vs `sent`); open-but-unavailable POST returns
  409 `conversation_unavailable`. Adds topic lifecycle columns and UNIQUE
  `conversations.destination_id` (db_version 28 -> 29). Implemented on the combined
  M11 feature branch; validation, PR, merge, and release remain deferred to that gate.

= 0.13.0 =
* Digests and Operational Intelligence, remainder (M11B, ADR-0030, completing M11 together with
  M11A/ADR-0029 as one combined release): a daily Operational Summary (orders, payments, checkout
  failures, JavaScript-error categories, and a visitor-to-order funnel, all aggregate counts only);
  three fixed threshold alerts (checkout failure count, order failure spike, JS-error category
  spike), default disabled, each independently configurable and bounded by a fixed one-hour re-fire
  cooldown so a persisting condition can never flood Telegram; and an operator-triggered,
  operator-reviewed AI-assisted rendering of the Operational Summary's own aggregate counts,
  reusing the AI Draft Assistant's provider configuration (M09) but never auto-sent to Telegram or
  any visitor — displayed in wp-admin only, with a fixed "NOT SENT" notice. AI-summary generation
  shares M09's existing site-wide two-slot provider-concurrency cap rather than introducing a second
  one. Every destination reuses the same conversation-topic-exclusion eligibility rule M11A already
  established. Adds four new database tables (db_version 24 -> 28).

= 0.12.0 =
* Visitor Activity Digest (M11A, ADR-0029, first non-AI slice of M11): routine visitor activity —
  page views, navigation, search, product views, and cart/checkout intent — is batched into a single
  periodic aggregate Telegram summary instead of one message per event, once an administrator
  explicitly enables it and selects a bot and destination on the Visitor Tracking settings screen. A
  digest sends as soon as either an administrator-configured event threshold (default 50, range
  10-500) or a maximum wait (default 15 minutes, range 5-60) is reached. Digest content is aggregate
  counts by fixed category and page type only — never a URL, path, search term, or any
  visitor-identifying value. Batching is opt-in and self-healing: while disabled, or if the selected
  bot/destination becomes invalid (including a website-chat conversation topic, which can never be
  selected as a target), affected notification rules keep sending individually exactly as before,
  with the condition surfaced in Diagnostics rather than failing silently. Adds two new database
  tables (db_version 22 -> 24).

= 0.11.1 =
* Corrective fix: unchecking a Visitor Tracking checkbox (most visibly "Exclude administrators")
  and saving did not persist the uncheck, because the save handler merged submitted fields over the
  currently-stored settings before sanitizing, and an unchecked HTML checkbox is never present in
  the submitted request. The same latent bug on the plugin-wide Settings page's "Allow anonymous
  chat" checkbox is fixed identically. Also removes the "Clicks" family toggle and "Click target
  allowlist" field from the ordinary Visitor Tracking settings screen: both required a
  developer-supplied identifier that an ordinary WordPress administrator cannot configure safely or
  meaningfully. Custom click tracking is now inactive unconditionally, including for any value
  already stored from before this release — no replacement developer-facing control is offered in
  this release. No schema or database-version change.

= 0.11.0 =
* AI draft assistant (M09, ADR-0028): operator-assist only — no AI-first or automated customer
  response, and no code path can ever send a draft to a visitor or Telegram. An administrator
  configures the OpenAI provider (server-side API only, disabled by default until a non-empty
  credential and bounded model identifier are both set), a visitor-disclosure text/version, and
  explicitly approves the published, non-password-protected posts/pages a draft may be grounded in;
  editing an approved post excludes it again until re-approved. A visitor is shown an unchecked,
  optional acknowledgement checkbox before their first message, only while AI is enabled; only an
  explicit `ai_ack=true` on that exact request records eligibility, identically for anonymous and
  logged-in chat, with no new tracking identifier — declined, omitted, malformed, pre-enablement, and
  stale-disclosure-version conversations are permanently ineligible, never backfilled or re-prompted.
  An authorized operator may request a draft from the existing conversation-detail screen; the
  request is queued, retrieval is always source-only (zero-match is a fixed terminal outcome with no
  provider call), and the fixed prompt policy delimits approved-source and conversation content as
  data, never instructions. Draft generation uses a race-safe locking design (a conversation-row
  lock enforces one active draft per conversation and doubles as request idempotency; a
  migration-seeded singleton config-row lock enforces a site-wide cap of two concurrent generations),
  a 90-second generation lease with compare-and-set completion so a crashed worker's row is never
  stranded, and a bounded, idempotent recurring sweep that recovers or dead-letters a stale lease
  within a fixed time and a shared five-attempt budget — provider invocation is at-least-once, not
  exactly-once, an explicit and bounded limitation. A generated draft is always labelled "NOT SENT"
  and requires the operator to copy, edit, and send manually; approving a draft is an audit-trail
  action only. `AiDraftRepository` is referenced only by a fixed six-class allow-list, enforced by a
  structural test — no visitor-facing, widget, webhook, or Telegram-outbound code can reference it.
  Adds two tables and one additive conversation column (`db_version` `18` -> `22`).

= 0.10.0 =
* Administrative bot commands (M08, ADR-0027): a fixed, allow-listed set of Telegram bot commands
  for mapped operators — `/help`, `/whoami`, `/status`, `/errors`, `/visitors`, `/orders`, `/order`,
  `/stock`, `/sales`, `/conversations`, `/here`, `/presence`, `/claim`, `/release`, `/resolve`,
  `/reopen`, `/confirm`. A command is recognized only via Telegram's own `bot_command` message
  entity at offset 0, addressed to this bot; every command requires both the existing M07 operator
  identity mapping and a freshly evaluated `MANAGE_CONVERSATIONS` capability — an unmapped or
  capability-revoked sender gets an identical, non-disclosing outcome. Unknown forum topics are
  fully silent for every command. `/order`, `/stock`, and `/sales` are backed by a new, read-only
  `WooCommerceCommandQueryService` using only documented, HPOS-safe WooCommerce APIs, bounded to a
  500-record/5-page safe processing cap — never a `wc_get_orders(limit: -1)` call, and never a
  partial figure past the cap. `/resolve` and `/reopen` require a `/confirm` follow-up within 60
  seconds (a WordPress core transient, matching the existing Diagnostics-page precedent); `/reopen`
  is additionally restricted to the conversation's current assignee, narrower than the Hub's own
  administrator-level reopen action. Every command reply uses the existing outbound Telegram-send
  path — no second send mechanism, no new REST route, no schema change (`db_version` stays `18`).

= 0.9.0 =
* Operator workflow (M07, ADR-0026): a manual WordPress-user <-> Telegram numeric-id identity
  mapping is now the plugin's own inbound Telegram operator-authorization gate — a Telegram reply
  from an unmapped account never reaches the visitor side. Adds operator presence (available/busy/
  offline), a genuine per-operator unread model, an operator inbox and conversation detail view in
  the Hub, encrypted internal notes, concurrency-safe (compare-and-set) assignment and unassignment,
  a `resolved -> open` reopen transition, archived-only manual conversation deletion via a shared
  purge service also used by scheduled retention, and bounded conversation search. The Telegram
  sender id and username are both treated as SENSITIVE personal data throughout — never rendered,
  URL-exposed, or search-filtered. Deleting a mapped operator's WordPress account now also clears
  their Telegram-sender message attribution, anonymizes their note authorship, and unassigns their
  conversations, in addition to the existing visitor-owner cleanup. New `MANAGE_CONVERSATIONS`
  capability for operator self-service actions; administrator overrides continue to use `MANAGE`.

= 0.8.0 =
* Authenticated chat access and UX redesign (M06.3.1, ADR-0025): corrects M06.3's rejected
  visitor-entered-name onboarding. Chat now requires an authenticated WordPress user; a logged-out
  visitor sees only "Sign in to chat" (and "Create account" when registration is enabled) — no
  name field, composer, history, or conversation control is ever shown before sign-in. The display
  name is now derived server-side from the WordPress account (a fixed generic fallback when empty)
  and is never entered by the visitor. Conversations gain a persistent, database-enforced owner,
  with exactly one active conversation per account and bot guaranteed by a unique index — never a
  request-timing assumption. Every conversation REST route now explicitly requires a WordPress
  cookie session and nonce, additive to the existing bearer-secret credential, plus an ownership
  match on message/poll. A returning, authenticated visitor resumes their conversation across
  browsers/sessions via a new resume endpoint. Opening the chat panel creates nothing; the first
  Send invisibly starts and posts the conversation. The visible "Start chat" and "End conversation"
  controls are removed. Deleting a WordPress account revokes its conversations' bearer secret and
  clears the ownership link. The default presentation preset is now a theme-derived style using the
  active WordPress theme's own colors, with the previous static presets still available. The
  message log now respects a visitor's manual upward scroll, showing a "New messages" control
  instead of forcing the view back down. Pre-existing (M05-M06.3) conversations remain in the
  database, subject to existing retention, but are no longer reachable through the widget, since
  they were never associated with an authenticated account.

= 0.7.0 =
* Chat identity, lifecycle, and presentation (M06.3, ADR-0024): the chat widget now requires a
  visitor display name (1-80 characters) before the first message can be persisted or routed —
  encrypted at rest, write-once, never exposed back to the client beyond a `display_name_required`
  boolean on the start/poll responses so a page reload correctly re-derives whether the name step is
  still needed. The name feeds the Telegram forum-topic title (a Unicode-safe truncated name plus a
  short, non-secret reference, bounded to Telegram's 128-character cap) and a one-time context header
  on the conversation's first forwarded message only. Pre-existing conversations remain fully
  functional with no name and no invented replacement. Adds three compiled, static presentation
  presets (classic/modern/minimal; modern default), geometry and motion-default appearance tokens on
  a documented, stable `.ut-chat-widget` selector contract, and configurable participant labels
  (default "You"/"Support") — no custom-CSS editor, external stylesheet URL, or runtime-generated
  stylesheet; a global preset/appearance change becomes visible after the site's own page-cache purge
  or expiry, not instantly. The visitor's own reduced-motion preference is always honoured regardless
  of the admin's motion-default setting. Conversations inactive for 30 days (no visitor or operator
  message) now auto-resolve through the existing status-transition map and are then archived by the
  existing daily retention pass — never a raw status write, never reopening an already-archived
  conversation, and still no manual delete control. Chat-widget-created Telegram topic destinations no
  longer appear in the Bots tab's manually configured destination list or expose a "Send test message"
  action; they are shown separately, read-only. Adds `db_version` 15 (one new nullable, encrypted
  column on the conversations table). Email collection, transcript delivery, and logged-in WooCommerce
  order context remain deferred to future milestones.

= 0.6.2 =
* Interactive Telegram delivery corrective pass (M06.2 corrective plan v2, ADR-0023 amendment):
  live testing found the 0.6.1 mechanism did not hold up on a busy, multi-plugin Action Scheduler
  install (a real chat message sat 33 seconds before its outbound send even began). Primary
  interactive latency now comes from a bounded (4-second), claim-protected, in-process synchronous
  attempt sharing the exact same delivery logic as the durable queue worker, with a further bounded
  (5-second) in-process fallback layer that does not depend on Action Scheduler's shared batch slot
  at all; the previous expedited-dispatch trigger is retained but demoted to a final best-effort
  nudge after both bounded layers. A persisted, atomic, time-leased claim on both outbound sends and
  topic creation prevents two callers from ever being concurrently active on the same row, with
  automatic crash recovery via lease expiry; the delivery guarantee this now honestly documents is
  at-least-once, not exactly-once. A widget bug that minted a fresh idempotency key on every retry,
  compounded by checking rate limits before the idempotency-replay lookup, is fixed: the per-IP new
  conversation limit is split into hourly and daily buckets, a start-idempotency replay is checked
  and its secret verified before any new-conversation rate-limit token is consumed, and a wrong
  secret against a valid replay key now consumes a dedicated, independent auth-failure bucket
  instead. Responses gain an optional, fixed-value `reason` field and messages gain a
  `delivery_state` in the poll response, so the widget can show a truthful pending/rate-limited state
  instead of one generic failure. Adds `db_version` 14 (two new nullable lease columns; no other
  schema impact). Patch release; no other behavior change.

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
  per-destination rate limiting and circuit breaking, dead-letter handling with admin requeue and
  dismiss (dismiss removes a reviewed dead letter that cannot or should not be resent; the
  queue-health banner stays until underlying conditions clear), retention-based cleanup,
  bot/destination management screen, queue-health alerting, best-effort webhook deregistration on
  uninstall.

= 0.0.1 =
* Product foundation: composition root, module boundaries, persistence and migration framework, queue
  abstraction, audit logging, privacy classification, credential vault, capability model, diagnostics.
