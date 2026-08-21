# ADR-0021 — Conversation Persistence, Bearer-Secret Visitor Authentication, and Topic-Scoped Inbound Message Capture

## Status

Accepted

## Context

M05's charter (`docs/milestones/m05-conversation-backend.md`, master-plan.md
§13 "M5 — Conversation backend") requires a persistent, secure conversation
backend: a visitor-facing identity primitive (the plugin's first — M04's
visitor events are anonymous), Telegram forum-topic-scoped bidirectional
message routing, and a minimal public REST contract for the M06 chat widget
to call. ADR-0005 already names `Conversations`
(`UniversalTelegram\Conversations`) as a top-level boundary owned by M05, so
no boundary ADR is needed. ADR-0013 established the webhook route's
authenticity/replay/inbound-handling boundary as deliberately metadata-only,
explicitly noting that M05 "will need its own ADR before storing any message
content or introducing queued inbound processing" — this is that ADR. No
second ADR accompanies M05: nothing else in this milestone introduces an
independent architecture, security boundary, persistence model, or public
contract beyond what is decided here.

## Decision

- **Persistence.** Conversations and their messages persist in exactly two
  new tables, `universal_telegram_conversations` and
  `universal_telegram_conversation_messages` (`Migrator` steps 11–12,
  `target_version()` 10 → 12), following the plugin's existing raw-DDL,
  verify-via-information-schema migration pattern with no foreign-key
  constraints, consistent with every existing table. The full column/key
  design is frozen in `docs/plans/m05-conversation-backend-plan-v1.md` §8.
  The per-conversation Telegram destination reuses the pre-existing
  `universal_telegram_destinations` table (M01) unchanged — no new or
  altered schema for it.
- **Visitor identity and authentication.** Visitor identity is a two-part
  credential generated once at conversation start: a public, opaque
  `conversation_uuid` (UUIDv4) used as the sole database lookup key, and a
  private 256-bit bearer secret (`bin2hex(random_bytes(32))`) returned to the
  visitor exactly once, in the `start` response body, and never persisted in
  plaintext. Only `password_hash($secret, PASSWORD_DEFAULT)` is stored,
  verified only via `password_verify()`; the secret travels only via the
  `Authorization: Bearer` header, never in a URL, query string, log,
  diagnostics value, or error message. Every authentication failure mode —
  unknown `conversation_uuid`, malformed `conversation_uuid`, revoked
  secret, missing header, or wrong secret — produces the identical
  controlled `404`, `{"ok":false}`, so no response ever discloses whether a
  different conversation exists, is active, or is archived. M05 ships no
  in-place secret-rotation endpoint; a secret is only ever revoked, by
  nulling `secret_hash`, at the `resolved → archived` transition and again,
  permanently, at retention deletion. The full protocol is frozen in the
  plan's §3.
- **Public REST contract.** Three unauthenticated-at-the-WP-REST-layer
  routes under `universal-telegram/v1` — `POST /conversations` (start),
  `POST /conversations/{conversation_uuid}/messages` (post), and
  `GET /conversations/{conversation_uuid}?since_id=` (short-poll only, an
  ordinary keyset cursor) — authenticated instead by the bearer secret,
  mirroring the existing webhook route's own unauthenticated-at-REST,
  secret-verified precedent. Every response carries `Cache-Control:
  no-store, no-cache, must-revalidate` and `Pragma: no-cache`; no
  `Access-Control-Allow-Origin` header is ever emitted (same-origin only).
  `start` is protected by a per-IP/day bucket and a non-bypassable
  site-wide rolling cap, checked before any row or queue job is created;
  `post-message` and `poll` each have independent per-conversation and
  site-wide limits. The full contract, bounds, and rate-limit design are
  frozen in the plan's §4.
- **Topic-scoped inbound capture — the narrow extension to ADR-0013.** The
  existing unauthenticated, secret-token-verified webhook boundary
  (ADR-0013) is otherwise completely unchanged: bot lookup, header
  verification, body-size cap, JSON well-formedness, and `update_id`
  presence all run first, identically, with identical generic failure
  responses. Conversation-scoped content capture proceeds only when all
  three additional gates hold, checked against metadata only, in order:
  (1) `UpdateRepository::record()` confirms the `(bot_id, update_id)` pair
  is genuinely new (the existing dedup mechanism, reused as-is, no second
  dedup table); (2) the update's `chat_id` matches the configured
  conversation-support destination's `chat_id` for this bot; (3)
  `message_thread_id` matches an existing conversation's
  `telegram_topic_id` with `topic_creation_state='created'`. Only once all
  three pass is the plaintext message text — which Telegram delivers
  unencrypted in the webhook body — read out of the payload and
  immediately encrypted via the existing `CredentialVault::encrypt()`
  before being written to `conversation_messages.body_ciphertext`; it is
  never logged and never persisted anywhere else. If any gate fails,
  behavior is byte-for-byte identical to today's metadata-only path: dedup
  record (or no-op), `200 OK`, no conversation row, no status transition,
  no visitor-visible content. This is a narrow, additive extension of
  ADR-0013's own boundary, not a replacement of it — every one of ADR-0013's
  original decisions remains in force unmodified. The full routing and
  encryption design is frozen in the plan's §6 and §9.
