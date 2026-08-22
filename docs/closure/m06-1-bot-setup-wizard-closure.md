# Milestone Closure Record — M06.1 Bot Setup Wizard

- **Starting baseline SHA (`main` before this milestone):** `b37cc13` (clean, `main == origin/main`;
  merge of PR #13, "feat(admin): add Telegram bot setup guidance" — the static panel this milestone
  replaces).
- **Frozen plan commit SHA:** `d273f95` (`docs(m06-1): freeze bot setup wizard plan`), materializing
  `docs/plans/m06-1-bot-setup-wizard-plan-v1.md` (v1). Not revised during implementation — no
  superseding plan SHA.
- **Implementation commits** (branch `feature/m06-1-bot-setup-wizard`, merged to `main` via merge
  commit `e3d329a`):
  - `d273f95` — docs(m06-1): freeze bot setup wizard plan
  - `9ce0170` — feat(admin): add BotSetupWizardState progress derivation (M06.1)
  - `2a141ca` — refactor(admin): extract TelegramFormFields shared form collaborator (M06.1)
  - `e8089c1` — feat(admin): add bot setup wizard renderer (M06.1)
  - `e5a34e1` — feat(admin): wire bot setup wizard entry point into Bots tab (M06.1)
  - `84ad1ba` — feat(admin): accessibility semantics and verification copy for setup wizard (M06.1)
  - `e3e9314` — docs(m06-1): bump version for bot setup wizard
  - `ab2977c` — fix(admin): lean-gate cleanup for bot setup wizard (M06.1)
- **PR:** [magpern/universal-telegram#14](https://github.com/magpern/universal-telegram/pull/14),
  merged via merge commit `e3d329a` (all eight commits preserved individually, not squashed, matching
  the M00–M04.1 merge-commit precedent).
- **Final `main` SHA:** `e3d329a` (verified `main == origin/main`, clean working tree, immediately
  after merge).
- **Closure commit SHA:** recorded by this document's own commit, immediately following.

## Technical status

**PASS.** Every requirement of the frozen plan is implemented and tested; local lean validation and
the full GitHub Actions matrix are both green on the PR. Product Owner acceptance (real Bots-tab
walkthrough) is pending — see below.

## Implementation scope

The static "Set up a Telegram bot" guidance panel (`BotManagementPage::render_bot_setup_guidance()`)
is replaced by a five-step, progress-driven setup wizard rendered inside the existing Bots tab
(`admin.php?page=universal-telegram&tab=bots&view=wizard&step=N`). No new Hub tab, WordPress
capability, database table, migration, REST route, or admin-post action was added.

New classes, all in the existing `Administration\Telegram` subdomain (ADR-0005, no new boundary):

- **`BotSetupWizardState`** — pure-read derivation of each step's completion from already-persisted
  state (`BotProfileRepository`/`ChatProfileResolver`/`ChatWidgetAvailability`/`DestinationRepository`).
  Steps 1, 4, 5 are verifiable and get a real "Complete"/"Not yet" state; steps 2 and 3 (creating the
  Telegram support group, adding the bot as its administrator) happen entirely inside Telegram and
  are permanently labelled as external manual prerequisites — never falsely marked complete, since
  WordPress has no way to observe or verify them.
- **`TelegramFormFields`** — a shared, page-agnostic presentation collaborator extracting the
  add-bot form, the create-destination form (optionally pre-filled), and the single-op button form
  (`register_webhook`, `send_test_message`, `requeue_message`, etc.) so both the manual `BotManagementPage`
  and the new wizard renderer reuse one implementation. Neither page is injected into the other.
- **`BotSetupWizardRenderer`** — renders the progress nav and the active step's content. Step 4
  offers the existing "Send test message" action once a destination exists, explicitly labelled as
  queued/non-synchronous evidence, not proof. Step 5 only *links* to the existing Settings tab to
  enable the chat widget — it never renders, duplicates, or cross-posts that form, since
  `SettingsPage`'s combined save handler owns both `chat_widget_enabled` and the unrelated
  `remove_data_on_uninstall` field in one payload.

`BotManagementPage::render_tab_content()` now selects between the wizard and the manual view: the
wizard is the default while setup is incomplete or `?view=wizard` is explicitly requested; the manual
view is the default once complete, with a persistent "Setup wizard" link back in. `view` is
allow-listed to `wizard`/absent; `step` is accepted only as an integer in the inclusive range 1–5 —
`0`, `6`, non-numeric, and missing values are all rejected outright (never clamped) and fall back to
`BotSetupWizardState::current_step()`.

The wizard is named to and scoped to `ChatProfileResolver::default_bot()` (the first configured bot)
only — the same bot `ChatWidgetAvailability` and M05's own start handler already treat as
authoritative. When more than one bot exists, a "Manage other bots →" link to the manual view is
shown rather than the additional bots being silently ignored; no bot picker or parallel wizard
instance was added.

WooCommerce independence: no step, and none of the three new classes, reads or depends on
WooCommerce state (ADR-0003) — the wizard behaves identically with or without WooCommerce active.

## ADR and version

**No new ADR.** Checked against `docs/governance.md`'s trigger (architecture, a security boundary, a
persistence model, a public contract, a milestone boundary, or a previously accepted decision) —
none apply. No new capability, table, migration, or settings field; database schema version
unchanged. Plugin version bumped `0.5.0 → 0.6.0` (`universal-telegram.php`, `CHANGELOG.md`).

