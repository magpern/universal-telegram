# M06.3 — Chat Identity, Lifecycle, and Presentation — Implementation Plan v1

## 0. Baseline

- Repository: `/opt/biopentra/dev/universal-telegram`.
- `origin/main` == `HEAD` == `c6be5c25a5b3bc9af86524d8cbce9bc8e1182db1`, clean working tree, branch
  `main` — the M06.2 corrective v2 closure commit, fully closed and Product-Owner-accepted
  (`docs/closure/m06-2-interactive-telegram-delivery-closure.md`). No M07 code, plan, or branch
  exists.
- `Migrator::target_version()` = `14`. `universal-telegram.php` version to be re-confirmed at freeze
  commit time (assumed `0.6.2` per the M06.2 closure record).
- `docs/adr/` ends at `0023`; the next unused ADR number is `0024`.

## 1. Scope

Three product surfaces ship together because they share one seam — the moment a conversation first
becomes visible to an operator in Telegram: **presentation** (compiled style presets, replacing the
minimal existing CSS), **visitor identity** (a required display name feeding the Telegram topic title
and a one-time first-message context header), and **lifecycle hygiene** (30-day inactivity
auto-archival via the existing domain transition, and destination-list hygiene in the Bots tab).

**Explicitly deferred, not started here**: email collection, email-transcript delivery, SMTP/mail
integration (proposed **M06.4**), logged-in WooCommerce order context (proposed **M06.5**), operator
conversation UI, manual hard-delete, custom-CSS editor, external stylesheet URLs, and all of **M07**.

## 2. Data model and migration

`Migrator::step_15_add_conversation_display_name_column()`, `db_version` 14 → 15, one additive,
re-runnable (information-schema-guarded) step:

- `universal_telegram_conversations.display_name_ciphertext MEDIUMBLOB NULL` — encrypted via the
  existing `CredentialVault::encrypt()` (same mechanism already used for message bodies, ADR-0021),
  with its own additional-authenticated-data context (`conversation:<uuid>:display_name`) preventing
  ciphertext relinking against the message-body ciphertext or another conversation.
- No other schema change. Presets/labels/geometry/motion are `Settings` fields, not conversation data.
  Destination-list hygiene (§7) uses a repository query, not a new column.

**Compatibility.** Pre-migration conversations have `display_name_ciphertext = NULL`. An already-created
topic is never renamed — `Conversation::display_name()` returning `null` means `TopicCreationHandler`
falls back to today's exact `'Conversation ' . $uuid` literal for any conversation whose topic is
created with no stored name. No name is invented or backfilled for any existing row.

## 3. Privacy, consent, and Telegram-disclosure boundary

Display name is classified **sensitive** under ADR-0009 (masked, not stripped, in any future audit/
diagnostics path — none currently touches conversation content). Validation: after `trim()`, 1–80 UTF-8
characters via the one shared helper (§4), rejected with the same generic-400/no-distinguishing-detail
posture `handle_start()` already uses for a malformed idempotency key.

The widget's required-name screen states, in its own UI copy, that the name is shared with the support
team in Telegram — a fixed disclosure statement, not a new consent gate (ADR-0022's sessionStorage-only
posture is unaffected). The name is never written to `sessionStorage`, a URL, a header, an audit value,
or a diagnostics value, and it is never treated as, or transmitted alongside, the bearer secret.

**Forbidden in every surface this milestone touches**: bearer secret, internal numeric conversation id,
raw ciphertext, visitor IP, idempotency keys. The context header and topic title carry only the
truncated display name and the short (8-hex-char) UUID reference.

## 4. Shared UTF-8 helper

