# ADR-0025 — Authenticated Chat Access, Persistent Ownership, and Concurrency-Safe Conversation Continuity

## Status

Accepted (M06.3.1). Corrects and partially supersedes ADR-0024's visitor-entered display-name
mechanism, which the Product Owner rejected after M06.3's technical closure.

## Context

M06.3 (ADR-0024) shipped a required, visitor-entered display-name step before a guest's first
message, with a client-generated bearer secret as the sole conversation credential and no
WordPress authentication involved at all. The Product Owner rejected this onboarding model: an
unauthenticated visitor could invent any name, no continuity existed across browsers/sessions
beyond `sessionStorage`, and the three conversation REST routes had no cookie/nonce boundary of
their own. This ADR replaces visitor-entered identity with server-derived identity from an
authenticated WordPress session, adds persistent, database-enforced conversation ownership, and
defines the concurrency guarantee, CSRF boundary, and account-lifecycle handling that follow from
requiring authentication.

## Decision

- **Authentication is mandatory.** Every conversation REST route (`POST /conversations`,
  `GET /conversations/mine`, `POST /conversations/{uuid}/messages`, `GET /conversations/{uuid}`)
  requires a live WordPress cookie session; a logged-out request is rejected with a uniform
  `auth_required` 401 before any other processing. This is additive to, never a replacement of,
  the existing ADR-0021 per-conversation bearer secret: `messages`/`poll` require the cookie
  session, a valid nonce, the bearer secret, *and* an ownership match — all four.
- **CSRF is explicit and self-enforced.** Each route's own handler verifies
  `wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')` as its own first statement
  (`ConversationsController::authenticate_session()`), not relied upon implicitly via WordPress
  core's cookie-authentication wiring. Failure is the identical `auth_required` 401 regardless of
  whether the session or the nonce was the actual cause.
- **Identity is server-derived, never visitor-entered.** `handle_start()` resolves the display
  name from `wp_get_current_user()->display_name`, bounded and validated through the same
  `ConversationDisplay` helpers ADR-0024 defined, with a fixed generic fallback (`"Member"`) —
  never the username, email, or numeric user id. The name is persisted atomically with the
  conversation row at creation (`ConversationRepository::create()`'s existing single insert,
  extended with `owner_user_id`/`display_name_ciphertext` columns) rather than deferred to a
  first-message step; ADR-0024's `display_name_required`/client-supplied-`display_name` REST
  contract is removed entirely, along with the widget's name-entry form.
- **Persistent ownership.** A new nullable `owner_user_id` column records the authenticated
  WordPress user a conversation belongs to. `authenticate()`'s existing bearer-secret check gains
  a second requirement: `owner_user_id === get_current_user_id()`, failing into the same existing
  controlled 404 (no distinguishing detail) used for every other bearer-secret failure mode. A
  legacy row (`owner_user_id IS NULL`, every M05–M06.3 conversation) can never match any
  authenticated caller by construction, and is never backfilled or auto-claimed.
- **One active conversation per owner and bot, enforced at the database.** A generated, stored
  column `owner_active_slot` (`CONCAT(owner_user_id, ':', bot_id)` when `owner_user_id IS NOT NULL`
  and `status IN ('new','open','waiting_for_visitor','waiting_for_operator')`, else `NULL`) carries
  a `UNIQUE` index. `new` is included deliberately: a conversation is created in `new` and only
  reaches `open` once its Telegram topic is created, so excluding `new` would let two concurrent
  first-Send requests each insert a `new` row before either transitioned. `resolved`/`archived`
  are excluded, freeing the slot for exactly one fresh active conversation afterward.
  `create_or_resume_owned()` attempts exactly one insert; a duplicate-key collision on this index
  is the sole, explicit signal that another concurrent request already won the race for this
  `(owner_user_id, bot_id)` — the method never retries the insert and never creates a second row,
  instead looking up the existing active row and rotating its bearer secret for the caller.
- **Cross-session resume via a new, minimal route.** `GET /conversations/mine` (cookie+nonce only
  — no bearer secret exists yet for a browser resuming on a new tab or device) finds the caller's
  single active conversation for the resolved bot and mints a fresh secret via the same
  `rotate_secret()` primitive the duplicate-key path uses. This is the only channel that ever
  re-issues a secret to a browser that does not already hold one; no secret is ever stored
  server-side in recoverable form.
- **Conversation creation is invisible and Send-triggered.** Opening the widget panel creates
  nothing. The first actual Send performs `POST /conversations` (start) and
  `POST /conversations/{uuid}/messages` back to back, using the existing idempotency-key replay
  protection on each independently, so a retried Send after a network failure at either step is
  safe. There is no visible "Start chat" control, and no "End conversation" control — a
  conversation only ever ends server-side (resolved/archived), reflected to the client via the
  existing 404/`resolved`/`archived` poll paths.
- **Account deletion clears the association, not just the secret.** The `deleted_user` hook calls
  `ConversationRepository::release_owner_conversations()`, which revokes the bearer secret (the
  existing `revoke_secret()` path) **and** sets `owner_user_id = NULL` for every conversation that
  account owned, converting the rows into the same legacy-ownerless shape as an M05–M06.3
  conversation — read-only, unreachable via the authenticated widget, subject only to the existing
  retention-age sweeps. Logout requires no server action: every route re-checks the live cookie
  session per request.
- **Default presentation is theme-derived, not a fixed brand color.** A fourth static preset,
  `theme` (new default, replacing `modern`), uses generic WordPress/WooCommerce CSS custom-property
  fallback chains (`var(--wp--preset--color--primary, var(--wc-primary, <neutral literal>))`) —
  never a hard-coded site-specific color, matching the same compiled-CSS-only, no-custom-CSS-input
  posture ADR-0024 established for the other three presets, which remain available unchanged.
- **The visitor's manual scroll position is respected.** The widget's message log auto-scrolls to
  the newest message only when the visitor has not scrolled away from the bottom; otherwise a new
  message shows a "New messages" affordance instead of forcibly scrolling, satisfying the
  charter's accessibility requirement without regressing the existing keyboard/focus/ARIA/
  reduced-motion posture ADR-0024 already established.

## Alternatives

- *Weaken or replace the bearer secret with WordPress authentication alone.* Rejected: the task
  explicitly required preserving the existing bearer-secret protection; relying on the cookie
  session alone would remove the independent, non-enumerable per-conversation credential ADR-0021
  established and narrow the defense-in-depth this boundary now has.
- *Rely on WordPress core's implicit cookie-nonce wiring instead of an explicit check.* Rejected:
  an explicit, self-contained check in this class's own code behaves identically regardless of
  which other REST authentication handlers are registered, and keeps the failure shape (a uniform
  `auth_required` 401) under this boundary's own control rather than core's default `rest_forbidden`
  shape.
- *A time-based mutex or advisory lock for "one active conversation" instead of a database
  constraint.* Rejected: a lock is inherently racy under real concurrent requests and requires its
  own cleanup/expiry logic; a generated column plus a unique index is enforced by the database
  itself, atomically, with no additional moving part.
- *Exclude `new` from the active-slot definition, matching its exclusion from ADR-0024's
  inactivity sweep.* Rejected: unlike that sweep (which only needed to free rows for the retention
  job for the same 3 statuses used for RESOLVED as well), the concurrency guarantee this ADR needs
  applies from the moment of creation — a `new` row is exactly the race window two concurrent
  first-Sends would otherwise land in.
- *Backfill or auto-claim legacy ownerless conversations for a visitor who later signs in.*
  Rejected outright by the task's frozen scope: no automatic re-association was authorized, and
  doing so would require inferring identity from unauthenticated historical data with no reliable
  signal.
- *A WooCommerce-only account/login integration.* Rejected: WooCommerce must remain optional;
  `AccountUrlResolver` prefers WooCommerce's My Account page only when active and configured,
  falling back to WordPress core's own login/registration routes otherwise.

## Consequences

Guest/anonymous chat is permanently removed from this widget's supported surface; any future
milestone wanting an anonymous path must revisit this ADR, not assume ADR-0024's prior model still
applies. M06.4/M06.5 (deferred email transcripts, WooCommerce order context) can now assume a
reliable, server-derived identity and a persistent `owner_user_id` to key off, rather than a
session-scoped, self-reported name. M07 (operator workflow) inherits the same materially useful
topic title/context header ADR-0024 introduced, now always populated (no `display_name_required`
gap state to design around). Any future milestone adding another conversation-creating entry point
must route through `create_or_resume_owned()` (or an equivalent duplicate-key-safe path) rather
than calling `create()` directly with an `owner_user_id`, to preserve the one-active-conversation
guarantee.

## Security and privacy impact

Closes three gaps in the M06.3 model: an invented visitor identity is replaced with a verified
WordPress identity; a guessed-UUID/stale-session cross-user read is closed by the ownership check
even when a bearer secret is somehow known; and CSRF is now explicitly, self-consistently enforced
per route rather than assumed. The numeric WordPress user id, username, and email are never
serialized to any REST response, log, or Telegram-bound content — only the (bounded, validated)
display name and the existing non-secret short UUID reference. Account deletion actively severs
the ownership link rather than merely revoking a credential, so a deleted account's numeric id can
never be retained or coincidentally re-matched. The one-active-conversation database constraint
removes a class of concurrency bugs (duplicate rows, orphaned secrets) that a request-timing-based
approach could not fully close.

## Affected Documents/Milestones

ADR-0024 (amended/corrected: removes the visitor-entered `display_name`/`display_name_required`
REST contract and the required-name widget step; retains the encryption-at-rest pattern, the
topic-title/first-message-header disclosure boundary, and the static preset system architecture
unchanged in shape). ADR-0021 (amended: adds `owner_user_id` and the `owner_active_slot`
concurrency index to the conversations table; the bearer-secret protocol itself is unchanged).
ADR-0022 (amended: the config island gains `loggedIn`/`nonce`/`loginUrl`/`registerUrl`; the
cache-safety guarantee is clarified as scoped to anonymous visitors, who all still see identical
content). M06.4/M06.5 (inherit `owner_user_id` as a reliable identity key). M07 (inherits a
guest-chat-free, always-identified conversation model).

## Compatibility/Migration Impact

Additive only: two new columns (`Migrator` step 16, `db_version` 15 → 16 —
`owner_user_id BIGINT UNSIGNED NULL` and the generated, unique-indexed `owner_active_slot`), one
new `Settings` enum value (`chat_widget_preset` gains `theme` as the new default), one new static
CSS file, one new REST route (`GET /conversations/mine`). Every M05–M06.3 conversation row remains
functional at the database layer (queryable, subject to existing retention sweeps) but becomes
unreachable through the REST API once authentication is required — an explicit, accepted
consequence of removing guest chat, not a data-loss event. No existing column is renamed or
removed; `display_name_ciphertext`, `store_display_name()`, and `display_name_required()` remain in
place as the underlying encryption/write-once primitives, now populated at creation time rather
than via the removed first-message step.