## Lean validation and CI evidence

Local, pre-PR (lean validation gate per the M06.1 execution authorization — not the full historical
matrix):

- PHPCS, scoped to every changed PHP file: clean (0 errors after one repair round — docblock
  spacing, two missing `translators:` comments, and an unsanitized `$_GET['step']` read).
- PHPStan, scoped to the same files: `[OK] No errors`.
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`: 411 tests, 973
  assertions, 0 failures, 38 pre-existing WooCommerce-gated skips (unrelated to this milestone). One
  test assertion (`&tab=settings` vs. `esc_url()`'s `&#038;tab=settings` HTML-entity encoding) was
  corrected during the gate; the underlying renderer was already correct.
- `bin/check-doc-links.php docs README.md`: clean.

GitHub Actions full matrix on PR #14 (both triggered runs — push and PR-open — passed identically):
`build`, `integration-wc-present-current`, `integration-wp-only-current`, `integration-wp-only-floor`,
`js-behavioural`, `package-acceptance` (6.9/8.1, 7.1/8.3, 7.1/8.3/WC 11.0.1), `phpcs`,
`static-analysis`, `unit` (8.1, 8.3, 8.4) — all **pass**.

## Deviations from the frozen plan

- `BotSetupWizardState`'s constructor takes `ChatProfileResolver`, `ChatWidgetAvailability`, and
  `DestinationRepository` rather than the plan's listed `BotProfileRepository` — `ChatProfileResolver`
  already exposes `default_bot()`, so the separate repository dependency was unused and dropped.
  `DestinationRepository` was added (not in the original plan) to back the new
  `connected_destination()` method, needed so the wizard's step 4 "Send test message" action can
  target the connected destination's own row id — the eligibility rule itself stays owned by
  `ChatProfileResolver`; this only correlates its result back to the destination row. No behavior,
  test, or acceptance-criteria change resulted.
- No other deviation. All five wizard steps, the query-argument validation rule, the
  `ChatWidgetAvailability::is_available()` reuse, and the Settings-tab-link-only approach for step 5
  match the frozen plan exactly.

## Unresolved limitations

None known.

## Independent (Vlad) acceptance

Not applicable — per ADR-0011, milestones M00 through M09 do not require a separate Vlad acceptance
session; required quality evidence is the frozen plan, code review, mandatory automated validation,
and green CI, all present above.

## Final status

**PASS**, pending Product Owner acceptance below.

## Addendum — wizard/manual-view hotfix, new-user guided setup, and any-bot configuration

After the PR #14 merge above, a live walkthrough on the BioPentra site surfaced a defect and two
follow-on scope corrections, all delivered on branch `fix/m06-1-wizard-manual-view`
([PR #15](https://github.com/magpern/universal-telegram/pull/15), merged via merge commit
`8422134`).

### Defect: wizard and manual view rendered simultaneously

`BotManagementPage::render_tab_content()` unconditionally called `render_bot_list()` and
`render_create_bot_form()` regardless of which view (wizard or manual) it also rendered. For a
completed default bot this showed the wizard *and* the manual "Add a bot" form on the same page —
duplicating the sensitive token-entry form and muddying multi-bot management. Fixed by making the
branch exclusive: wizard-only when incomplete or `?view=wizard` is requested, manual-view-only
(with a persistent "Setup wizard" link) otherwise.

### Corrective addendum 1: new-user landing choice

The wizard's step 1 always jumped straight to the BotFather-walkthrough-then-token form, with no way
to distinguish "I already have a bot" from "walk me through creating one." Step 1 now shows a
choice — **Set up an existing bot** / **Create and set up a new bot** — for a brand-new setup (no
bot configured at all).

### Corrective addendum 2: wizard can configure any bot

A live test on BioPentra showed that once any bot existed, the landing choice could never resurface
and there was no way to run the checklist against a second bot. The wizard's entry point
("Setup wizard" link) now always presents a top-level choice — create a new bot, or configure an
existing one (a picker across every configured bot, skipped straight through when there is exactly
one) — regardless of whether the default bot is already complete. The bare Bots-tab URL still
auto-resumes an incomplete default bot's own checklist directly, unchanged, since that remains the
common single-bot case. `BotSetupWizardState`'s completion methods now take an explicit
`BotProfile` instead of always resolving the default bot internally, so any selected bot's own
progress can be derived — the chat widget itself stays wired to exactly one bot
(`ChatProfileResolver::default_bot()`); selecting or creating a bot through the wizard never
changes that, and step 5 requires only a registered webhook (not widget activation) for a
non-default bot. Creating a bot through the wizard's own form (a hidden `from_wizard` marker
honored by the existing `create_bot` redirect) returns into the wizard with that new bot selected;
it is simply added to the bot list, never auto-promoted to default.