- **Lazy, idempotent topic creation.** A Telegram forum topic is created
  only after a conversation's first accepted visitor message, never at
  `start`, via a single atomic compare-and-set on
  `conversations.topic_creation_state` (`none → pending`, gated on
  `$wpdb->rows_affected`) before any job is enqueued — so retries,
  duplicate first-message submissions, or concurrent requests can never
  produce two topics for one conversation. Full design in the plan's §5.
- **Fixed retention, no settings UI.** Message bodies are nulled 30 days
  after their conversation is `archived`; an archived conversation, its
  messages, and its destination row are permanently deleted 90 days after
  archival, in one daily Action Scheduler pass mirroring
  `Telegram\Outbound\RetentionCleanupHandler`'s existing registration
  pattern. Full design in the plan's §9.

## Alternatives

- *A searchable hashed-token lookup (hash the secret, look up by hash).*
  Rejected: a salted one-way hash cannot support direct lookup by design;
  the public-`conversation_uuid`-plus-secret split resolves this correctly
  without weakening the hash.
- *Create the Telegram topic at conversation start.* Rejected: enables
  unlimited empty-topic creation via repeated, abandoned `start` calls.
- *Store all inbound message text unconditionally, gated only by the
  existing ADR-0013 checks.* Rejected: unbounded expansion of ADR-0013's
  deliberately narrow, metadata-only scope; the three additional gates
  (dedup, chat identity, known-topic mapping) are required before any
  content capture.
- *A second, conversation-specific secret vault distinct from
  `CredentialVault`.* Rejected: the threat model and key-availability
  semantics for conversation message ciphertext are identical to the
  existing outbound-message case; a second vault would duplicate the
  three-tier key-resolution logic with no materially different
  requirement.
- *A dedicated conversation-scoped destination table, or a new uniqueness
  constraint on `universal_telegram_destinations`.* Rejected: the existing
  `UNIQUE(bot_id, chat_id, message_thread_id)` constraint on the
  pre-existing destinations table already guarantees at most one
  destination row per Telegram topic — exactly the guarantee a new table or
  constraint would provide, with none of the duplication.

## Consequences

M06 (the chat widget) becomes the first consumer of the REST contract
decided here; it does not modify or renegotiate the visitor-token security
model, only calls it. M07 (operator workflow) becomes the first, and only
milestone that ever calls `ConversationRepository::assign()` or sets the
`resolved` status transition — both are reserved, unused domain surface as
of M05. Any later milestone that needs to add message-content processing
against inbound Telegram updates should extend the three-gate check
established here rather than introducing a parallel routing or dedup
mechanism.

## Security and privacy impact

This ADR introduces the plugin's first persistent, message-content-bearing
security boundary. Its core protections are deliberate, not incidental: a
bearer secret that is never derivable from, or reducible to, a lookup key;
uniform-404 failure responses that foreclose an enumeration or
existence-disclosure oracle; a narrow, three-gate extension of an
already-hardened webhook boundary rather than a parallel or looser one;
authenticated encryption (AES-256-GCM via the existing `CredentialVault`)
for all persisted message content, with per-message additional authenticated
data preventing ciphertext relinking; and fixed, code-enforced retention
with no administrator-configurable window in this milestone.

## Affected Documents/Milestones

ADR-0013 (extended narrowly, not superseded — its own decisions remain in
force unmodified); ADR-0005 (already named the `Conversations` boundary this
ADR implements); M06 (chat widget — sole consumer of the REST contract
decided here); M07 (operator workflow — sole future user of `assign()` and
the `resolved` transition); M08 (administrative bot — if it ever needs to
read conversation state, it does so through this boundary's repositories,
not a new one).

## Compatibility/Migration Impact

Additive only: two new tables (`Migrator` steps 11–12, `db_version` 10 → 12),
no changes to any existing table's schema. `universal_telegram_destinations`
gains new rows through this milestone's own write path but no schema
change. No existing REST route, webhook behavior, or outbound pipeline
behavior changes for any caller outside the new conversation-scoped paths
this ADR introduces.
