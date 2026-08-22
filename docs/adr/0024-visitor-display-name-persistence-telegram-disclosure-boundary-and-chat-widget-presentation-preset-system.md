# ADR-0024 — Visitor Display-Name Persistence, Telegram-Disclosure Boundary, and Chat Widget Presentation Preset System

## Status

Accepted (M06.3).

## Context

M06-core (ADR-0022) shipped an anonymous widget with no visitor-facing identity beyond the ADR-0021
bearer-secret credential; Telegram topics were titled `'Conversation ' . $uuid`, opaque to operators.
M06.3's charter requires a required visitor display name before first send, a name-derived topic title
and one-time first-message context header, and a maintainable, CSP-safe presentation system with
built-in presets — none of which any existing ADR covers.

## Decision

- A visitor-supplied display name (1–80 UTF-8 characters, trimmed, classified `sensitive` per
  ADR-0009) is collected once, client-side, before the conversation's first send, and transmitted as
  an optional `display_name` field on that first `POST /messages` call only — never a URL, header, or
  logged value, and never treated as or transmitted alongside the ADR-0021 bearer secret. The server
  persists it as `universal_telegram_conversations.display_name_ciphertext`, encrypted via the existing
  `CredentialVault::encrypt()` (ADR-0021's key-resolution tier, unchanged; no second vault), scoped by
  its own additional-authenticated-data context. If no encrypted name exists yet for a conversation,
  every `POST /messages` call for it requires a valid name; an invalid or missing name is rejected
  before any message is persisted or routed, using the identical generic-failure shape already used for
  other malformed requests. A later message on an already-named conversation never overwrites the
  stored name. Authenticated `start`/`poll` responses expose only a `display_name_required: bool` flag
  — computed from whether the encrypted name exists — never the name itself, so a page reload can
  correctly decide whether to show the required-name step again without ever disclosing the stored
  value back to the client.
- The Telegram forum-topic title and the first forwarded visitor message's context header are the
  *only* two places this name is disclosed beyond the plugin's own database: the topic title as a
  UTF-8-safe truncated name plus a `' · '`-separated 8-hex-character short reference (the UUID's own
  public prefix, never secret), bounded to Telegram's 128-character topic-name cap with the suffix's
  length reserved first; the first message as a one-line `[display_name · short_ref]` prefix, added
  once by the shared, already-hardened send path and never re-added to a later message in the same
  conversation. Neither the bearer secret, the internal numeric conversation id, raw ciphertext, nor
  visitor IP is ever included in either surface. The widget states this disclosure to the visitor,
  in-UI, before requiring the name — a fixed disclosure statement, not a new consent-management
  mechanism (ADR-0022's sessionStorage-only, no-cookie posture is unaffected).
- A conversation created before this decision has `display_name_ciphertext = NULL`; its already-created
  topic (if any) is never renamed, and topic creation falls back to the pre-existing
  `'Conversation ' . $uuid` title only when no name is stored at creation time. No name is invented or
  backfilled for an existing row.
- Presentation is a compiled, static, three-preset system (`classic`/`modern`/`minimal`; `modern`
  default), selected by a `Settings` enum field and reflected in `ChatWidgetAssets`' existing
  cache-safe style-handle selection — never an admin-supplied arbitrary stylesheet URL or inline
  custom-CSS textarea, preserving ADR-0022's static-config-island, no-per-visitor-data, CSP-safe
  posture exactly. Because the selected preset is a function of stored settings rather than the
  request, it remains identical for every anonymous visitor and therefore cache-safe; a global preset
  change becomes visible to an already-cached visitor only once the site's own page cache purges or
  naturally expires — this decision makes no claim of invalidating any third-party full-page cache.
  Geometry and a motion *default* are exposed as documented CSS custom properties on the widget's
  stable `.ut-chat-widget` root selector, forming the public backward-compatible contract the M06
  milestone charter requires; the visitor's own `prefers-reduced-motion` is always honoured in compiled
  CSS and is never overridable by the admin motion-default setting, which can only restrict — never
  restore — motion the visitor's OS has disabled.
- Automatic conversation archival (30 days with no visitor or operator message) is performed only by
  invoking the plugin's existing `ConversationRepository::transition()` compare-and-set, moving an
  eligible `open`/`waiting_for_visitor`/`waiting_for_operator` conversation to `resolved` — the only
  status the frozen `ConversationStatus` transition map allows those three statuses to reach — after
  which the plugin's existing `resolved → archived` retention-cleanup pass (already in
  `RetentionCleanupHandler::run()`) and its existing secret-revocation call handle the rest, unchanged.
  No raw status write is ever issued, and the eligibility query never selects an already-`resolved`,
  `archived`, or otherwise ineligible row, so an archived or deleted conversation can never be reopened
  or reprocessed by this job.

## Alternatives

- *Server-generate a name at start, deferring collection.* Rejected: the charter requires the name
  before the first conversation, and a server-generated placeholder would need inventing text with no
  source of truth.
- *A separate `POST /conversations/{uuid}/display-name` endpoint.* Rejected: duplicates
  `handle_post_message()`'s existing idempotency-replay protection for no benefit; folding it into the
  first message reuses an already-hardened path and keeps exactly one insertion point.
- *Return the stored display name on `start`/`poll` for the widget's own convenience.* Rejected: the
  server has no need to echo back data the client itself just sent, and doing so would create a second
  place (beyond the topic/header) where the name is disclosed, widening the surface this ADR otherwise
  keeps deliberately narrow.
- *An admin-supplied custom CSS textarea or external stylesheet URL for full customization.* Rejected
  outright by the milestone charter: both are explicitly excluded, and either would reintroduce exactly
  the CSP/cache-safety risk ADR-0022 was written to avoid.
- *Overridable `prefers-reduced-motion`.* Rejected: overriding a visitor's own OS-level accessibility
  preference is an accessibility regression the milestone's own acceptance criteria (keyboard/focus/
  contrast/reduced-motion/screen-reader) forecloses.
