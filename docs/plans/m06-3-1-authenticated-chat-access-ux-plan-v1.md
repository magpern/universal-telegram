# M06.3.1 — Authenticated Chat Access and UX Redesign — Plan v1

## Context

M06.3 (ADR-0024) shipped a required visitor-entered display-name step before an anonymous guest's
first chat message. The Product Owner rejected this onboarding model after M06.3's technical
closure (`main` @ `f4912296c580d9a84bfcdbe160c54bc20ea7ecdb`): an unauthenticated visitor could
enter any name, no continuity existed across browsers/sessions, and the conversation REST routes
had no cookie/nonce boundary. M06.3.1 replaces visitor-entered identity with server-derived
identity from an authenticated WordPress session, adds persistent database-enforced conversation
ownership, and redesigns the widget's UX around "sign in, then chat" rather than "enter your name,
then chat." Full rationale and the frozen decisions are recorded in ADR-0025.

## Scope

Implement exactly: authenticated-only chat access with a sign-in state for logged-out visitors;
server-derived display name (no visitor input); persistent `owner_user_id` on conversations;
explicit cookie+nonce CSRF enforcement on every conversation REST route, additive to the existing
bearer secret; a database-enforced one-active-conversation-per-owner-per-bot guarantee; a new
`GET /conversations/mine` resume route; removal of the name form, "Start chat", and
"End conversation" controls; invisible start-then-message conversation creation at Send time;
account-deletion ownership cleanup; a new `theme` default style preset; and scroll-position-aware
"New messages" behavior. Excluded: guest chat, email transcripts, WooCommerce order context,
operator UI, manual deletion, custom-CSS editor, external stylesheets, and any M06.4/M06.5/M07
work.

## REST / auth / nonce / cache contract

Every route (`POST /conversations`, `GET /conversations/mine`, `POST /conversations/{uuid}/messages`,
`GET /conversations/{uuid}`) requires, as the first statement of its own handler
(`ConversationsController::authenticate_session()`): `is_user_logged_in()` and
`wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')`. Failure is a uniform
`{ok:false, reason:'auth_required'}` 401 — no distinguishing detail between a missing session and
an invalid nonce. `messages`/`poll` additionally require the existing ADR-0021 bearer secret *and*
an ownership match (`conversation.owner_user_id === current_user_id`); any bearer/ownership failure
produces the existing identical, controlled 404. No route ever sets a CORS header (same-origin
only, unchanged from M05). The static config data island (`ChatWidgetAssets::print_config()`)
gains `loggedIn`, `nonce` (only when logged in), `loginUrl`, and `registerUrl` — still cache-safe
for the anonymous audience it is scoped to (identical among anonymous visitors of the same page);
personalization only ever occurs for an authenticated request, for which this stack has no
full-page-cache layer.

## Data model, migration, retention

`Migrator` step 16 (`db_version` 15 → 16) adds `owner_user_id BIGINT UNSIGNED NULL` and a
generated, `UNIQUE`-indexed `owner_active_slot` column
(`CONCAT(owner_user_id, ':', bot_id)` when `owner_user_id IS NOT NULL` and
`status IN ('new','open','waiting_for_visitor','waiting_for_operator')`, else `NULL`) to the
`conversations` table. `ConversationRepository::create()` is extended with trailing
`?int $owner_user_id`/`?string $display_name_plaintext` parameters, persisted atomically with the
row insert. `create_or_resume_owned()` is the sole entry point the authenticated start route uses:
one insert attempt; on an `owner_active_slot` duplicate-key collision, it looks up the existing
active row (`find_active_for_owner()`) and rotates its secret (`rotate_secret()`) rather than
retrying the insert or creating a second row. The existing 30-day inactivity → `resolved` →
`archived` sweep (`RetentionCleanupHandler`) is extended to also match `new` conversations (since
`new` now occupies the concurrency slot), routing a matched `new` row through the two individually
legal transitions `new → open → resolved` before falling into the existing `resolved → archived` +
secret-revocation pass, unchanged.

## Existing-conversation compatibility

Every M05–M06.3 conversation (`owner_user_id IS NULL`) remains fully functional at the database
layer — queryable, subject to the existing retention/archival sweeps — but becomes unreachable
through the REST API once authentication is mandatory, including to its original anonymous
visitor. Rows are never backfilled or auto-claimed by a later authenticated session. This is an
explicit, accepted consequence of removing guest chat, not a migration defect.

## Settings changes

`chat_widget_preset` gains a fourth enum value, `theme`, which becomes the new default (replacing
`modern`); `classic`/`modern`/`minimal` remain available unchanged. No Settings field ever existed
for visitor-entered names (it was pure client/REST state), so there is no name-related setting to
migrate — only the REST contract (`display_name_required`/`display_name`) and the widget's name
form are removed.

