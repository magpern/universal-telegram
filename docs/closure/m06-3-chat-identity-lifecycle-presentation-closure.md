# Milestone Closure Record — M06.3 Chat Identity, Lifecycle, and Presentation

- **Starting baseline SHA (`main` before this milestone):** `c6be5c25a5b3bc9af86524d8cbce9bc8e1182db1` (clean,
  `main == origin/main`; M06.2 corrective v2 closure commit, fully closed and Product-Owner-accepted per
  `docs/closure/m06-2-interactive-telegram-delivery-closure.md`).
- **Frozen plan/ADR commit SHA:** `c68c68b38d8006f34a525a5e82f4c73dadb51df4` (`docs: freeze M06.3 chat identity
  and presentation plan`), materializing `docs/plans/m06-3-chat-identity-lifecycle-presentation-plan-v1.md` (v1)
  and `docs/adr/0024-visitor-display-name-persistence-telegram-disclosure-boundary-and-chat-widget-presentation-preset-system.md`.
  Not revised during implementation — no superseding plan SHA.
- **Implementation commits** (branch `feature/m06-3-chat-identity-lifecycle-presentation`, merged to `main` via
  merge commit `7160ea4e7ed987c620a75207fdec32338fd096e1`):
  - `c68c68b` — docs: freeze M06.3 chat identity and presentation plan
  - `42a03e3` — feat(conversations): WP1 — display-name column, encryption, write-once repository support
  - `c8fadc4` — feat(conversations): WP2 — first-message display-name validation and reload-safe contract
  - `fa0f08a` — feat(conversations): WP3 — display-name topic title and first-message context header
  - `c201db1` — feat(settings): WP4 — chat widget preset, geometry, motion, and participant label fields
  - `7b1e43a` — feat(chat-widget): WP5 — classic/modern/minimal preset system and appearance tokens
  - `6719c40` — feat(chat-widget): WP6 — presentation rework and required visitor display-name flow
  - `ab2e32a` — feat(conversations): WP7 — 30-day inactivity auto-archival and destination-list hygiene
  - `c4bc355` — chore(m06-3): WP8 — version bump 0.6.2 -> 0.7.0 and doc updates
  - `5fee96b` — fix(m06-3): lean-gate repairs from PHPCS
  - `f89d34c` — fix(m06-3): lean-gate repairs from integration tests
  - `310ccf2` — fix(m06-3): lean-gate repairs from CI's full integration matrix
- **PR:** [magpern/universal-telegram#18](https://github.com/magpern/universal-telegram/pull/18), merged via
  merge commit `7160ea4e7ed987c620a75207fdec32338fd096e1` (all twelve commits preserved individually, not
  squashed, matching the M00–M06.2 merge-commit precedent).
- **Final `main` SHA:** `7160ea4e7ed987c620a75207fdec32338fd096e1` (verified `main == origin/main`, clean
  working tree, immediately after merge).
- **Closure commit SHA:** recorded by this document's own commit, immediately following.

## Technical status

**PASS.** Every requirement of the frozen plan and ADR-0024 is implemented and tested; local lean validation
(after two repair rounds, see Deviations) and the full GitHub Actions matrix on PR #18 are both green. Product
Owner acceptance is pending real-browser manual review — see below.

## Implementation scope

Adds required visitor display-name persistence, a Telegram disclosure boundary, static chat-widget
presentation presets, and 30-day inactivity auto-archival to the M06-core chat widget, without weakening any
existing conversation security/delivery guarantee (ADR-0021, ADR-0022, ADR-0023 all remain unmodified except
the two documented amendments):

- **WP1 (`src/Persistence/Migrator.php`, `src/Conversations/Conversation.php`,
  `src/Conversations/ConversationRepository.php`):** `Migrator::step_15_add_conversation_display_name_column()`,
  `db_version` 14 → 15 — one additive, re-runnable, nullable `display_name_ciphertext MEDIUMBLOB` column on
  `universal_telegram_conversations`. `ConversationRepository` gains `store_display_name()` (write-once,
  encrypted via the existing `CredentialVault` with a conversation-scoped additional-authenticated-data
  context distinct from message-body encryption), `decrypt_display_name()`, `inactive_open_conversations()`,
  and `destination_ids_for_bot()`. `ConversationRepository` now requires a `CredentialVault` dependency,
  updated at its sole composition-root call site and every test construction site.
- **WP2 (`src/Conversations/ConversationDisplay.php`, `src/Conversations/Rest/ConversationsController.php`):**
  `ConversationDisplay::bounded_utf8()`/`is_valid_display_name()` — the one shared UTF-8-safe helper (built on
  `mb_strlen()`/`mb_substr()`, the pattern already established in this exact controller) used for both the
  1–80-char name bound and topic-title truncation. `handle_post_message()` requires a valid `display_name`
  whenever no name is stored yet; an invalid/missing name is rejected before any message is persisted or
  routed. The message insert and the encrypted name write both commit before the existing status-transition/
  topic-dispatch/outbound calls, with a compensating message delete if name storage fails. `start`/`poll`
  responses gain `display_name_required: bool` (never the name).
- **WP3 (`src/Conversations/ConversationDisplay.php`, `src/Conversations/TopicCreationHandler.php`,
  `src/Conversations/MessageRepository.php`, `src/Conversations/ConversationOutboundHandler.php`):**
  `ConversationDisplay::topic_title()` replaces the pre-M06.3 `'Conversation ' . $uuid` literal with a
  Unicode-safe truncated name plus short reference (falls back to the literal when no name is stored) for both
  the `create_forum_topic()` call and the destination row's label. `MessageRepository::is_first_message()`
  identifies a conversation's first accepted message by insertion sequence; `ConversationOutboundHandler::
  create_and_route()` — the one shared path both the durable queue job and the in-process immediate-delivery
  attempt route every visitor message through — prepends the one-time context header there only.
- **WP4 (`src/Core/Configuration/Settings.php`, `src/Administration/Hub/SettingsPage.php`):** five new fields
  (`chat_widget_preset`, `chat_widget_geometry`, `chat_widget_motion_default`,
  `chat_widget_participant_label_visitor`/`_operator`) on the existing Hub Settings tab, generic safe defaults
  (modern/round/standard/You/Support) — no new tab, no new capability.
- **WP5 (`assets/css/chat-widget-classic.css`/`-modern.css`/`-minimal.css`,
  `src/ChatWidget/ChatWidgetAssets.php`):** three compiled, static presets replace the single M06-core
  stylesheet; preset selection is a pure function of stored settings, reflected in the existing cache-safe
  style-handle logic. No custom-CSS editor, external stylesheet URL, or runtime-generated stylesheet.
  Appearance tokens on the documented `.ut-chat-widget` selector contract; the visitor's own
  `prefers-reduced-motion` is always honoured in compiled CSS, never overridable by the admin motion default.
- **WP6 (`assets/js/chat-widget.js`):** a required-name step (disclosure sentence, client-side validation
  mirroring the server) shown until `display_name_required` (read from the start/poll response) is false; chat
  bubbles gain a participant-label + local-time meta line and date separators; `data-delivery` attributes plus
  status modifier classes for queued/pending/failed; `data-geometry`/`data-motion` attributes wired to the
  preset CSS. `sendMessage()` threads an optional `displayName` to the first message's POST body only.
- **WP7 (`src/Conversations/RetentionCleanupHandler.php`, `src/Administration/Telegram/BotManagementPage.php`):**
  the existing daily retention pass gains one prior step: open/waiting conversations inactive 30 days
  transition to `resolved` via the existing, frozen `ConversationStatus` map, then fall into the handler's own
  pre-existing `resolved → archived` + secret-revocation loop on the same pass — never a raw status write.
  `BotManagementPage::render_destinations()` splits conversation-created destinations into a separate,
  read-only "Conversation topics" section with no "Send test message" action.
- **WP8:** version bump only: `universal-telegram.php`, `readme.txt` (stable tag + changelog), `docs/ARCHITECTURE.md`
  (ChatWidget row + versioning history), `docs/master-plan.md` (M6.3 entry), `docs/adr/README.md` (reserved-number
  index, also backfilling the previously-undocumented ADR-0023 entry), `docs/testing/m06-chat-widget-manual-checklist.md`,
  `tests/package/run.sh`.

## ADR-0024

`docs/adr/0024-visitor-display-name-persistence-telegram-disclosure-boundary-and-chat-widget-presentation-preset-system.md`
— accepted in commit `c68c68b`. Amends ADR-0021 (adds `display_name_ciphertext`, extends the existing
encryption/retention pattern to it) and ADR-0022 (adds preset/appearance fields to the existing static JSON
config island) — neither is superseded.

## Version / database transition

- `UNIVERSAL_TELEGRAM_VERSION`: `0.6.2 → 0.7.0` — a **minor** bump: required visitor display-name persistence
  plus a new presentation/style-preset system together constitute a genuine new functional-capability class,
  matching the M06-core `0.4.0 → 0.5.0` precedent.
- `universal_telegram_db_version`: `14 → 15` (`Migrator` step 15; one nullable, encrypted column added to the
  existing conversations table — no new table, no change to any other existing table).
- Distributable package: `universal-telegram-0.7.0.zip`, built and verified via package acceptance (local and
  CI).
- **No Git tag, no GitHub Release, and no deployment action was created or performed for this milestone.**

## Privacy, disclosure, and forbidden-surface guarantees

- Display name classified `sensitive` under ADR-0009; encrypted at rest (`CredentialVault`, conversation-scoped
  AAD context distinct from message-body encryption); write-once (a later message can never overwrite a stored
  name); 1–80 UTF-8 characters, rejected with the same generic-400 posture as any other malformed request —
  never a distinguishing error.
- The widget's required-name step states, in its own UI copy, that the name is shared with the support team in
  Telegram — the only pre-send disclosure surface.
- The name is disclosed beyond the plugin's own database in exactly two places: the Telegram topic title
  (Unicode-safe truncated name + short, non-secret reference, bounded to Telegram's 128-char cap) and the
  first forwarded message's one-line context header — never any later message.
- Authenticated `start`/`poll` responses expose only `display_name_required: bool` — the stored name itself is
  never returned to the client by any route.
- Forbidden in every surface this milestone touches (verified by `ConversationDisplayTest` and the
  first-message-context-header tests): the bearer secret, the internal numeric conversation id, raw
  ciphertext, visitor IP, and idempotency keys.
- Pre-existing (pre-M06.3) conversations remain fully functional with `display_name_ciphertext = NULL`; an
  already-created topic is never renamed; no name is invented or backfilled for any existing row.

## Lifecycle and destination-list hygiene

- Automatic archival never issues a raw status write: an inactive (30-day, no visitor/operator message)
  open/waiting conversation transitions to `resolved` via the existing, frozen `ConversationStatus` compare-
  and-set, then the handler's own pre-existing `resolved → archived` + secret-revocation pass (unchanged)
  handles the rest on the same run. The eligibility query never selects an already-resolved or archived row,
  so nothing already archived or deleted can be reopened or reprocessed. No new retention-day setting, no
  settings UI, no manual archive/delete control — reserved for M07.
- Chat-widget-created Telegram topic destinations no longer appear in the Bots tab's manually configured
  destination table or expose a "Send test message" action; they render separately, read-only, in a
  "Conversation topics" section.

## Local validation and GitHub Actions evidence

Local lean validation gate (changed-scope, run after all work packages, per the frozen plan's own §15/§C):

- PHPCS, scoped to every changed file: clean (0 errors/warnings) after one `phpcbf` repair round plus two
  manual fixes (see Deviations).
- PHPStan (full tree): `[OK] No errors`.
- Changed-scope integration suite (`bin/docker` Docker tooling, WordPress 7.1/PHP 8.3): 152 tests, 359
  assertions, all green. A subsequent full (unfiltered) local integration run at WordPress 6.9/PHP 8.1 — after
  the second repair round — confirmed 493 tests, 0 failures, 38 skipped (WooCommerce-dependent, expected with
  WooCommerce absent).
- Changed-scope unit suite: 61 tests, 138 assertions, all green.
- JS behavioural suite (full `tests/js/`): 46 tests, all green.
- ZIP build + package-acceptance script (WordPress 7.1/PHP 8.3, WooCommerce absent): **PASSED**, confirming
  `universal_telegram_db_version=15` and `display_name_ciphertext` present after activation.

GitHub Actions full matrix on PR #18 (final run, commit `310ccf2`): `build`, `integration-wc-present-current`,
`integration-wp-only-current`, `integration-wp-only-floor`, `js-behavioural`, `package-acceptance` (6.9/8.1,
7.1/8.3, 7.1/8.3/WC 11.0.1), `phpcs`, `static-analysis`, `unit` (8.1, 8.3, 8.4) — all **pass**, on both the
`push` and `pull_request` workflow triggers.

## Deviations from the frozen plan

- **A real defect caught by the lean gate, not by planning.** An earlier edit to `src/ChatWidget/
  ChatWidgetAssets.php` accidentally dropped its `namespace UniversalTelegram\ChatWidget;` declaration,
  leaving the class in the global namespace. The scoped PHPCS pass caught this via
  `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound`; verified as newly introduced (not
  pre-existing) by comparing against the pre-branch file content, then fixed by restoring the namespace line.
  Commit `5fee96b`.
- **Two integration-test fixture issues, not production-code defects**, found by the changed-scope local run:
  a destination-list-hygiene test needed `chat_widget_enabled` turned on so the Bots tab wizard considers
  setup complete and renders the manual view the destination split lives in; a `display_name_required` poll
  test's second poll needed to avoid the shared controller's already-exhausted per-conversation poll rate
  limit. Commit `f89d34c`.
- **Five pre-existing Migrator schema tests from earlier milestones, found only by CI's full (unfiltered)
  integration matrix**, not the local changed-scope filter: each hardcoded "clean install reaches db_version
  N" for its own historical N (13 or 14) at the time it was written. `Migrator::maybe_migrate()` always
  advances to the current `target_version()` (now 15), so these assertions needed updating to 15 — the same
  update pattern M06.2 itself already applied to one of these same files when it bumped 13 → 14. No production
  behavior changed; only these five stale expected values, plus a second, deterministic fix (an injected
  future clock, using `RateLimiter`'s existing seam) for the `display_name_required` poll test above, whose
  first fix passed locally only by wall-clock luck. Commit `310ccf2`. A full, unfiltered local integration run
  was then performed to confirm no further breakage before re-pushing (493/493 green).
- No other deviation. The reload-safe `display_name_required` contract, the shared UTF-8 helper, the
  atomic-with-compensation name/message write ordering, the topic-title/context-header design, the
  cache-honest preset system, and the always-honoured reduced-motion guarantee all match the frozen plan and
  ADR-0024 exactly.

## Unresolved limitations

None known.

## Independent (Vlad) acceptance

Not applicable — per ADR-0011, milestones M00 through M09 do not require a separate Vlad acceptance session.

## Final status

**PASS.**

## Product Owner acceptance

**PENDING.** Not attempted as part of this technical-closure work — per this authorization's own instruction,
no live Telegram/browser acceptance was performed or fabricated. The manual acceptance checklist
(`docs/testing/m06-chat-widget-manual-checklist.md`, M06.3 section) remains to be performed against the real
browser widget on `dev.biopentra.eu`:

- required name and disclosure sentence before first send;
- named topic and one-time first-message header in a real Telegram group;
- preset appearance visible after a normal cache purge/expiry;
- reduced-motion, keyboard, screen-reader, and mobile checks;
- destination-list hygiene in the Bots tab;
- archival behaviour via a safe test fixture or dry-run evidence, not a real 30-day wait.

## M06.4, M06.5, and M07 status

**Unstarted.** Email collection, email-transcript delivery, and SMTP/mail integration remain a proposed
**M06.4** concern. Logged-in WooCommerce order-context sharing remains a proposed **M06.5** concern. Neither
was designed, planned, or implemented as part of this milestone. **M07** (operator workflow) remains
unstarted — nothing in this milestone touches `ConversationRepository::assign()`, adds operator-facing UI, or
introduces a manual conversation-management control.

## Deployment/configuration confirmation

No release, tag, or deployment action was taken. No Telegram bot, token, webhook secret, destination, or
group permission was created, configured, or changed. No live Telegram API call occurred anywhere in this
milestone's authorized work.