- *A new conversation status or raw `UPDATE` for archival.* Rejected: the frozen `ConversationStatus`
  transition map and the existing `resolved → archived` retention pass already express exactly the
  required lifecycle; adding a second archival mechanism would create two ways to reach the same state
  with no offsetting benefit, and a raw status write would bypass the map's own concurrency-safe
  compare-and-set guarantee.

## Consequences

M06.4 (deferred email transcript delivery) and M06.5 (deferred logged-in WooCommerce order context)
inherit this ADR's `sensitive` classification precedent for any further visitor-supplied PII they
introduce, without needing to re-litigate the classification question. M07 (operator workflow) gains a
materially more useful default topic title and first-message context with no new dependency on
`assign()` or a direct `resolved` call of its own. A future milestone wanting name *editing*, re-prompt,
or multi-tab continuity must revisit ADR-0022's session-scoped, non-recoverable state model — not
assumed solved here. Any later milestone extending automatic lifecycle transitions must continue
routing through `ConversationRepository::transition()` against the frozen map rather than introducing a
raw status write.

## Security and privacy impact

This is the plugin's first collection of visitor-supplied, self-identifying PII. Its protections are
deliberate: encryption at rest via the existing vault; a fail-closed length/format bound with a
non-distinguishing generic error; an explicit in-UI disclosure statement before collection; a hard
exclusion list (secret, internal id, ciphertext, IP) enforced at the one shared code path that ever
writes to Telegram; and a `display_name_required` boolean — never the name itself — as the only
client-visible signal of whether a name is already stored, closing off the response body as a
disclosure surface. Automatic archival reuses the existing, already-reviewed `resolved → archived` +
secret-revocation path rather than introducing a second lifecycle mechanism to reason about.

## Affected Documents/Milestones

ADR-0021 (amended: adds `display_name_ciphertext`, extends the existing encryption/retention pattern to
it); ADR-0022 (amended: preset/appearance fields join the existing static JSON config island); ADR-0009
(this decision's `sensitive` classification is its first concrete application to visitor-supplied
conversation data); M06.4/M06.5 (inherit this ADR's PII classification and disclosure precedent); M07
(inherits a materially more useful topic title/context header with no new dependency introduced).

## Compatibility/Migration Impact

Additive only: one nullable column (`Migrator` step 15, `db_version` 14 → 15), five new `Settings`
fields with safe defaults, three new static CSS files. No existing REST route response shape changes
for any caller outside the new optional `display_name` request field and the new
`display_name_required` response field; no existing topic, destination, or message row is altered
retroactively; the existing `resolved → archived` retention pass and secret-revocation call are reused
unmodified.
