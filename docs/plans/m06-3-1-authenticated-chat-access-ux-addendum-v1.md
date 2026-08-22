# M06.3.1 Addendum v1 — Configurable Anonymous Chat Policy

## Context

The frozen M06.3.1 plan and its implementation (WP1–WP7, merging as PR #19) made chat
authenticated-only, unconditionally, correcting M06.3's rejected visitor-entered-name onboarding.
This addendum adds one narrowly-scoped, additive product correction on top of that already-built
foundation: whether chat requires authentication becomes a site-owner-configurable policy, default
OFF (i.e. authenticated-only remains the default, unchanged behavior). This does not reopen or
redesign any decision already implemented — the authenticated flow's guarantees (persistent
ownership, database-enforced concurrency, explicit CSRF, server-derived identity) are unconditional
and untouched. Only the previously-hard-coded rejection of anonymous access becomes optional.

## Decision

- New `Settings` field `chat_widget_allow_anonymous` (bool, default `false`), a Hub → Settings tab
  checkbox "Allow anonymous chat" — independent of `chat_widget_enabled` (chat can be enabled
  site-wide while remaining authenticated-only, which is the default combination).
- **Auth-branch selection is per-request** (`is_user_logged_in()`), never purely from the setting:
  - **Logged in** → always the existing M06.3.1 authenticated path
    (`create_or_resume_owned()`, cookie + explicit `wp_rest` nonce, server-derived identity, owner
    match, concurrency slot) — unconditionally, regardless of `chat_widget_allow_anonymous`. A
    logged-in visitor never falls back to the anonymous flow.
  - **Logged out, setting OFF** → unchanged M06.3.1 behavior: widget remains visible, shows
    sign-in (+ create-account when available) only; `handle_start()`/`handle_post_message()`/
    `handle_poll()` reject with the uniform `auth_required` 401, no distinguishing detail.
  - **Logged out, setting ON** → the anonymous path: the pre-M06.3.1 (M05/M06.2) client-generated
    bearer-secret protocol, unchanged — no cookie, no nonce (a public, cacheable page cannot safely
    carry one). `create()` is called with `owner_user_id = null`. Every existing rate-limit scope
    (`START_SITE`, `START_CLIENT_HOUR/DAY`, `AUTH_FAIL_CLIENT`, `POST_SITE/CONVERSATION`,
    `POLL_SITE/CONVERSATION`) applies unchanged — no new or weakened abuse control. An anonymous
    row's generated `owner_active_slot` is `NULL` (the column's own `CASE` already requires
    `owner_user_id IS NOT NULL`), so it never contends for or is bound by the one-active-conversation
    concurrency guarantee — that guarantee remains scoped to owned conversations only.
- **`messages`/`poll` authorization branches on the target conversation's own ownership**, not a
  single global rule: `authenticate()` resolves the conversation by UUID + bearer secret first (as
  today), then — `owner_user_id` not null → cookie + nonce + owner match, exactly as already
  implemented, any failure the existing controlled 404; `owner_user_id` null → requires
  `chat_widget_allow_anonymous` to currently be `true` (the already-verified bearer secret is then
  sufficient, no nonce) — if the setting is `false` (including a conversation created while it was
  previously `true`), the identical controlled 404 is returned, never a distinguishing signal that
  the conversation exists but anonymous access is now disabled.
- **`GET /conversations/mine` remains authenticated-only** unconditionally — it has no meaning for
  an anonymous bearer-secret credential (no cross-session resume for anonymous chat, matching the
  pre-M06.3.1 model exactly).
- **No visitor-entered name, ever, for either flow.** An anonymous conversation's stored display
  name is the fixed literal `"Visitor"` — no PII (never IP, user agent, email, numeric id,
  username, or the bearer secret). The existing `ConversationDisplay::topic_title()`/
  `first_message_context_header()` logic is reused unchanged and already appends `· <short_ref>`,
  producing `Visitor · <short_ref>` — no new formatting code. No name form, "Start chat," or
  "End conversation" control is restored for either flow.
- **No merge/claim on later login.** Unaffected by construction: `owner_user_id` is set once at
  creation and never reassigned by any existing code path — an anonymous conversation a visitor
  later authenticates for remains a permanently separate, ownerless row, architecturally identical
  to a legacy M05–M06.3 row.
- **Cache safety unchanged in kind.** `chat_widget_allow_anonymous`'s value is identical for every
  anonymous visitor of a given page (a pure function of stored settings, like the existing preset/
  geometry fields) — safe to add to the static config island as `anonymousChatAllowed`, carrying no
  personalized data itself. `loggedIn`/`nonce`/`loginUrl`/`registerUrl` remain exactly as already
  scoped: personalized only for an actually-authenticated request.
- **Widget state.** Logged-in behavior is unchanged. Logged-out behavior forks on
  `anonymousChatAllowed`: `false` → today's sign-in-only shell (unchanged); `true` → an enabled,
  empty composer immediately, reusing the same invisible start-then-message pattern already built
  (the pre-M06.3.1 client-generated-secret `ensureStarted()` shape, with no nonce header on these
  specific calls) — still no name form, no "Start chat," no "End conversation."

## No schema/database change

No new table, no new column, no `db_version` bump — `owner_user_id IS NULL` is already the exact
semantic this addendum reuses; only a `Settings` field and REST/widget branching logic are added.

## ADR/version impact

Amends ADR-0025 with a new decision subsection recording this as a configurable dual-access
boundary — additive only, reversing no existing ADR-0025 guarantee. Plugin version: a further minor
bump at freeze time (a genuine new configurable capability, not a patch), finalized when this
addendum's work package is committed.

## Work package

One additional work package (WP8) on top of the existing WP1–WP7: `Settings` field + Hub UI;
`ConversationsController` auth-branch restructuring (`handle_start()` login-state branch,
`authenticate()` ownership-conditional branch, anonymous-path rate-limit reuse verification);
widget JS `anonymousChatAllowed` state fork; `"Visitor"` fixed-identity constant; automated tests
covering the full authorization matrix (start logged-in/out × setting on/off; messages/poll owned
vs. anonymous vs. anonymous-now-disabled; `mine` always-authenticated; config-island cache-identity
across anonymous requests). Tests are added with this work package; the lean validation gate is not
re-run until all packages (including this one) are complete, per the existing Phase C process.
