# Milestone Closure Record — M06 Chat Widget (Core Slice)

- **Starting baseline SHA (`main` before this milestone):** `da877a050aa7274265ed4842a9b45ba4d7c9bc1d` (M05 closure commit, corrected; clean, `main == origin/main`; M05 merged via PR #8, merge `9ff8a8e`; a subsequent closure-record correction merged via PR #9, merge `da877a0`).
- **Frozen plan commit SHA:** `39d225e` (`docs: freeze M06 chat widget core plan`), materializing `docs/plans/m06-chat-widget-plan-v1.md` (v1). Not revised during implementation — no superseding plan SHA.
- **Implementation commits** (branch `feature/m06-chat-widget-core`, merged to `main` via merge commit `6996efc`):
  - `39d225e` — docs: freeze M06 chat widget core plan
  - `7a24d0e` — WP0: add idempotency-key support and db_version 13 migration for start and message endpoints (M05 corrective prerequisite)
  - `40a368c` — WP1: add availability gating and settings toggle
  - `d3960fd` — WP2: add cache-safe asset enqueue
  - `961040a` — WP3: add visitor state module
  - `51ff3a0` — WP4: add REST client with polling and backoff
  - `5bd8eee` — WP5: add widget UI and accessibility structure
  - `e80d84d` — WP6: wire lifecycle and conversation end handling
  - `eda9402` — WP7: close M06 core, record deferred charter scope (ADR-0022, version bump, docs)
  - `98ce43f` — fix: lean-gate fixups for M06 idempotency migration and boundary guard
  - `dd78838` — test(package): assert db_version 13 and M06 idempotency columns
- **PR:** [magpern/universal-telegram#10](https://github.com/magpern/universal-telegram/pull/10), merged via merge commit `6996efcd6d3c6069bbdfa2130b542035c0f6e8e2` (all eleven commits preserved individually, not squashed, matching the M00–M05 merge-commit precedent).
- **Closure commit SHA:** recorded once this document itself is committed and pushed (see step 7 of the authorized closure process); `main` immediately after closure will be verified `main == origin/main`, clean working tree.

## Technical status

**PASS.** Every acceptance point of the frozen plan (`docs/plans/m06-chat-widget-plan-v1.md`) and both ADRs (the ADR-0021 amendment and ADR-0022) is implemented, tested, and green in CI on both the PR and the merge commit. No known defect or unaccepted scope gap remains open within the core slice.

## Implementation scope

The M06 **core slice only** — open/close, first-explicit-send conversation creation, visitor text sending, short-poll operator-reply rendering, session state/accessibility/responsive presentation, and local end-conversation clearing — consuming M05's conversation REST contract exclusively:

- **WP0 (M05 corrective prerequisite):** `ConversationsController::handle_start()` now requires two headers — `Idempotency-Key` and `X-Universal-Telegram-Conversation-Secret` (client-generated, 64 lowercase hex characters) — never a body/URL/log value. An unseen key creates a conversation storing only `password_hash(secret)` plus the unique `start_idempotency_key`; a replay with the same key verifies the presented secret via `password_verify()` and returns the identical response; a wrong/missing secret on a known key returns the same generic `400` as any other malformed request. `POST /messages` accepts an optional per-message `Idempotency-Key`, scoped `(conversation_id, idempotency_key)`, replaying the original success response without re-running the status transition/topic-creation/outbound side effects. New `Migrator::step_13_add_conversation_idempotency_columns()`, `db_version` `12 → 13` — nullable, uniquely-indexed columns added to the two existing conversation tables only, no new table, guarded by an information-schema existence check so a from-12 re-run is a safe no-op (unlike a bare `ALTER TABLE ADD COLUMN`). Amends **ADR-0021**.
- **WP1:** `ChatWidgetAvailability` (`src/ChatWidget/ChatWidgetAvailability.php`) combines a new `chat_widget_enabled` boolean setting (`Core\Configuration\Settings`) with the existing `ChatProfileResolver::default_bot()`/`conversation_chat_id()` eligibility check — no duplicated logic. The toggle lives on the *existing* Hub Settings tab (`SettingsPage`); no new tab, no new capability.
- **WP2:** `ChatWidgetAssets` (`src/ChatWidget/ChatWidgetAssets.php`) enqueues `assets/js/chat-widget.js`/`assets/css/chat-widget.css` only when available, reusing `TrackerAssets`' nine-condition cache-safety gate. Configuration is a static, non-executable `<script type="application/json" id="ut-chat-widget-config">` data island (`wp_json_encode()` with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`), printed at `wp_footer` priority 5 (ahead of core's `wp_print_footer_scripts` at priority 20) — never `wp_add_inline_script()`. Content is a fixed array literal (`restUrl`, `namespace` only) — never per-visitor data.
- **WP3–WP6 (`assets/js/chat-widget.js`, vanilla, no build step/framework):**
  - **State:** `sessionStorage` only — `utChatPendingStart` (`{idempotencyKey, secret}`, written before dispatch) and `utChatConversation` (`{uuid, secret, startedAt}`). No message text or cursor ever persisted.
  - **Client:** request construction (headers only, never a URL/body secret), one automatic retry on transient failures reusing the identical idempotency key/secret (never a bare unkeyed retry), `AbortController`-based poll cancellation, a hydration poll at `since_id=0` on reload/re-entry, 3s visibility-gated polling with exponential backoff (3s→6s→12s→30s, reset on success), and an in-memory rendered-id set preventing duplicate rendering.
  - **UI:** `role="dialog"`/`aria-modal="true"`/`aria-labelledby` panel, `role="log"`/`aria-live="polite"` message list, focus entry on open and return on close, a focus trap, Escape-to-close, text-only rendering (`textContent` only, never `innerHTML`, for visitor- or Telegram-origin text), and a mobile bottom-sheet stylesheet (600px breakpoint, safe-area-aware, sticky close control, `prefers-reduced-motion`).
  - **Lifecycle:** conversation starts only on first explicit send (privacy-minimal); an "End conversation" control clears local `sessionStorage` and stops polling only — no M05 endpoint is called or invented, since none exists for client-initiated termination.
- **WP7:** **ADR-0022** (chat widget client-side state and cache-safe configuration), version bump `0.4.0 → 0.5.0`, `docs/ARCHITECTURE.md` (`ChatWidget` row, versioning history) and `docs/master-plan.md` (M06 entry) updated to record the core slice delivered while explicitly retaining the deferred charter items as pending — not implied complete.

## ADRs

- **ADR-0021 amendment** (`docs/adr/0021-...md`): the client-generated-secret start idempotency protocol and per-message idempotency, described above. Accepted in commit `7a24d0e`.
- **ADR-0022** (`docs/adr/0022-chat-widget-client-side-state-and-cache-safe-configuration.md`): client-side sessionStorage state, header-only secret transport, and the static JSON data-island configuration mechanism. Accepted in commit `eda9402`.

## Version / database transition

- `UNIVERSAL_TELEGRAM_VERSION`: `0.4.0 → 0.5.0` — a **minor** bump: the chat widget (`ChatWidget` boundary) is a genuine new functional-capability class, with its own frontend and a schema change.
- `universal_telegram_db_version`: `12 → 13` (`Migrator` step 13; two existing conversation tables altered in place — `start_idempotency_key` on `universal_telegram_conversations`, `idempotency_key` on `universal_telegram_conversation_messages` — no new table, no change to any other existing table).
- Distributable package: `universal-telegram-0.5.0.zip`, built and verified via package acceptance (local and CI).
- **No Git tag, no GitHub Release, and no deployment action was created or performed for this milestone.**

## Token, privacy, cache, and XSS guarantees

- **Start secret:** client-generated (never server-generated), transported only via the `X-Universal-Telegram-Conversation-Secret` request header, stored server-side only as `password_hash()` — the plaintext/raw secret is never written anywhere, encrypted or not. Message-post/poll requests use the pre-existing `Authorization: Bearer` header (ADR-0021, unchanged).
- **Client-side state:** `sessionStorage` only — no cookies, no `localStorage`, no cross-site identity. Bound to the browser tab's session; a second tab starts an independent conversation. A `404` from any authenticated route, or a `resolved`/`archived` status, clears local state and requires a new conversation.
- **Cache safety:** the widget's static configuration (REST base URL/namespace only) is the only server-rendered output tied to the widget, and its content is a fixed array literal identical for every anonymous visitor — no conversation `uuid`, `secret`, or idempotency key is ever server-rendered or reaches cached HTML (verified by `ChatWidgetAssetsTest::test_config_island_is_identical_across_two_anonymous_requests` and `test_config_island_contains_only_static_rest_url_and_namespace`).
- **XSS:** all message text (visitor- or Telegram-origin) renders via `textContent`/DOM text nodes only, never `innerHTML`; only the widget's own static, hardcoded chrome uses `innerHTML`.
- **No plaintext token, secret, or `Authorization`/`X-Universal-Telegram-Conversation-Secret` header value ever reaches a log, the Audit/Diagnostics surfaces, a URL, or a query string.**

## Lean validation and CI evidence

Local (lean validation gate, per this milestone's execution authorization):

- Unit suite (`bin/docker/test-unit.sh`) — **173 passed**, 0 failures (after removing `ChatWidget` from `StructuralBoundariesTest`'s not-yet-implemented boundary list, since this milestone's frozen plan now authorizes it — only `AI` remains unimplemented).
- Integration suite (WP-only, `bin/docker/test-integration-wp-only.sh --wp-version=6.9`) — **377 passed**, 38 skipped, 0 failures (rerun clean after fixing defects found by the first run: `Migrator` step 13's bare `ALTER TABLE ADD COLUMN` was not itself safely re-runnable, unlike every other step's `CREATE TABLE IF NOT EXISTS` — fixed with an information-schema existence-check guard; a `wp_json_encode()` slash-escaping test assertion; a `REST_REQUEST`-defining test in `ChatWidgetAssetsTest` that leaked the constant into `TrackerAssetsTest` — removed as redundant with that existing coverage of the identical shared gate).
- Node `node:test`/`vm` JS suite (`bin/docker/test-js.sh`) — **36 passed**, 0 failures (after fixing a cross-VM-realm `assert.deepEqual` prototype-identity issue in one state-module test).
- PHPCS (`bin/docker/phpcs.sh`, full tree) — **0 errors, 0 warnings** (auto-fixed via `phpcbf`; remaining Yoda-condition and doc-comment-style issues fixed by hand).
- PHPStan (`bin/docker/phpstan.sh`, full tree) — **no errors**.
- Plugin ZIP build (`bin/docker/build-zip.sh`) — `universal-telegram-0.5.0.zip` built successfully.
- Package-acceptance (`bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3`) — **PASSED** locally, confirming `universal_telegram_db_version=13` and both new idempotency columns present after activation (script's hardcoded `db_version=12` expectation updated to `13`, with new explicit column-existence checks added).

CI (GitHub Actions), full matrix, both the `pull_request` trigger and the `push` trigger:

- PR #10, final commit `dd78838`: every job green across both the `push` and `pull_request` workflow runs (`phpcs`, `static-analysis`, `unit` ×3 PHP versions, `integration-wp-only-floor`, `integration-wp-only-current`, `integration-wc-present-current`, `js-behavioural`, `build`, `package-acceptance` ×3 configurations).
- Merge commit `6996efc` on `main`: green.

CI caught no additional defect beyond what the local lean-gate passes above already found and fixed.

## Deviations from the frozen plan

None material. One environment-specific test-design adjustment, not a change to any frozen decision: `MigratorConversationIdempotencySchemaTest`'s original design chained a direct `$wpdb->insert()` immediately after this step's own in-test re-migration within the same request; this containerized MariaDB test service showed transient, non-deterministic "Unknown column" errors in that exact position (never reproduced via a direct SQL client, and the underlying schema was independently confirmed correct via `INFORMATION_SCHEMA` and `SHOW COLUMNS` throughout). The test was simplified to schema-level verification only (clean-install column presence, from-12 no-op), since the uniqueness constraint and nullable-upgrade-safety behaviors this section also aimed to prove are already reliably covered at the repository layer (`ConversationRepositoryTest`, `MessageRepositoryTest`), which never exhibited this flakiness across any run.

## Scope boundary confirmed

- **M05's token/idempotency contract is preserved and extended, not replaced:** `conversation_uuid` remains the sole database lookup key; the bearer secret is still verified only via `password_verify()` against a `password_hash()`-stored value; revocation is still exclusively by nulling `secret_hash`, never by the client.
- **No per-visitor state reaches cached HTML:** confirmed above and by automated test.
- **No direct browser-to-Telegram path was introduced:** the widget calls only the three existing M05 REST routes; no new event, rule, queue, or message-delivery path exists.
- **No M07 (operator workflow) or admin/assignment UI leaked in:** the only administrative surface added is a single boolean toggle on the pre-existing Settings tab.

## Requirements-to-evidence mapping

Not produced as a separate document for this milestone; the frozen plan's own §9 "Validation" enumerated scenario list maps directly to the test files listed above (`ConversationRepositoryTest`, `MessageRepositoryTest`, `ConversationsControllerTest`, `MigratorConversationIdempotencySchemaTest`, `VisitorTokenGeneratorTest`, `ChatWidgetAvailabilityTest`, `ChatWidgetAssetsTest`, `chat-widget-state.test.mjs`, `chat-widget-client.test.mjs`, `chat-widget-ui.test.mjs`, `chat-widget-lifecycle.test.mjs`).

## Product Owner acceptance

**M06-core Product Owner acceptance: PENDING.** Not attempted as part of this technical-closure work, per ADR-0011 (M00–M09 close on automated evidence alone; formal acceptance testing is deferred until M10). Two specific manual items remain, per the frozen plan (§7, §9) and `docs/testing/m06-chat-widget-manual-checklist.md`:

- **Manual mobile/accessibility review** (keyboard/focus/ARIA behavior and 375px/414px mobile viewport non-obstruction) — not automatable in this repository (no jsdom/browser test runner).
- **Real dev-bot/forum-enabled-supergroup smoke test** (widget send → Telegram topic/message → operator reply → poll surfaces it) — requires configuring a real bot, which this authorized work explicitly did not do.

**M04.1 Product Owner acceptance (manual menu/navigation review): still PENDING**, unaffected by and unrelated to this milestone — recorded here only for continuity, per the M04.1 and M05 closure records.

## Other confirmations

- **M07 has not started.** This closure covers M06's core slice only. No operator-workflow-boundary code, plan, or branch exists as of this record.
- **The deferred M06 charter scope (chat profiles/targeting, business hours, pre-chat form, visual controls, scoped custom CSS, live preview, page-builder embeds) remains explicitly pending and unscheduled** — recorded in `docs/master-plan.md`'s M06 entry, not implied complete by this closure.
- **No real Telegram bot was configured, and no live Telegram API call occurred anywhere in this milestone.**
- **No Git tag, no GitHub Release, and no deployment action occurred anywhere in this milestone.**
