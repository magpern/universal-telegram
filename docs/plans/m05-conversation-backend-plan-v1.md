# M05 — Conversation Backend — Implementation Plan v1

Status: Frozen — implementation authorized. This document is self-contained: it does not require a reader to consult any earlier conversation draft or planning-session transcript.

## 1. Charter, baseline, and dependencies

Implements master-plan.md §13 "M5 — Conversation backend" (objective: establish
secure persistent conversations) and master-plan.md §6.1–6.3 (conversation
model, Telegram routing, visitor continuity). §6.4 (Attachments) is explicitly
"later chat releases" — out of scope. §7 (chat widget) is M6; §8 (admin bot
commands) is M8; §9's "Operator identity mapping", "Assignment" UI, and
"Conversation status controls" UI are M7 (master-plan §13 "M7 — Operator
workflow"). M5 delivers only the backend M7 will later drive.

Baseline: `HEAD == origin/main == 2dce4e5` (M04.1 closure commit), working
tree clean. M04.1 is merged and technically closed (PR #7, merge `e00fb1c`,
closure `2dce4e5`, CI green). **Product Owner acceptance of M04.1 is still
pending** (Vlad's manual menu/navigation review) — recorded, not a blocker:
per ADR-0011, M00–M09 close on automated evidence alone, and nothing in M05
depends on M04.1's navigation surface beyond the already-merged Hub shell.

ADR-0005 already names `Conversations` (`UniversalTelegram\Conversations`) as
a top-level boundary "owned by M05" — no boundary ADR needed. **One ADR is
proposed, ADR-0021** (§7), covering the new persistence model, the visitor
bearer-token security boundary, the new public REST contract, and the narrow,
conversation-scoped extension to the existing webhook-authenticity boundary
(ADR-0013). No second ADR — nothing else in this plan introduces a distinct
architecture, security boundary, persistence model, or public contract beyond
what ADR-0021 already covers.

## 2. Scope

In scope (backend only, no frontend widget, no admin UI):
- Conversation and message persistence, one row per conversation, one row
  per message (visitor or operator).
- A two-part visitor credential: a public `conversation_uuid` plus a private
  bearer secret, verified by a one-way password hash — first identity
  primitive of its kind in this plugin (M04's visitor events are anonymous).
- Telegram forum-topic creation, one topic per conversation, created only
  after the conversation's first accepted visitor message, idempotent and
  concurrency-safe.
- Bidirectional routing: visitor message → outbound Telegram message in the
  conversation's topic (via a minimal, conversation-owned extension over the
  existing outbound pipeline); Telegram reply in that topic → conversation
  message, gated by chat identity + known topic mapping + update dedup.
- A conversation status field and state machine
  (`new → open ↔ waiting_for_visitor/waiting_for_operator → resolved →
  archived`) and a nullable `assigned_operator_id` column — data model only;
  no M05 code path sets `resolved` or ever writes `assigned_operator_id`
  (reserved for M7).
- A minimal, unauthenticated (at the WP-REST layer), token-authenticated REST
  contract for conversation start, visitor message post, and short-polling —
  the seam M6's widget will call. No widget markup, JS, or CSS.
- Fixed-default retention/cleanup, reusing `CredentialVault`, no settings UI.

Out of scope: chat widget UI (M6), operator workflow UI/identity mapping/
audit/reopening (M7), attachments (§6.4), admin bot commands (M8), AI
participation (M9+; `ai_participation_state` column reserved, unused), any
new Hub tab, any new WordPress capability, any retention *settings* UI, token
rotation UI.

## 3. Visitor identity and authentication protocol

Two-part credential, generated once at conversation start:
- `conversation_uuid` — public, opaque (UUIDv4), returned to the visitor,
  used as the REST path segment. Non-secret; safe to appear in logs, the
  Telegram topic title (non-sensitive reference only, per master-plan §6.2),
  and diagnostics.
- `secret` — `bin2hex(random_bytes(32))`, returned to the visitor **exactly
  once**, in the conversation-start response body only. Never persisted in
  plaintext; never logged; never placed in a URL, query string, response
  history field, diagnostics view, or error message.

Authoritative design, stated once, referenced everywhere else in this plan:
the `conversation_uuid` row is fetched directly by its own unique, indexed
column — there is no lookup-by-token step of any kind, and no bearer token
is ever used as, or derived into, a lookup key. Only the secret's
`password_hash( $secret, PASSWORD_DEFAULT )` is stored, in `secret_hash`.
Authentication is `password_verify( $presented_secret, $row->secret_hash )`
only — `password_verify()` is internally timing-safe on its own; no
`hash_equals()` call exists anywhere in this protocol, and none is needed.

Transport: the secret is accepted only via the `Authorization: Bearer
<secret>` request header, never elsewhere. Because it is never placed in a
URL, it is never at risk of leaking through a browser `Referer` header on
subsequent navigation.

Rotation/revocation, exact semantics: M05 ships no in-place secret-rotation
endpoint or UI — a leaked or expiring secret cannot be replaced in place in
this milestone, only revoked. Revocation happens at two points, both by
nulling `secret_hash` (never by deleting the row itself as the revocation
act): (a) at the `resolved → archived` transition (§7), so an archived
conversation stops authenticating immediately; (b) implicitly, permanently,
when the row itself is deleted by retention cleanup (§9) — at which point
`secret_hash` no longer exists to verify against. A revoked secret, an
unknown `conversation_uuid`, a malformed `conversation_uuid`, a missing
`Authorization` header, and a secret that fails `password_verify()` all
produce the **identical, controlled response**: `404`, body `{"ok":false}`,
no distinguishing header. No `401` is ever returned, since a 401 would
itself disclose that the `conversation_uuid` is valid and the failure is
auth-specific; the uniform 404 never confirms or denies another
conversation's existence, revoked or otherwise.

## 4. Public REST boundary

Namespace `universal-telegram/v1`, alongside the existing webhook route.

- `POST /conversations` — start. Body: `{"chat_profile": "<optional, must
  match a configured profile>"}`; no free-text visitor input at this step.
  Response (once): `{"ok":true,"conversation_uuid":...,"secret":...}`.
- `POST /conversations/{conversation_uuid}/messages` — post. Body:
  `{"text": "<=4096 UTF-8 chars>"}`; length checked before any DB write;
  malformed JSON or wrong `Content-Type` → generic `400`, no partial write.
- `GET /conversations/{conversation_uuid}?since_id=<int>` — poll. No body.
  Cursor semantics: `since_id` defaults to `0` on a caller's first poll (all
  messages returned); every subsequent poll passes the highest `id` it has
  already seen, and the response contains only messages with `id >
  since_id`, ascending by `id` — an ordinary keyset cursor, no page tokens,
  no server-side session state beyond the row data itself. Response:
  `{"ok":true,"status":...,"messages":[{"id","direction","text",
  "created_at"}...]}`, where `text` is the message's own **decrypted**
  plaintext (decryption happens server-side, per-request, for the
  authenticated caller only — this is expected and is not a violation of
  §9's at-rest encryption). Neither this nor any other M05 response ever
  includes the bearer secret (returned exactly once, only in the `start`
  response body), `secret_hash`, or any raw `body_ciphertext` value — those
  three fields are never serialized into an HTTP response under any
  circumstance, success or error.

Cross-cutting: `Content-Type: application/json` required on write routes and
returned on all three; every response carries `Cache-Control: no-store,
no-cache, must-revalidate` and `Pragma: no-cache`, so no page cache or CDN
layer (including the existing Cloudflare-proxied setup) stores a response
containing conversation state. No `Access-Control-Allow-Origin` header is
emitted — same-origin only; M05 adds no cross-origin capability. Polling is
**short polling only**: each `GET` is one bounded, synchronous request-
response cycle — no long-held PHP-FPM/worker connection, no chunked
transfer, no Server-Sent Events, no WebSocket. M05 defines only this
contract; client polling cadence is M6's concern.

Rate limits, enforced before any database row or queue job is created:
- `start`: a per-IP/day HMAC bucket (reusing `IngestController`'s existing
  bucket pattern) **and** a non-bypassable, site-wide rolling cap — a single
  atomically-incremented counter checked and incremented first, independent
  of any per-IP identity, so IP rotation cannot defeat it. Breach → `429`
  with `Retry-After`, zero rows or jobs created.
- `post-message`: an independent per-conversation token bucket **and** the
  same site-wide cap category, so many distinct conversations cannot
  aggregate past the site-wide bound either.
- `poll`: a per-conversation minimum-interval check (`429` + `Retry-After` if
  polled faster than a fixed floor) **and** the site-wide cap.

Every controlled error (`400`, `404`, `429`) returns a generic, non-
sensitive body; no response ever reveals whether a *different*
`conversation_uuid` exists, is active, or is archived.

## 5. Topic creation and outbound delivery

The Telegram topic is created **only after the conversation's first accepted
visitor message**, not at `start` — this is the explicit recommendation, to
prevent repeated/abandoned `start` calls from generating unlimited empty
Telegram topics.

Idempotency and concurrency safety: `conversations.topic_creation_state`
(`none|pending|created|failed`) is advanced by a single atomic conditional
update — `UPDATE ... SET topic_creation_state='pending' WHERE id=? AND
topic_creation_state='none'`, gated on `$wpdb->rows_affected` — before a
`TopicCreationHandler` job is ever enqueued. Only the caller that wins this
compare-and-set enqueues a job, so retries, duplicate first-message
submissions, or concurrent requests can never produce two topics for one
conversation.

**Destinations row — existing table, no new schema.** M05 adds exactly two
new tables (§8, steps 11–12); `universal_telegram_destinations` is not one
of them — it already exists as of M01 (`Migrator::step_3`), and M05 writes
into it, it does not extend or alter it. On topic-creation success, the
handler sets `conversations.telegram_topic_id` and
`topic_creation_state='created'`, then inserts **one** row into the existing
`destinations` table: `kind='supergroup'`, the configured conversation-
support `chat_id`, `message_thread_id=telegram_topic_id`,
`conversations.destination_id` is set to this row's `id`. Ownership: this
row is owned by the conversation that caused its creation — no other
destination-management code path (bot/destination admin screens, rule
delivery) creates or targets a conversation's own destination row. Its
uniqueness is enforced by the table's own pre-existing constraint,
`UNIQUE(bot_id, chat_id, message_thread_id)` — one destination row per
Telegram topic can ever exist, which is exactly the same guarantee a second,
conversation-specific unique constraint would provide, so none is added.
Lifecycle: created once, at topic-creation success, never updated
afterward. Retention cleanup: deleted by the Conversations retention
handler (§9) in the same pass that deletes the conversation and its
messages, keyed by `conversations.destination_id` — never left behind as an
orphaned row, and never touched by `Telegram\Outbound\
RetentionCleanupHandler`, which only ever acts on message/delivery-log rows,
not destination rows.

On failure, bounded retries (reusing `Queue\RetryPolicy`'s existing
attempt cap) end in `topic_creation_state='failed'`, surfaced as a degraded
conversation state, never a silent drop.

Outbound routing extension — precisely scoped, not "unchanged": the send
pipeline itself (`OutboundMessageRepository`, `MessageDispatcher`,
`SendMessageHandler`, the reliability layer) is genuinely untouched. The
only new code is a `Conversations`-owned adapter that, once
`topic_creation_state='created'`, calls
`OutboundMessageRepository::create( $bot_id, $destination_id, ... )` against
the destination row created above — the minimal seam required to route a
conversation's messages into its own forum topic through the existing
pipeline unmodified.

Queue-before-topic behavior: every visitor message — including the first —
is persisted as a `conversation_messages` row immediately, so nothing is
ever lost while a topic is `pending`. Its outbound send is deferred and
re-checked on the same delay-scheduling primitive `SendMessageHandler`
already uses, until `topic_creation_state='created'` or a bounded maximum
wait (5 minutes) elapses; on timeout the message is marked
`delivery_state='failed'` but remains stored and visible in the
conversation — never silently dropped.

## 6. Inbound webhook security and routing

Every existing `WebhookController` verification step runs first, unchanged:
bot lookup by `bot_uuid`, `X-Telegram-Bot-Api-Secret-Token` verification,
body-size cap, JSON well-formedness, `update_id` presence — identical to
today, identical generic `401`/`400` on failure.

Conversation-scoped content capture proceeds **only if all three** hold:
1. `UpdateRepository::record()` returns `true` — this is a genuinely new
   `(bot_id, update_id)`, not a repeat (the existing `UNIQUE(bot_id,
   update_id)` / `INSERT IGNORE` mechanism is reused as-is for inbound
   dedup; no second dedup table).
2. The update's `chat_id` matches the configured conversation-support
   destination's `chat_id` for this bot — not merely any destination.
3. `message_thread_id` is present and matches an existing conversation's
   `telegram_topic_id` with `topic_creation_state='created'`.

Telegram delivers message text as plaintext inside the webhook's JSON body —
there is nothing to decrypt on the way in. Validation order is therefore:
run the three gates above first, against metadata only (dedup, `chat_id`,
`message_thread_id`), with the message text left untouched in the request
body throughout; only once all three gates pass is the plaintext text read
out of the payload, immediately encrypted via `CredentialVault::encrypt()`,
and the resulting ciphertext — never the plaintext — written to
`conversation_messages.body_ciphertext`. The plaintext value itself is held
only in local PHP variables for the span of that one encrypt call and is
never logged, never passed to `WP_REST_Response`, and never persisted in any
other column or table. Then: insert the row (`direction='operator'`), advance the status
transition map (§6 below is the map itself — this section is inbound
handling). If **any** of the three conditions fails — unknown chat, unknown
or not-yet-created topic, malformed thread reference, or a duplicate update
— behavior is byte-for-byte identical to `WebhookController`'s current
metadata-only path: `UpdateRepository::record()` (or no-op on a duplicate),
`200 OK`, no conversation row, no status transition, no visitor-visible
content, no exception. This is asserted as an explicit regression test
(§9.7).

## 7. Status/assignment transition map (data model only)

| Transition | Sole M05 actor |
|---|---|
| `new → open` | conversation-owned code, on the first visitor message whose topic is confirmed created |
| `open ↔ waiting_for_visitor` | inbound-routing path (§6), on an accepted operator reply |
| `open ↔ waiting_for_operator` | the visitor post-message path (§4), on an accepted visitor message |
| `* → resolved` | **no M05 code path** — reserved for M7's operator UI |
| `resolved → archived` | only the retention cleanup handler (§8), on schedule |

The public REST API never sets `resolved` or any status outside the map
above, and never writes `assigned_operator_id` under any circumstance —
`ConversationRepository::assign()` exists only as a domain method the
charter's "Status and assignment" deliverable requires and M7 will later
call from its own UI; nothing in M05's own request-handling or webhook code
paths invokes it. This satisfies the constraint directly: the visitor REST
API cannot set assignment or arbitrary status.

## 8. Persistence

Exactly two new tables, `Migrator` steps 11–12, `target_version()` 10 → 12
— no third table is introduced anywhere in this plan. The per-conversation
`destinations` row described in §5 is a row in the pre-existing
`universal_telegram_destinations` table (M01, step 3), not a new table and
not a schema change to it. No
foreign-key constraints, consistent with every existing table in this
plugin; referential integrity and cascade cleanup are enforced in
application code (repositories) and the retention handler, not the schema.
Both tables use `{$wpdb->get_charset_collate()}`, matching every existing
step.

`universal_telegram_conversations`: `id BIGINT UNSIGNED AUTO_INCREMENT PK`,
`conversation_uuid CHAR(36) NOT NULL`, `secret_hash VARCHAR(255) NULL`,
`bot_id BIGINT UNSIGNED NOT NULL`, `destination_id BIGINT UNSIGNED NULL`,
`chat_profile VARCHAR(64) NULL`, `status VARCHAR(20) NOT NULL DEFAULT
'new'`, `assigned_operator_id BIGINT UNSIGNED NULL`, `topic_creation_state
VARCHAR(16) NOT NULL DEFAULT 'none'`, `telegram_topic_id BIGINT NULL`,
`ai_participation_state VARCHAR(16) NOT NULL DEFAULT 'none'`,
`consent_state VARCHAR(16) NOT NULL DEFAULT 'unknown'`, `session_ref
VARCHAR(191) NULL`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NOT
NULL`, `resolved_at DATETIME NULL`, `expires_at DATETIME NULL`. Keys:
`PRIMARY(id)`, `UNIQUE(conversation_uuid)`, `KEY status`, `KEY
telegram_topic_id`, `KEY topic_creation_state` (supports the WP3
compare-and-set scan), `KEY created_at` (retention scan).

`universal_telegram_conversation_messages`: `id BIGINT UNSIGNED
AUTO_INCREMENT PK`, `conversation_id BIGINT UNSIGNED NOT NULL`,
`message_uuid CHAR(36) NOT NULL`, `direction VARCHAR(8) NOT NULL`,
`body_ciphertext LONGTEXT NULL`, `outbound_message_uuid CHAR(36) NULL`,
`telegram_message_id BIGINT NULL`, `delivery_state VARCHAR(16) NOT NULL
DEFAULT 'stored'`, `created_at DATETIME NOT NULL`. Keys: `PRIMARY(id)`,
`UNIQUE(message_uuid)`, `KEY conversation_created (conversation_id,
created_at)` (supports both the `since_id` poll cursor and per-conversation
retention cleanup), `KEY outbound_message_uuid` (application-level
integrity checks against the outbound table, no real FK).

Both steps follow `Migrator`'s existing raw-DDL, verify-via-information-
schema pattern exactly (step_9/step_10 are the closest precedent).

## 9. Encryption and retention

Encryption: `CredentialVault::encrypt()`/`decrypt()` is reused unchanged —
no new secret store. Primitive: AES-256-GCM (authenticated encryption,
already implemented), bound to a per-message context string (e.g.
`conversation_message:{message_uuid}`) as additional authenticated data, so
one message's ciphertext cannot be relinked to another. Key source: the
vault's existing three-tier fail-closed resolution (explicit
`UNIVERSAL_TELEGRAM_CREDENTIAL_KEY` constant → the four WP auth salts →
WP's own site auth salt) — identical to how bot tokens, webhook secrets, and
outbound message bodies are already protected. Key identifier/version: the
vault's existing envelope version tag and embedded key-source byte — no new
versioning scheme. Failure behavior: a decrypt failure never modifies the
stored ciphertext (existing vault contract); the poll endpoint renders an
unavailable message as an opaque placeholder, never an exception or a
plaintext leak. Never logged: plaintext message text, the raw or hashed
visitor secret, and the `Authorization` header value never reach PHP/WP
debug logs or the plugin's Audit/Diagnostics surfaces — the Audit boundary's
`context` column records only structural facts (`conversation_uuid`, event
type), never message text. Reusing `CredentialVault` rather than a second
vault is deliberate: the threat model and key-availability semantics are
identical to the existing outbound-message case; a second vault would
duplicate the three-tier logic with no materially different requirement.

Retention — exact fixed periods and schedule, no settings UI in M05:
- `conversation_message_retention_days = 30` — nulls `body_ciphertext` for
  messages of `archived` conversations older than 30 days (mirrors
  `Telegram\Outbound\RetentionCleanupHandler`'s own default value).
- `conversation_retention_days = 90` from the moment of archival — at 90
  days past `archived`, permanently deletes the conversation row, all its
  message rows, and its `destinations` row (§5) in one pass, so no orphaned
  topic mapping or encrypted-message remnant survives.
- Cleanup schedule: one recurring Action Scheduler action, run once daily,
  registered exactly like `RetentionCleanupHandler::HOOK`'s existing
  registration (`as_schedule_recurring_action( time() + DAY_IN_SECONDS,
  DAY_IN_SECONDS, ... )` in `Core\Plugin`) — no separate cron mechanism, no
  new scheduling primitive.
- Secret revocation piggybacks on this same daily pass, not a separate job:
  `secret_hash` is nulled at the `resolved → archived` transition the
  cleanup handler performs (§7), and again, permanently, when the row is
  deleted at the 90-day mark — the exact two points already defined in §3.
- Deletion is idempotent by construction: each run queries currently-
  matching rows and acts on them, exactly like the existing
  `RetentionCleanupHandler::run()` — re-running against already-cleaned data
  is a no-op.
- A message stuck in `delivery_state='failed'` past its bounded wait (§5) is
  not separately purged; it is retained and visible until the fixed windows
  above remove it, same as every other row.

## 10. Files (new unless noted)

`src/Conversations/Conversation.php`, `ConversationStatus.php`,
`ConversationRepository.php`, `ConversationMessage.php`,
`MessageRepository.php`, `VisitorTokenGenerator.php`,
`Rest/ConversationsController.php`, `TopicCreationHandler.php` (queue
handler, mirrors `SendMessageHandler`), `RetentionCleanupHandler.php`
(mirrors `Telegram\Outbound\RetentionCleanupHandler`, fixed constants, no
`Settings` involvement). Modified: `src/Persistence/Migrator.php` (+2 steps,
+2 table constants), `src/Telegram/Client/TelegramApiClient.php`
(+`create_forum_topic()`), `src/Telegram/Inbound/WebhookController.php`
(+conversation-scoped routing per §6), `src/Core/Plugin.php` (wiring). No
change to `Core/Configuration/Settings.php`, `SettingsPage`, or any Hub tab.

## 11. Work packages

**WP0 — Freeze ADR-0021 + this plan.** Docs only. Commit: `docs: freeze
ADR-0021 and M05 plan`.

**WP1 — Persistence + domain model.** Migrator steps 11–12;
`Conversation`/`ConversationMessage`/`ConversationStatus`/repositories,
including `ConversationRepository::assign()` as an unused-in-M05 domain
method per §7. Tests: migration step tests, repository CRUD + the transition
map's own allowed/disallowed cases. Acceptance: `db_version` reaches 12,
both tables verified via information schema. Commit: `feat(conversations):
add persistence layer and domain model`.

**WP2 — Visitor credential + REST start/post/poll endpoints.**
`VisitorTokenGenerator` (`conversation_uuid` + secret + `password_hash`),
`Rest/ConversationsController` with the rate limits and uniform-404 behavior
of §3–§4. Tests: credential generation/verification tests, secret-never-
exposed assertions, REST route tests (valid/missing/wrong secret, malformed
body, size limits, both rate-limit tiers, uniform-404 for both "unknown
conversation" and "bad secret"). Commit: `feat(conversations): add visitor
REST contract and bearer-secret authentication`.

**WP3 — Telegram topic creation.** `create_forum_topic()` on
`TelegramApiClient`; `TopicCreationHandler`; the compare-and-set
`topic_creation_state` guard; dynamic `destinations` row creation. Tests:
client method test (intercepted via `pre_http_request`, no live bot),
concurrency/duplicate-enqueue idempotency test, failure/bounded-retry test.
Commit: `feat(conversations): create Telegram forum topic per conversation,
idempotently, after the first visitor message`.

**WP4 — Outbound routing (visitor → Telegram).** The conversation-owned
adapter over the existing, unmodified `OutboundMessageRepository`/
`MessageDispatcher`; queue-before-topic deferral and bounded-wait timeout.
Tests: message stored immediately regardless of topic state; sent once
`created`; timeout produces a visible `failed` state, never data loss.
Commit: `feat(conversations): route visitor messages into the conversation's
Telegram topic`.

**WP5 — Inbound routing (Telegram → conversation) + status transitions.**
Extend `WebhookController` per §6 exactly; wire the transition map's
inbound-driven rows. Tests: conversation-thread reply stores a message and
transitions status; wrong-chat, unknown-topic, malformed-thread, and
duplicate-update cases are all regression-asserted as byte-for-byte
unchanged from today's metadata-only behavior. Commit: `feat(conversations):
route Telegram topic replies back to their conversation`.

**WP6 — Retention + docs/closure.** `Conversations\RetentionCleanupHandler`
with the fixed §9 defaults; ARCHITECTURE.md boundary-table row 9 updated;
closure record written. Commit: `feat(conversations): add retention cleanup
with fixed defaults; update architecture reference`, then `docs: record M05
technical closure`.

## 12. ADR-0021 (proposed)

Title: **Conversation Persistence, Bearer-Secret Visitor Authentication, and
Topic-Scoped Inbound Message Capture**. Status: Proposed. Decision: (a)
conversations and messages persist in the two tables of §8; (b) visitor
identity is a public `conversation_uuid` plus a private bearer secret,
verified by `password_hash`/`password_verify`, transported only via the
`Authorization` header, with uniform-404 failure responses that never
disclose another conversation's existence (§3); (c) the existing
unauthenticated-webhook boundary (ADR-0013) extends narrowly: message text
is captured, encrypted, and persisted only when dedup, chat-identity, and
known-topic-mapping all hold (§6) — every other update remains
metadata-only exactly as ADR-0013 established; (d) the new `universal-
telegram/v1/conversations*` REST routes are unauthenticated at the WP-REST
layer, authenticated instead by the bearer secret, mirroring the webhook
route's own precedent, with short-polling-only, no-store, same-origin
semantics (§4); (e) Telegram forum topics are created lazily (first accepted
message, not at start) and idempotently via a compare-and-set state column
(§5). Alternatives considered: a searchable hashed-token lookup (rejected —
a salted one-way hash cannot support direct lookup by design; the public-
ID-plus-secret split resolves this correctly); creating the topic at
conversation start (rejected — enables unlimited empty-topic creation via
repeated `start` calls); storing all inbound message text unconditionally
(rejected — unbounded expansion of ADR-0013's deliberately narrow scope). No
second ADR: nothing else in this milestone introduces an independent
architecture, security boundary, persistence model, or public contract.

## 13. Lean validation and evidence

Written with each WP, run once after WP6: `phpcs` (full tree — M04.1's CI
caught tree-wide sniffs a scoped local run missed), `phpstan`, `test-unit`,
`test-integration-wp-only` (conversations are WooCommerce-independent; the
`wc-present` leg is left to CI, not run locally). No `package-acceptance`
run locally (CI-only, per M04.1 precedent). Tests are written during
implementation and enumerated here; **none are run during planning**.

Enumerated scenarios: (1) secret never appears outside the single
start-response body — absence-asserted across every log/diagnostics/error
path; (2) malformed/oversized post-message body rejected before any DB
write; (3) `start`'s per-IP and site-wide rate limits both enforced, with
zero rows/jobs created on trip; (4) `post-message`/`poll`'s per-conversation
and site-wide limits enforced independently, `429` + `Retry-After`; (5)
concurrent/duplicate first-message paths yield exactly one topic and one
`destinations` row; (6) a message accepted while `pending` is stored
immediately, sent only once `created`, and times out to a visible `failed`
state rather than silent loss; (7) wrong-chat/unknown-topic/malformed-
thread/duplicate inbound updates are regression-asserted metadata-only,
matching today's `WebhookController` exactly; (8) a repeated `(bot_id,
update_id)` never creates a second message or transition; (9) simulated key
unavailability renders an opaque placeholder, never an exception or
plaintext; (10) retention cleanup nulls bodies at day 30 and deletes
conversation+messages+destination rows at day 90 from archival, idempotent
on rerun; (11) the full WP-only integration suite passes with no
WooCommerce present.

## 14. Version / DB

Version: `0.3.1 → 0.4.0` — minor bump; conversations are a genuine new
functional-capability class (matches the M0–M4 minor-bump precedent), not a
restructuring. `db_version`: `10 → 12`.

## 15. Documentation and closure

`docs/adr/0021-...md`, this plan (frozen), `docs/ARCHITECTURE.md` row 9 +
version-history line, `readme.txt` changelog,
`docs/closure/m05-conversation-backend-closure.md` per the milestone-closure
template, explicitly recording: M04.1 Product Owner acceptance still
pending (unaffected by M05), no real Telegram bot configured or contacted at
any point in M05, Vlad's independent acceptance not applicable to M05
(ADR-0011, M00–M09).

## 16. Real-bot requirement

Every Telegram API interaction in M05, including the new
`create_forum_topic()`, goes through `TelegramApiClient::call()`,
interceptable via WordPress' `pre_http_request` filter exactly like every
existing Telegram client test — no live bot token is required to implement
or validate M05, matching the established M01–M04 pattern. The earliest
point a **real** dev bot becomes necessary for genuinely meaningful
end-to-end acceptance is **M06** (the chat widget), when a human first
drives the flow through an actual browser UI against a live Telegram forum
group to see a real topic and a real reply — before that, per ADR-0011, no
manual acceptance session applies to M00–M09 regardless.