## Theme-preset and asset strategy

A new static `assets/css/chat-widget-theme.css` defines the same documented `.ut-chat-widget`
custom-property contract as the other three presets, but every color token is a generic
`var(--wp--preset--color--*, var(--wc-*, <neutral literal>))` fallback chain — no hard-coded
site-specific color, no runtime CSS generation, no custom-CSS input. `ChatWidgetAssets::preset()`'s
existing defended enum selection is extended to include `theme` and falls back to it (rather than
`modern`) for a corrupted stored value.

## Work packages

1. **WP1 — ownership schema/migration/repository** (`69902d8`): `owner_user_id`, generated
   `owner_active_slot` unique index, `create_or_resume_owned()`, `find_active_for_owner()`,
   `rotate_secret()`, `release_owner_conversations()`; `new`-inclusive inactivity sweep.
2. **WP2 — authenticated REST/nonce/ownership boundary** (`10be18b`): `authenticate_session()`,
   `auth_required()`, owner-match check in `authenticate()`, `GET /conversations/mine`,
   server-derived display name, removal of the `display_name_required`/`display_name` contract.
3. **WP3 — widget state machine and theme preset** (`ba5972e`): signed-out state, `AccountUrlResolver`,
   authenticated resume on open, invisible start-then-message at Send, removal of the name
   form/Start/End controls, `theme` preset CSS, scroll-position-aware "New messages" affordance.
4. **WP4 — account-deletion lifecycle wiring** (`ba22423`): `deleted_user` hook →
   `release_owner_conversations()`.
5. **WP5 — theme-default and scrolling** — delivered as part of WP3 (`ba5972e`); no separate commit,
   since the style-preset and scroll-affordance work are one cohesive client-side change.
6. **WP6 — destination-list hygiene regression coverage** (`217c316`): confirms
   `destination_ids_for_bot()` is unaffected by the `owner_user_id` addition.
7. **WP7 — ADR/version/db documentation, manual checklist, closure** (this commit and the closure
   commit that follows): ADR-0025, this plan document, version/db bump, manual acceptance checklist
   update.

## Automated scenarios covered

Access control (every route rejects a logged-out request); CSRF (missing/invalid nonce rejected
uniformly); ownership (a different authenticated user's bearer secret against another user's
conversation_uuid is rejected identically to an unknown one; a guessed UUID never succeeds);
concurrency (two simultaneous first-Sends for the same owner+bot converge on one row; a resolved
prior row frees the slot for a fresh one; the migration-level unique-index constraint itself);
logout (a stale cached secret is rejected at the next authenticated call once the session ends);
account deletion (ownership cleared, secret revoked); continuity (`GET /conversations/mine` resumes
and rotates); legacy-row unreachability; no-visitor-name-flow (server-derived identity only);
default theme preset (no hard-coded palette); cache-safety (identical config among anonymous
visitors); archive behaviour (the `new`-inclusive two-hop chain); WooCommerce absence
(`AccountUrlResolver` falls back to core login/registration); destination-list hygiene regression;
JS behavioural coverage for the signed-out/checking/idle/active state machine and the removed
name-form/Start/End controls.

## Validation gate

One lean local pass after all packages: PHPCS (changed PHP files), PHPStan, the affected unit and
integration suites (Conversations, ChatWidget, Persistence/migration, Administration, REST
security), the JS behavioural suite, and the package-acceptance check confirming `db_version` 16.
Repairs, if any, are limited to the direct defect found, followed by one rerun of the affected
check and one rerun of the full lean gate. GitHub Actions remains the independent, full-matrix
validation before merge.

## Manual browser acceptance checklist

Added to `docs/testing/m06-chat-widget-manual-checklist.md`: verify the logged-out shell shows only
sign-in/create-account links (no composer/history); verify an authenticated visitor's composer is
enabled immediately with no visible "Start chat"; verify a resumed conversation (second browser)
shows prior history; verify "Close" only hides the panel (conversation still resumable on reopen);
verify scrolling up during an incoming message shows "New messages" instead of forcing scroll;
verify the default `theme` preset visually matches the active site theme's colors, not a fixed
brand purple; verify keyboard/focus/reduced-motion behavior is unchanged from M06.3.

## ADR

ADR-0025 (new; amends/corrects ADR-0024, amends ADR-0021 and ADR-0022) — full text at
`docs/adr/0025-authenticated-chat-access-persistent-ownership-and-concurrency-safe-conversation-continuity.md`.