### Merge conflict with M06.2

`fix/m06-1-wizard-manual-view` branched before M06.2 (PR #16, docs/closure/m06-2-interactive-telegram-delivery-closure.md)
merged to `main`, and both touched `BotManagementController.php`, `BotManagementPage.php`, and
`BotManagementControllerTest.php`. Resolved by merging `origin/main` into the branch: combined the
`from_wizard` redirect with M06.2's `test_message_result` redirect (both conditions, mutually
exclusive by `$op` in practice); kept this branch's exclusive wizard/manual-view branching while
adding M06.2's `render_test_message_notice()` call (M06.2 had built on the pre-hotfix code, since
this branch wasn't merged yet, and so reintroduced the unconditional `render_bot_list()` call this
hotfix removes); and reconciled the test file by dropping this branch's redundant
`controller_capturing_redirect()` helper in favor of M06.2's already-built-in `last_redirect_url`
capture on its updated `controller()` helper. Merge commit `970308c`.

### Implementation commits (branch `fix/m06-1-wizard-manual-view`)

- `70a2475` — fix(admin): render only the wizard or the manual bots view, never both (M06.1)
- `01d081b` — fix(test): target wizard step 1 for the duplicate-form regression check
- `820a23d` — fix(test): update package-acceptance Bots-tab smoke check for the wizard-only default view
- `32f5655` — chore: retrigger CI for M06.1 wizard/manual-view hotfix
- `b47727d` — feat(admin): add new-user landing choice to wizard step 1 (M06.1 addendum)
- `e19c17b` — feat(admin): wizard can create a new bot or configure any existing bot (M06.1 addendum)
- `970308c` — Merge remote-tracking branch 'origin/main' into fix/m06-1-wizard-manual-view

### Lean validation and CI evidence (PR #15)

- PHPCS, scoped to every changed file across all commits including the merge resolution: clean.
- PHPStan, scoped to the same files: `[OK] No errors`.
- Every directly affected integration test file run individually (multi-file `phpunit` CLI
  invocations were found to silently run only the first file — each file was verified separately
  as a result): `BotManagementPageTest` (15 tests), `BotSetupWizardRendererTest` (20 tests),
  `BotSetupWizardStateTest` (14 tests), `BotManagementControllerTest` (13 tests, including M06.2's
  own Test Message tests plus this branch's wizard-redirect tests) — 62 tests, 181 assertions,
  0 failures, after fixing one stale-object test-fixture bug (a `BotProfile` value object must be
  re-fetched after a repository mutation to observe it) and several HTML-entity-encoding mismatches
  in string assertions (`esc_html()` renders `'` as `&#039;`).
- GitHub Actions full matrix on PR #15 (merge commit `970308c`): `build`,
  `integration-wc-present-current`, `integration-wp-only-current`, `integration-wp-only-floor`,
  `js-behavioural`, `package-acceptance` (6.9/8.1, 7.1/8.3, 7.1/8.3/WC 11.0.1), `phpcs`,
  `static-analysis`, `unit` (8.1, 8.3, 8.4) — all **pass**. (GitHub Actions did not queue any
  check-suite at all on this PR for several pushes/close-reopens until the merge conflict against
  `main` was actually resolved — worth knowing if a future PR's checks similarly never appear.)

### Final `main` SHA (after PR #15)

`8422134` (verified `main == origin/main`, clean working tree, immediately after merge).

## Product Owner acceptance

**Pending.** Awaiting final sign-off following the live BioPentra walkthrough that already
identified and fed back the two corrective addenda above.

- Name:
- Date:
- Conditions attached:
