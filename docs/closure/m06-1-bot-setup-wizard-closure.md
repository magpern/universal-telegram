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

## Product Owner acceptance

**Pending.** Awaiting a real Bots-tab walkthrough of the five-step wizard (create bot → create
support group → add bot as administrator → connect group → activate chat widget) against a live
bot/group, including the manual-view toggle and multi-bot behavior, before this closure is finalized.

- Name:
- Date:
- Conditions attached:
