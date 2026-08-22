# M06.1 — Bot Setup Wizard: Implementation Plan

**Context.** The Bots tab (`BotManagementPage::render_bot_setup_guidance()`) currently shows a static,
always-visible instructional panel above the add-bot form. It never reflects what the administrator
has actually done, offers no progress feedback, and gives no path back to a half-finished setup. This
milestone (M06.1) replaces that passive panel with a progress-driven, five-step wizard, without
touching the M06 chat-widget protocol, the M05 conversation model, or Telegram delivery. It is a
usability-only change layered over already-shipped mechanisms.

**Baseline.** `git fetch origin` + `git status --short --branch` confirm `main` is clean and matches
`origin/main` exactly. `HEAD` = `origin/main` = `b37cc13e5a5b1cfee2ddccbd8bd7649afb459b2d` (merge of
PR #13, "feat(admin): add Telegram bot setup guidance" — the very panel this milestone replaces). The
prior baseline `b37cc13` given in the task is current; it has not advanced.

## Repository findings (read-only, this session)

- `src/Administration/Telegram/BotManagementPage.php` — renders bot list, the static guidance panel
  (`render_bot_setup_guidance`, lines 194–231), add-bot form, dead-letter list. All markup is
  hand-rolled `echo`/`printf`, one `<div class="card">` per bot.
- `src/Administration/Telegram/BotManagementController.php` — single admin-post dispatcher, ops:
  `create_bot`, `replace_token`, `delete_bot`, `create_destination`, `delete_destination`,
  `register_webhook`, `rotate_webhook`, `retry_pending_webhook`, `rollback_webhook`,
  `test_connection`, `send_test_message`, `requeue_message`. Every op already does exactly what the
  five wizard steps need — there is no missing backend capability.
- `src/Telegram/Configuration/BotProfile.php` — read model exposing `status()`,
  `webhook_registration_state()` (`unregistered|registered|uncertain`), `has_pending_secret()`,
  `telegram_username()`/`telegram_bot_id()` (set only after a successful `getMe`, i.e. after
  `create_bot` or `test_connection` validates the token). Token/secret ciphertext accessors exist but
  per docs/adr/0012 A12 (restated in this class's own docblock) must never be rendered.
- `src/Telegram/Configuration/DestinationRepository.php` / `Destination.php` / `DestinationKind.php`
  — `Destination::enabled()`, `kind()` (only `SUPERGROUP` may carry `message_thread_id`),
  `message_thread_id()`.
- `src/Conversations/ChatProfileResolver.php` — `default_bot()` (first configured bot — the existing,
  only definition of "the default bot") and `conversation_chat_id( $bot_id )` (the bot's one *enabled*
  `SUPERGROUP` destination with no `message_thread_id`).
- `src/ChatWidget/ChatWidgetAvailability.php::is_available()` — `chat_widget_enabled` setting AND
  `default_bot()` present AND `conversation_chat_id()` resolvable. This is the plugin's own single
  authoritative predicate for "the widget is actually usable" and must be reused whole, not
  reconstructed field-by-field.
- `src/Administration/Hub/SettingsPage.php` — owns **both** `remove_data_on_uninstall` and
  `chat_widget_enabled` in one form/handler (`handle_request()` merges `$input` over
  `$this->settings->get()` and treats an absent checkbox key as explicit `false`). Posting a partial
  payload from anywhere else risks silently flipping `remove_data_on_uninstall` off. This form must
  never be duplicated or cross-posted to.
- `src/Administration/Hub/HubPage.php` + `TabRegistry`/`Tab` — one top-level menu, tabs resolved by
  `$_GET['tab']`, each tab renders its own content inside a shared `.wrap`; tab nav is real `<a href>`
  links, already always visible and keyboard-native — this is how an administrator gets from Settings
  back to Bots with zero new code.
- `docs/governance.md` — ADR required only when a change touches architecture, a security boundary, a
  persistence model, a public contract, a milestone boundary, or a previously accepted decision.
  `docs/adr/0005` fixes the module boundaries; this wizard adds no file outside the existing
  `Administration\Telegram` subdomain and no table/migration/option/capability.
- `docs/adr/0003` — WooCommerce is optional and orthogonal to Telegram/bot/chat-widget setup.
- CI scripts: `bin/docker/phpcs.sh`, `bin/docker/phpstan.sh`, `bin/docker/test-unit.sh`,
  `bin/docker/test-integration-wp-only.sh`, `bin/docker/test-integration-wc-present.sh`,
  `bin/docker/test-js.sh`. Plugin header version: `0.5.0` (`universal-telegram.php`). No DB schema
  version constant is touched.
- Tests live at `tests/integration/Administration/Telegram/{BotManagementPageTest,
  BotManagementControllerTest}.php`.

## ADR determination (ADR-0005 / governance.md threshold)

**No new ADR required.** This change adds no module boundary, no table/migration/option/capability,
no new admin-post action beyond calling *existing* ops, and changes no public contract or previously
accepted decision (ADR-0012 A12's never-render-a-token rule is reinforced, not touched). It is a pure
presentation-layer reorganization of already-shipped operations, stated explicitly here for the
architect to confirm rather than assumed.

## Design

### Entry point / navigation

No new Hub tab. The wizard lives inside the existing Bots tab (`TAB_ID = 'bots'`), toggled by a
`view`/`step` query arg read the same way `HubPage::resolve_tab_id()` reads `tab`:
`admin.php?page=universal-telegram&tab=bots&view=wizard&step=N` vs. the default `...&tab=bots`
(manual view, unchanged).

- **Default view:** if the default bot (`ChatProfileResolver::default_bot()`) is absent, or the
  wizard is not yet complete, `render_tab_content()` renders the wizard by default; once complete, the
  manual view is the default, reachable wizard via a persistent "Setup wizard" link.
- **Revisiting any step:** the progress nav's five steps are real `<a href>` links to
  `...&view=wizard&step=N`, each reachable regardless of completion state.
- **Query-argument validation:** `view` is allow-listed to exactly `wizard` or absent — any other
  value is treated as absent (manual view). `step` follows exactly this rule: *accept `step` only when
  it is an integer in the inclusive range 1–5; otherwise use `BotSetupWizardState::current_step()`.*
  There is no clamping — `step=0` and `step=6` are both rejected outright (never coerced to `1` or `5`)
  and fall back to the derived current step, identically to a non-numeric or missing value. This
  mirrors `HubPage::resolve_tab_id()`'s own existing "unrecognized input silently falls back, never an
  error" convention.
- The manual Bots view (bot list, actions, destinations, add-bot form, dead-letter list) is unchanged
  behavior; only `render_bot_setup_guidance()` is deleted and replaced by the wizard entry logic.
- **Named target bot (resolves the open question from the prior draft):** the wizard operates
  explicitly and only on `ChatProfileResolver::default_bot()` — the first configured bot — and names
  it on screen ("Setting up **{bot name}**"). If additional bots exist, the wizard shows a visible,
  non-buried link ("Manage other bots →" to the manual view) rather than ignoring them silently; it
  never attempts to run a second wizard instance or picker for a non-default bot.

### Progress / completion derivation (no new persistence)

New pure-read class `BotSetupWizardState` (`src/Administration/Telegram/BotSetupWizardState.php`),
computed on each render, never stored:

| Step | Completion state |
|---|---|
| 1. Create bot | **Verifiable — complete when** `default_bot() !== null` AND `telegram_username() !== null` (token validated via the existing `create_bot`/`test_connection` `getMe` call) |
| 2. Create support group | **Not WordPress-verifiable — always shown as an external manual prerequisite**, never marked complete. No Next-link state is stored or implied; advancing to step 3 only changes which step is *displayed*, not any recorded fact. |
| 3. Add bot as administrator | Same as step 2: **external manual prerequisite**, never marked complete by WordPress. |
| 4. Connect group | **Verifiable — complete when** `ChatProfileResolver::conversation_chat_id( default_bot()->id() ) !== null` (an enabled `SUPERGROUP` destination with no `message_thread_id`) |
| 5. Activate chat widget | **Verifiable — complete when** `ChatWidgetAvailability::is_available() === true` AND `default_bot()->webhook_registration_state() === 'registered'`. The class is *reused whole* via constructor injection — no independent reconstruction of its internal predicate. |

Only steps 1, 4, 5 render a completion badge ("Complete"/"Not yet"); steps 2 and 3 render a distinct,
neutral "Manual step — do this in Telegram" label that is never "Complete" and never implies WordPress
tracked it. `current_step()` = the first step in {1,4,5} that is incomplete, defaulting past 2/3 since
they carry no completion state to block on.

### Rendering

New `TelegramFormFields` (`src/Administration/Telegram/TelegramFormFields.php`) — a small, page-agnostic
presentation collaborator (not a controller, not injected with either page) holding the markup
currently duplicated in `BotManagementPage`: the add-bot form, the create-destination form (parameterized
so the wizard can pre-fill `label=Website Support`, `kind=supergroup`), the single-op button form
(`register_webhook`, `send_test_message`, etc.), and the nonce/hidden-field boilerplate they share.
Both `BotManagementPage` and the new wizard renderer take a `TelegramFormFields` instance; **neither
page is injected into the other** — this removes the circular/awkward dependency from the prior draft.

New `BotSetupWizardRenderer` (`src/Administration/Telegram/BotSetupWizardRenderer.php`), constructed
with `BotSetupWizardState` and `TelegramFormFields`. Renders:

- `<nav aria-label="Setup progress"><ol>` of five `<li>`, each an `<a>`; `aria-current="step"` on the
  active one; badge per the table above.
- Step 1: BotFather `/newbot` instructions, `https://core.telegram.org/bots#6-botfather` link, token
  warning notice, the shared add-bot form (via `TelegramFormFields`).
- Step 2: private supergroup + Topics/Tabs instructions, explicitly labeled "Manual step — do this in
  Telegram," no form, no completion claim.
- Step 3: add-bot-as-admin + "Manage topics" instructions (appears only after Topics is enabled; no
  unrelated permission requested), same manual-step labeling.
- Step 4: `telegram.me/chatIDrobot` link, destination field guidance (`Website Support` /
  `supergroup` / numeric `-100…` chat ID / blank topic ID), the shared create-destination form
  pre-filled with those defaults, **and, once a destination exists, the existing "Send test message"
  action** (via `TelegramFormFields`) with copy explaining delivery is queued and may take a short
  time to arrive — presented as recommended evidence, not synchronous proof. Step completion itself
  stays keyed to the destination row exactly as derived in `BotSetupWizardState`, not to test-message
  delivery.
- Step 5: the existing `register_webhook` button (via `TelegramFormFields`), then a plain link to the
  Settings tab (`admin.php?page=universal-telegram&tab=settings`) with copy: "Open Settings and enable
  the chat widget, then return to the Bots tab." **No form is embedded or cross-posted here** — the
  Settings tab's own existing form/handler is used entirely unchanged. Returning to the wizard (via the
  Hub's existing tab nav) recomputes step 5 as complete automatically since state is derived, not
  stored — no return-URL parameter or handler change is needed. Final verification copy: "Visit an
  uncached public page in a private/incognito window — page caching can retain an older version
  without the widget."
- Focus: each step section has a heading with `id` + `tabindex="-1"`; a "Skip to current step" link at
  the top provides an explicit keyboard path to move focus there — a normal full-page navigation does
  not itself move focus to the heading, so this link is the mechanism, not a backstop.
- Errors/status: reuse the plugin's existing admin-notice/redirect conventions already used by
  `BotManagementController`'s and `SettingsPage`'s redirect flows — no new messaging mechanism.
- Token safety: step 1's form is the literal shared `TelegramFormFields` add-bot form — never a token
  display, never a pre-filled value, never JS access.

### WooCommerce absence

No step, `BotSetupWizardState`, `TelegramFormFields`, or `BotSetupWizardRenderer` reads or depends on
WooCommerce state — confirmed no `Integrations\WooCommerce` reference exists in `Administration\Telegram`
today, and none is added. The wizard behaves identically with or without WooCommerce active; this is
inherited for free from ADR-0003's existing separation, stated rather than re-implemented as a dead
`class_exists`/`WC()` check.

## Work packages (frozen at seven; commit granularity below is final)

### WP0 — Plan-freeze commit
- Files: this plan, copied verbatim to `docs/plans/m06-1-bot-setup-wizard-plan-v1.md`.
- No ADR accompanies it (per the ADR determination above), so this is a standalone, code-free
  documentation commit per docs/governance.md's Freeze model — no implementation begins before it
  exists.
- Tests: none (docs-only); `composer check-doc-links` clean.
- Acceptance evidence: file present at that exact path, committed standalone with no code changes in
  the same commit.
- Commit: `docs(m06-1): freeze bot setup wizard plan`

### WP1 — `BotSetupWizardState`
- Files: new `src/Administration/Telegram/BotSetupWizardState.php`.
- Constructor deps: `BotProfileRepository`, `ChatProfileResolver`, `ChatWidgetAvailability`.
- Tests: `tests/integration/Administration/Telegram/BotSetupWizardStateTest.php` — no bot; bot created
  but token never validated; bot validated, no destination; destination wrong kind/has
  `message_thread_id`/disabled; destination correct but webhook `unregistered`/`uncertain`; webhook
  registered but `chat_widget_enabled` false (`ChatWidgetAvailability::is_available()` false); fully
  complete; multiple bots (asserts it always reads `default_bot()`, i.e. the first bot); steps 2/3
  never report a completion state under any input.
- Acceptance evidence: new test file green; assert (spy/mock) zero write-method calls anywhere in the
  class — it is read-only by construction.
- Commit: `feat(admin): add BotSetupWizardState progress derivation (M06.1)`

### WP2 — Extract `TelegramFormFields` shared presentation collaborator
- Files: new `src/Administration/Telegram/TelegramFormFields.php`; `BotManagementPage.php` updated to
  construct/use it for the add-bot form, create-destination form, and single-op button forms instead
  of its own private `render_*` methods (methods moved, not duplicated, not made `public` on the page
  itself); `Core/Plugin.php` composition-root wiring updated to construct `TelegramFormFields` once and
  inject it into `BotManagementPage` only — `BotSetupWizardRenderer` does not exist yet at this point
  and is wired in WP3, not here.
- Tests: existing `BotManagementPageTest.php` updated only where method visibility/location changed —
  no behavior change, so assertions on rendered output stay the same.
- Acceptance evidence: `BotManagementPageTest` still green; diff shows extraction into one shared
  class, with only `BotManagementPage` consuming it so far.
- Commit: `refactor(admin): extract TelegramFormFields shared form collaborator (M06.1)`

### WP3 — `BotSetupWizardRenderer` and its own composition-root wiring
- Files: new `src/Administration/Telegram/BotSetupWizardRenderer.php`, constructed with
  `BotSetupWizardState` (WP1) and the same `TelegramFormFields` instance (WP2) — both classes now
  exist, so `Core/Plugin.php` is updated here to construct `BotSetupWizardRenderer` and inject the
  already-existing `TelegramFormFields` instance into it (reusing the single instance built in WP2,
  not a second one); deletes `BotManagementPage::render_bot_setup_guidance()`.
- Tests: `tests/integration/Administration/Telegram/BotSetupWizardRendererTest.php` — correct step
  renders for a given state; `aria-current="step"` on the right nav item; steps 2/3 never render a
  "Complete" badge under any state; step 4 shows "Send test message" only once a destination exists,
  with queued/delay copy present; step 5 links to the Settings tab and contains no settings form
  markup, no `chat_widget_enabled` input field anywhere in its output; every step reachable via its
  link regardless of completion; named default-bot heading present; a second-bot scenario shows the
  "Manage other bots" link rather than omitting it; no token/ciphertext string ever appears in output
  (grep rendered HTML for absence of the fixture bot's plaintext token).
- Acceptance evidence: renderer test suite green.
- Commit: `feat(admin): add bot setup wizard renderer (M06.1)`

### WP4 — Wire entry point in `BotManagementPage::render_tab_content()`
- Files: `src/Administration/Telegram/BotManagementPage.php` — branch on the validated `view`/`step`
  query arguments (allow-list `view` to `wizard`/absent; accept `step` only when it is an integer in
  the inclusive range 1–5, otherwise use `BotSetupWizardState::current_step()` — no clamping, `0` and
  `6` are rejected outright, not coerced to `1`/`5`) to pick wizard vs. manual default view.
- Tests: extend `BotManagementPageTest.php` — no bot ⇒ wizard renders by default; complete setup ⇒
  manual view renders by default with a "Setup wizard" link present; `?view=wizard&step=3` always
  renders step 3 regardless of completion state; `?view=something_else` behaves identically to no
  `view` at all (manual/default view); `?view=wizard&step=0`, `?view=wizard&step=6`,
  `?view=wizard&step=abc`, and `?view=wizard` with no `step` all fall back to
  `current_step()` rather than erroring or rendering an invalid step.
- Acceptance evidence: test green, including the four invalid-input cases above.
- Commit: `feat(admin): wire bot setup wizard entry point into Bots tab (M06.1)`

### WP5 — Accessibility pass + verification copy
- Files: `BotSetupWizardRenderer.php` — skip-to-step-heading link, `tabindex="-1"` + id per step
  heading, non-color-only complete/incomplete/manual-step markers; final copy for BotFather warning,
  Topics/Tabs, "Manage topics" permission scoping, destination field guidance, and the
  cache/incognito verification instruction.
- Tests: extend `BotSetupWizardRendererTest.php` with markup-level assertions
  (`aria-labelledby`/`aria-current`/`tabindex`/skip-link target) — no new test file.
- Acceptance evidence: same suite green; manual keyboard-only walkthrough performed once during
  implementation and recorded as evidence in the eventual closure record (not fabricated here).
- Commit: `feat(admin): accessibility semantics and verification copy for setup wizard (M06.1)`

### WP6 — Version and changelog only
- Files: `CHANGELOG.md` (new `[0.6.0]` entry — minor bump, no schema change); `universal-telegram.php`
  `Version:` header bump to `0.6.0`.
- Tests: none (docs-only); `composer check-doc-links` run as part of the final validation gate.
- Acceptance evidence: `composer check-doc-links` clean.
- Commit: `docs(m06-1): bump version for bot setup wizard`

No closure record is part of implementation. Per docs/governance.md's milestone lifecycle (steps 6–9),
`docs/closure/m06-1-bot-setup-wizard-closure.md` is written **after** the final validation gate passes
and the PR merges — citing the actual frozen-plan commit SHA, the actual merge SHA, and real CI run
evidence, not evidence asserted in advance. It is out of scope for this plan's own work packages.

## Final validation gate (lean, changed-scope only)

Run only against files this milestone touches:
1. `bin/docker/phpcs.sh`.
2. `bin/docker/phpstan.sh`.
3. `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`, scoped to
   `tests/integration/Administration/Telegram/` if the harness supports a path filter, else the full
   integration suite once.
4. No `test-js.sh` — no JS is added.
5. No `test-integration-wc-present.sh` locally, per the WooCommerce-independence finding — GitHub
   Actions' full matrix (including the WC-present run) remains the independent, authoritative full
   validation; this local gate is deliberately narrower than CI, not a replacement for it.

## Out of scope

M06 chat-widget protocol changes, M05 conversation model changes, Telegram delivery architecture,
starting M07, any new Hub tab/capability/table/migration/settings field, any automatic BotFather/group
creation, any change to `WebhookRegistrationCoordinator`'s, `MessageDispatcher`'s, or `SettingsPage`'s
own logic.

## Self-check: no duplication or bypass of existing mechanisms

- Bot creation/validation: wizard step 1 uses the *existing* `create_bot` op via the shared
  `TelegramFormFields` form — no parallel validation path.
- Destination creation: wizard step 4 uses the *existing* `create_destination` op, pre-filled — no
  parallel Telegram/destination path.
- Test delivery: wizard step 4 uses the *existing* `send_test_message` op — no new send path.
- Webhook: wizard step 5 uses the *existing* `register_webhook` op — no new registration code.
- Widget: wizard step 5 *links to* the existing Settings tab/form — no new settings storage, no
  cross-post, no partial-payload risk to `remove_data_on_uninstall`.
- Token handling: no new rendering surface for ciphertext/plaintext; step 1's form is the same shared
  form used by the manual view.

---

**Confirmation:** no repository files, branches, commits, dependencies, bot configuration, or live
Telegram calls were made or performed during this planning session; only read-only `git
fetch`/`status`/`log`/`rev-parse` and file reads were executed. This draft is ready for one
architecture review.