One helper, `Conversations\ConversationDisplay::bounded_utf8( string $value, int $max_chars ): string`,
built on `mb_strlen()`/`mb_substr( ..., 'UTF-8' )` — the pattern already established in this exact file
(`ConversationsController`'s own message-text bound already uses `mb_strlen( $text, 'UTF-8' )`), so no
new extension dependency is introduced. Used for both: (a) the 1–80-char name-validation bound in
`handle_post_message()`, and (b) the topic-title truncation in §5, so the two call sites can never
drift independently.

## 5. Topic title and first-message context header

`ConversationDisplay::topic_title( ?string $display_name, string $uuid ): string` replaces
`TopicCreationHandler::try_once()`'s two literal `'Conversation ' . $conversation->conversation_uuid()`
call sites (the `create_forum_topic()` name argument and the paired `destinations->create()` label):

- `short_ref = substr( $uuid, 0, 8 )` (UUIDv4 hex prefix — already the public lookup key, not secret;
  plain `substr()` is correct here since a UUID is pure ASCII hex).
- If `display_name` is non-null: `bounded_utf8( $display_name, 128 - mb_strlen( ' · ' . $short_ref ) )
  . ' · ' . $short_ref` — bounded to Telegram's 128-char topic-name cap with the suffix's exact length
  reserved first.
- If `null`: today's `'Conversation ' . $uuid` (unchanged fallback for pre-M06.3-compatible rows).

The first forwarded visitor message — identified deterministically as the conversation's first
`conversation_messages` row by insertion sequence, not a request-scoped flag — gets a one-line prefix,
`[display_name · short_ref]\n`, prepended once by the shared send path
(`TopicCreationHandler`/`SendMessageHandler`'s `try_once()`), before the existing plaintext-to-ciphertext
boundary (ADR-0021's inbound-only capture boundary is untouched). Every later message forwards exactly
as today — no header, no re-derivation.

## 6. Visitor identity flow — reload-safe contract

- `display_name` is accepted **only** on `POST /messages` when the addressed conversation has no
  stored name yet (`display_name_ciphertext IS NULL`) — which, since an invalid/missing name now
  blocks persistence entirely (below), is always equivalent to "this is the first accepted message."
  There is no separate name endpoint.
- If no encrypted name exists yet, **every** `POST /messages` call for that conversation requires a
  valid name (1–80 UTF-8 chars via §4); an invalid or missing name is rejected with the existing
  generic `400` and **nothing is persisted or routed** — validated before `MessageRepository::create()`
  is ever called.
- On success: `MessageRepository::create()` runs, then `ConversationRepository::store_display_name()`
  (encrypt via `CredentialVault::encrypt()`, write-once — a no-op if already set) runs, both **before**
  the existing status-transition/topic-dispatch/outbound calls that already follow in
  `handle_post_message()`. If `store_display_name()` fails after the message insert succeeded, the
  message row is deleted (compensating rollback) and the method's existing `503` failure shape is
  returned — mirroring its existing `null === $message → 503` pattern; no new DB-transaction primitive
  is introduced, since none exists elsewhere in this codebase.
- **Reload safety**: authenticated `start` and `poll` responses gain `display_name_required: bool`
  (`display_name_ciphertext IS NULL`) — never the stored name itself. On reload, the widget reads this
  boolean (via its hydration poll) to decide whether to show the required-name step again, rather than
  relying on client-side state that a reload already lost.
- **Idempotency preserved**: `handle_post_message()`'s existing idempotency-key replay check already
  runs before any of this and short-circuits a replay with the original response — a replayed first
  message never re-validates or re-stores a name; `store_display_name()`'s own write-once guard is a
  second, independent safeguard.

## 7. Style system

Three static, build-time-compiled CSS files (`assets/css/chat-widget-classic.css`, `-modern.css`,
`-minimal.css`) replace the single current stylesheet; `modern` is the shipped default. Selection is a
new `chat_widget_preset` `Settings` field (enum, default `modern`), read by `ChatWidgetAssets::enqueue()`
to pick the style handle — the same fixed, cache-safe selection logic already used for the config
island (a function of `wp_options`, never the request), so full-page caching is unaffected.

Two further `Settings` fields — `chat_widget_geometry` (`round`/`square`, default `round`) and
`chat_widget_motion_default` (`standard`/`reduced`, default `standard`) — become CSS custom properties
(`--ut-chat-radius`, `--ut-chat-motion`) on the widget's stable root wrapper class (`.ut-chat-widget`),
documented as the selector contract a site owner may override from their own (child-)theme stylesheet.
**The visitor's own `prefers-reduced-motion: reduce` is always honoured via a `@media` guard that is
never gated behind the admin setting** — `chat_widget_motion_default` only changes the default level
applied when the visitor's OS expresses no preference; it can restrict motion, never restore motion the
visitor has disabled.

No custom-CSS editor, no external stylesheet URL, no runtime-generated stylesheet, no inline executable
configuration, no new frontend framework. **Cache honesty**: presets/config stay cache-safe because
they carry no visitor-specific data (identical for every anonymous visitor) — but changing the
admin-selected preset becomes visible to a cached visitor only after the site's own page-cache purges
or naturally expires. This plan makes no claim of invalidating any third-party full-page cache.

## 8. Visitor identity flow (widget)

Before the first explicit send, the widget shows a required-name step (inline form, not a modal stacked
on the chat panel) carrying the §3 disclosure sentence. Client-side validation mirrors the 1–80-char
bound (defense in depth only — the server is authoritative per §6). Error states: empty submission
(inline "required" message, no request sent), over-length (client `maxlength=80`, largely
unreachable), and a server `400` (the widget's existing generic transient-failure UI state — no new
error taxonomy). Participant labels: `chat_widget_participant_label_visitor` (default `"You"`) and
`chat_widget_participant_label_operator` (default `"Support"` — generic, never site-specific wording
in source, defaults, tests, docs, or assets), each 1–40 chars, rendered by `appendMessage()`.

## 9. Presentation rework (widget JS/CSS)

`buildWidget()`/`appendMessage()` gain: floating launcher, chat-bubble markup with a `data-sender`
attribute driving preset CSS, date-separator insertion (compare previous message's local calendar day),
local time rendering (`Intl.DateTimeFormat`, never a raw UTC string), and `setStatus()` extended for the
existing M06.2 queued/pending/failed vocabulary — no new wire states, only new visual treatment. Mobile
layout reuses the existing 600px bottom-sheet breakpoint. Keyboard/focus/contrast/reduced-motion/
screen-reader requirements extend the existing `role="dialog"`/focus-trap/`aria-live` foundation from
M06-core.

## 10. Lifecycle hygiene

**Auto-archival via the existing domain transition.** `ConversationStatus`'s frozen map only allows
`ARCHIVED` from `RESOLVED` (`OPEN`/`WAITING_FOR_VISITOR`/`WAITING_FOR_OPERATOR` → `RESOLVED`; `NEW` →
`OPEN` only). `RetentionCleanupHandler::run()` already performs `resolved → archived` +
`revoke_secret()` for every currently-`resolved` row on its existing daily pass, ahead of its two
existing retention-age sweeps. This plan adds exactly one step, inserted before that existing loop: a
new `ConversationRepository` query selects conversations with `status IN (OPEN, WAITING_FOR_VISITOR,
WAITING_FOR_OPERATOR)` whose `updated_at` (already bumped by every existing `transition()` call,
including the visitor-message and operator-webhook-reply transitions) is older than 30 days, and calls
the existing `transition( $id, $current_status, ConversationStatus::RESOLVED )` for each — a
structurally valid transition for all three eligible statuses. The pass then falls straight into the
handler's own pre-existing `resolved → archived` + `revoke_secret()` loop on the same run, reusing that
already-accepted code path unchanged rather than issuing any raw status update. `NEW` conversations
(no message ever accepted, no topic, nothing to clean up) are out of scope, since `NEW → RESOLVED` is
not a valid transition and there is no content burden to justify forcing one. The eligibility query
only ever matches the three open/waiting statuses — `RESOLVED` and `ARCHIVED` rows are never selected,
so an already-archived or already-deleted conversation can never be reopened, altered, or reprocessed
by this job. No new retention-day setting, no settings UI, no manual archive/delete control (reserved
for M07).

**Destination-list hygiene.** `BotManagementPage::render_destinations()` currently lists every
`Destination` row for a bot identically, each with a "Send test message" button — including
conversation-created ones. New `ConversationRepository::destination_ids_for_bot( int $bot_id ): array`
(`SELECT DISTINCT destination_id ... WHERE bot_id = ? AND destination_id IS NOT NULL`, no schema
change) lets `render_destinations()` split conversation-created rows out of the manual table and its
test-message action into a separate, clearly-labeled, read-only "Conversation topics" section.

## 11. Settings mapping

| Field | Default | Tab |
|---|---|---|
| `chat_widget_preset` | `modern` | Hub → Settings (existing tab) |
| `chat_widget_geometry` | `round` | Hub → Settings |
| `chat_widget_motion_default` | `standard` | Hub → Settings |
| `chat_widget_participant_label_visitor` | `You` | Hub → Settings |
| `chat_widget_participant_label_operator` | `Support` | Hub → Settings |

All five join `Settings::defaults()`/`sanitize()` and `SettingsPage`'s existing field-registration
pattern — no new admin tab, no new capability (ADR-0020's default followed without exception).

## 12. Version and database

`db_version` **14 → 15**. Plugin version **`0.6.2 → 0.7.0`** — a minor bump (new functional-capability
class, matching the M06-core `0.4.0 → 0.5.0` precedent). Re-verified against `universal-telegram.php`'s
header at freeze commit time.

## 13. ADR

One new ADR, **ADR-0024**, full repository-format text, materialized separately at
`docs/adr/0024-visitor-display-name-persistence-telegram-disclosure-boundary-and-chat-widget-presentation-preset-system.md`
— it introduces a new persistent PII-bearing column (persistence-model decision), a new public
styling/selector contract (public-contract decision), and a new visitor-facing disclosure boundary
(significant product behavior with no prior precedent), per `docs/governance.md`'s "when an ADR is
required" list. Amends ADR-0021 (adds `display_name_ciphertext`) and ADR-0022 (adds preset/appearance
fields to the existing JSON config island) rather than superseding either.

## 14. Work packages

**WP1 — Schema, encryption, repository, privacy classification.**
Files: `src/Persistence/Migrator.php` (`step_15_...()`, `verify_step_15()`), `src/Conversations/
Conversation.php` (`display_name()` accessor), `src/Conversations/ConversationRepository.php`
(`store_display_name()`, write-once, encrypted; `inactive_open_conversations( int $days ): array`;
`destination_ids_for_bot( int $bot_id ): array`).
Tests: `MigratorConversationDisplayNameSchemaTest` (clean-install, from-14, from-15 no-op),
`ConversationRepositoryTest` (write-once encrypted storage, inactive-query boundary, destination-id
query).
Commit: `feat(conversations): WP1 — display-name column, encryption, write-once repository support`.

**WP2 — First-message name validation and reload-safe contract.**
Files: `src/Conversations/Rest/ConversationsController.php` (`handle_post_message()` name
validation/storage sequencing; `handle_start()`/poll handler add `display_name_required`),
`src/Conversations/ConversationDisplay.php` (new — `bounded_utf8()`).
Tests: `ConversationsControllerTest` (valid/oversized/empty/missing name; no-persist-no-route on
invalid; `display_name_required` true/false in start/poll; idempotency replay does not re-store/
duplicate).
Commit: `feat(conversations): WP2 — first-message display-name validation and reload-safe contract`.

**WP3 — Topic title and first-message context header.**
Files: `src/Conversations/ConversationDisplay.php` (`topic_title()`), `src/Conversations/
TopicCreationHandler.php` (wire both call sites), shared send path (context-header prefix, first
message only).
Tests: `TopicCreationHandlerTest` (named vs. `NULL` fallback, Unicode truncation boundary, exact
128-char cap), a send-handler test for the one-time context-header prefix.
Commit: `feat(conversations): WP3 — display-name topic title and first-message context header`.

**WP4 — Settings fields and Hub Settings tab.**
Files: `src/Core/Configuration/Settings.php` (5 fields in `defaults()`/`sanitize()`),
`src/Administration/Hub/SettingsPage.php` (form fields).
Tests: `SettingsTest` (per-field sanitize/defaults), `SettingsPageTest` (render/save).
Commit: `feat(settings): WP4 — chat widget preset, geometry, motion, and participant label fields`.

**WP5 — Style preset system.**
Files: `assets/css/chat-widget-classic.css`, `-modern.css`, `-minimal.css` (new), `src/ChatWidget/
ChatWidgetAssets.php` (preset-aware handle selection).
Tests: `ChatWidgetAssetsTest` (preset selection identical across two anonymous requests, extending the
existing cache-safety test precedent).
Commit: `feat(chat-widget): WP5 — classic/modern/minimal preset system and appearance tokens`.

**WP6 — Widget presentation and required-name flow (JS).**
Files: `assets/js/chat-widget.js` (`buildWidget()`/`appendMessage()` rework; required-name step wired
into `ensureStarted()`'s first-send path, reading `display_name_required` from the hydration poll).
Tests: extend `chat-widget-ui.test.mjs`/`chat-widget-client.test.mjs`/`chat-widget-lifecycle.test.mjs`.
Commit: `feat(chat-widget): WP6 — presentation rework and required visitor display-name flow`.

**WP7 — Auto-archival and destination-list hygiene.**
Files: `src/Conversations/RetentionCleanupHandler.php` (inactivity → `resolved` step, reusing existing
`transition()`), `src/Administration/Telegram/BotManagementPage.php` (split destination listing).
Tests: `RetentionCleanupHandlerTest` (30-day boundary, no premature/late transition, archived/deleted
rows never re-touched), `BotManagementPageTest`/`ConversationRepositoryTest` (destination-id query,
split listing, absent test-message action on conversation-created rows).
Commit: `feat(conversations): WP7 — 30-day inactivity auto-archival and destination-list hygiene`.

**WP8 — Version, documentation, closure prerequisites.**
Files: `universal-telegram.php`, `readme.txt`, `docs/ARCHITECTURE.md`, `docs/master-plan.md`.
Commit: `chore(m06-3): WP8 — version bump 0.6.2 -> 0.7.0 and doc updates`.

## 15. Lean local validation gate (run once, after WP1–WP8)

Changed-scope only: PHPCS (changed files, `phpcbf` repair round), PHPStan, changed-scope unit +
integration tests (migration, conversation repository, REST, topic/send, lifecycle, destination-admin,
settings, widget), changed-scope JS behavioural/UI tests, ZIP build + package-acceptance (asserting
`db_version=15` and the new column present). GitHub Actions remains the independent full matrix — not
part of this local gate.

**Proves**: migration clean-install/upgrade-from-14/repeat-safe; name encrypted, write-once, excluded
from every forbidden surface; reload-safe `display_name_required`; no persistence/routing on
invalid/missing name; first-message-only topic/header behavior; Unicode/128-char boundary; existing
unnamed conversations unaffected; preset selection + always-honoured reduced-motion; cache-safe asset
output; archival only via valid existing transitions + revocation, never reopening archived/deleted
rows; conversation destinations absent from manual actions; WooCommerce-absent behavior unaffected;
package acceptance.

## 16. Manual acceptance (Product Owner, real browser — pending, not performed by this plan)

Required-name step and disclosure sentence before first send; named topic + one-time first-message
header in a real Telegram group; preset appearance visible after a normal cache purge/expiry (not
claimed instant); OS-level reduced-motion honoured regardless of admin motion-default; keyboard-only
and screen-reader completion of the name-then-send flow; 375px/414px mobile non-obstruction; Bots tab
shows conversation-created destinations separately with no test-message action; archival behavior
confirmed via a safe test fixture or dry-run evidence (not a real 30-day wait).
