# Milestone Closure Record — M04.1 Administration Settings Hub

- **Starting baseline SHA (`main` before this milestone):** `1311410` (clean, `main == origin/main`; includes the bootstrap activation hotfix `7583e8a`, merged via PR #6 at `01247a3`, and M04's own closure at `3c837cc`; M04's implementation was merged to `main` via merge commit `ced5adf`).
- **Frozen plan commit SHA:** `b38cb9b` (`docs: freeze M04.1 administration settings hub plan`), materializing `docs/plans/m04-1-administration-settings-hub-plan-v1.md` (v1). Not revised during implementation — no superseding plan SHA.
- **Implementation commits** (branch `feature/m04-1-administration-settings-hub`, merged to `main` via merge commit `e00fb1c`):
  - `b38cb9b` — docs: freeze M04.1 administration settings hub plan
  - `e4c8932` — WP0: ADR-0020 (administration hub navigation and legacy URL compatibility) and architecture cross-reference
  - `009446c` — WP1: add tab registry and administration hub shell
  - `38a6cf7` — WP2: migrate Diagnostics into the hub and add the Overview tab
  - `d418789` — WP3: migrate Bots, Events, Rules, Simulator, and Event History into hub tabs
  - `b79799c` — WP4: migrate Visitor Tracking into its own hub tab
  - `e90d5f7` — WP5: add the Settings tab and retarget plugin-row/diagnostics-banner links
  - `3e66b6d` — WP6: add legacy GET-only redirects, version bump to 0.3.1, and regression coverage
  - `20c4e95` — fix: readonly-property typing, PHPCS/PHPStan violations, and a leftover admin_menu wiring bug in the hub
  - `9ef7163` — fix: CI-caught defects in tests — stale DiagnosticsPage::render() call, empty catch blocks, array alignment
- **PR:** [magpern/universal-telegram#7](https://github.com/magpern/universal-telegram/pull/7), merged via merge commit `e00fb1c` (all ten commits preserved individually, not squashed, matching the M00–M04 merge-commit precedent).
- **Final `main` SHA:** `e00fb1c` (verified `main == origin/main`, clean working tree, immediately after merge).
- **Closure commit SHA:** recorded by this document's own commit, immediately following.

## Technical status

**PASS.** Every acceptance point of the frozen plan (`docs/plans/m04-1-administration-settings-hub-plan-v1.md`, §3–§12) is implemented, tested, and green in CI on both the PR and the merge commit. No known defect or unaccepted scope gap remains open.

## Implementation scope

The WordPress admin left menu now shows exactly one top-level entry, **Telegram Hub** (slug `universal-telegram`), gated on `CapabilityRegistrar::MANAGE`. Every screen that previously registered its own `add_menu_page()`/`add_submenu_page()` call is now a tab of one shared shell, reached via `admin.php?page=universal-telegram&tab=<id>`:

| # | Tab | id | Capability | Migrated from |
|---|---|---|---|---|
| 1 | Overview | `overview` (default) | `MANAGE` | New — static welcome + links, no new data source |
| 2 | Bots | `bots` | `MANAGE` | `BotManagementPage` (was `universal-telegram-bots`) |
| 3 | Events | `events` | `MANAGE_AUTOMATIONS` | `EventCatalogPage` (was `universal-telegram-events`) |
| 4 | Rules | `rules` | `MANAGE_AUTOMATIONS` | `RuleBuilderPage` (was `universal-telegram-rules`) |
| 5 | Simulator | `simulator` | `MANAGE_AUTOMATIONS` | `RuleSimulatorPage` (was `universal-telegram-rule-simulator`) |
| 6 | Event History | `event-history` | `MANAGE_AUTOMATIONS` | `EventHistoryPage` (was `universal-telegram-event-history`) |
| 7 | Visitor Tracking | `visitor-tracking` | `MANAGE` | `VisitorTrackingPage` (was `universal-telegram-visitor-tracking`) |
| 8 | Settings | `settings` | `MANAGE` | New — plugin-wide `Settings` fields that had no admin UI before (uninstall data removal, Telegram/event/dispatch/fatal-marker retention) |
| 9 | Diagnostics | `diagnostics` | `MANAGE` | `DiagnosticsPage` (was the top-level menu, `universal-telegram-diagnostics`) |

New `Administration\Hub` subdomain of the existing `Administration` boundary (ADR-0005, no new top-level boundary): `Tab` (immutable tab definition, `\Closure`-typed render callback), `TabRegistry` (insertion-ordered), `HubPage` (the shell — resolves `$_GET['tab']`, renders the shared `.wrap`/`<h1>`/nav-tab-wrapper, dispatches to the resolved tab), `OverviewPage`, `SettingsPage`, `LegacyUrlRedirector`. Every migrated page class's `render()` became `render_tab_content()` (own `.wrap`/`<h1>` stripped, now owned once by `HubPage`); every existing capability check, nonce, form field, and `admin-post.php` handler is unchanged — only relocated.

## Navigation map and legacy-routing behavior

Every one of the seven pre-existing admin page slugs (`universal-telegram-diagnostics`, `-bots`, `-events`, `-rules`, `-rule-simulator`, `-event-history`, `-visitor-tracking`) is preserved permanently as a hidden (`parent_slug = ''`), capability-gated compatibility entry point via `LegacyUrlRedirector`. Behavior, in order:

1. Capability is checked first, using the same capability the original page used — `wp_die()` 403 on failure, before any redirect target is computed or any content is disclosed.
2. Only a `GET` request is redirected — **302 (temporary), never 301** — to `admin.php?page=universal-telegram&tab=<id>`.
3. A non-`GET` request never redirects; it renders a plain, capability-safe "this page has moved" notice instead. No mutation route was ever tied to `admin.php?page=` — every `admin-post.php` handler (`BotManagementController`, `RuleBuilderRequestHandler`, `VisitorTrackingPage::handle_request()`, `SettingsPage::handle_request()`) is entirely untouched by this class and continued working unmodified throughout every work package.

`HubPage::resolve_tab_id()`: an absent or unregistered `tab=` value silently falls back to `overview` — no error, no partial content, resolved before any tab-specific data access. A known tab with insufficient capability produces WordPress's own unchanged `wp_die()` insufficient-permissions behavior — never a silent fallback to Overview, never partial content. The two cases are verified as distinct by `HubPageTest` and `LegacyUrlRedirectorTest`.

The plugin-row **Settings** link (`PluginActionLinks`) now points directly at `tab=settings`. The Diagnostics admin-notice banner (`DiagnosticsPage::render_admin_notice()`) now points at `tab=diagnostics`.

## ADR-0020

**Administration Hub — Single Menu Entry with URL-Driven Tabs**, accepted in commit `e4c8932`. Establishes the `Administration\Hub` tab-registry pattern and the legacy-URL compatibility contract (302-only, `GET`-only redirects, `admin-post.php` routes explicitly out of scope) as the Administration boundary's new *default* — narrowly scoped, not an unconditional requirement: a later milestone (M05 onward) may instead adopt a documented architectural exception through its own frozen plan and Master Architect review. No new WordPress capability introduced; `MANAGE`/`MANAGE_AUTOMATIONS` (ADR-0010) reused unchanged, including the known, accepted, unchanged asymmetry that the parent menu is gated on the broader `MANAGE` capability. No other ADR superseded.

## Lean validation and CI evidence

Local, pre-PR (lean validation gate, per M04.1 execution authorization — not the full historical matrix):

- `bin/docker/composer.sh install` — clean.
- `bin/docker/phpcs.sh` (all 17 changed/new source files, then re-run across the full `Administration` source and test trees after CI caught test-file violations) — clean, 0 errors/warnings.
- `bin/docker/phpstan.sh` (same file set) — clean, 0 errors. Caught two real defects before any push: `Tab::$render`'s readonly property needed an explicit `\Closure` type (PHP requires typed readonly properties — `callable` is not a valid property type), and a leftover `add_action('admin_menu', [$bot_management_page, 'register_menu'])` call in `Core\Plugin::init()` left over from before `BotManagementPage::register_menu()` was removed in WP3 (would have fataled on `admin_menu`).
- Focused unit test (`TabRegistryTest`) — 4 tests, 4 assertions, green.
- Focused integration tests (Hub: `TabRegistryTest`, `HubPageTest`, `OverviewPageTest`, `SettingsPageTest`, `LegacyUrlRedirectorTest`; migrated screens: `DiagnosticsPageTest`, `BotManagementPageTest`, `BotManagementControllerTest`, `EventHistoryPageTest`, `VisitorTrackingPageTest`, `PluginActionLinksTest`, `RuleBuilderRequestHandlerTest`, `SelfTestTest`), WordPress 6.9/PHP 8.1 — 55 tests, 120 assertions, green.
- Full WP-only integration suite (WordPress 6.9/PHP 8.1), run once locally after the render-rename touched many classes, to bound the risk of a stale call site CI would otherwise have to catch — 284 tests, 659 assertions, 38 skipped (WooCommerce-gated tests correctly self-skip), green.
- `bin/docker/build-zip.sh` — builds `universal-telegram-0.3.1.zip`.
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3` — **PASSED**, including new package-acceptance checks added this milestone: all nine hub tabs registered in the exact expected order, the hub shell renders the tab nav and the requested tab's content, and the legacy `universal-telegram-bots` slug redirects to `tab=bots`.

CI (GitHub Actions), full matrix, both the `pull_request` trigger on PR #7 and the `push` trigger on the merge commit:

- PR #7, commit `9ef7163` (final PR commit): all 13 jobs green across both the `push` and `pull_request` workflow runs (`phpcs`, `static-analysis`, `unit` ×3 PHP versions, `integration-wp-only-floor`, `integration-wp-only-current`, `integration-wc-present-current`, `js-behavioural`, `build`, `package-acceptance` ×3 configurations).
- Merge commit `e00fb1c` on `main`: all 13 jobs green (run `32502623478`).

CI caught three real defects this local pass had missed, all fixed before merge (commit `9ef7163`): a stale `DiagnosticsPage::render()` call in `SelfTestTest.php` (not one of the files directly touched by the Diagnostics migration, so outside the initial focused-test scope), two comment-only `catch` blocks flagged by PHPCS's empty-catch sniff, and misaligned array double-arrows in a test fixture.

Not run (explicitly out of lean-gate scope; no changed test or tool required it): the full multi-PHP-version unit matrix locally (CI covers it), and any JS behavioural re-run beyond what CI's unchanged `js-behavioural` job already covers — no JS tracker file changed in this diff.

## Deviations from the frozen plan

None material. Two implementation-detail corrections, neither touching a frozen contract, capability, persistence model, or milestone boundary:

- `Tab`'s `$render` property is typed `\Closure` (via `\Closure::fromCallable()` in the constructor) rather than an untyped `callable` property, since PHP requires readonly properties to carry an explicit type and does not accept `callable` as a property type at all. The plan's own `Tab` sketch used a `callable` constructor parameter type (still true here); only the internal storage type changed.
- `LegacyUrlRedirector::register()` calls `add_submenu_page( '', ... )` (empty string) rather than `add_submenu_page( null, ... )` as the plan's prose described, since the WordPress stub types PHPStan checks against declare `$parent_slug` as non-nullable `string`; an empty string is WordPress core's own equivalent for the same "hidden, URL-only page" behavior. No functional difference.

## Version and DB confirmation

- `UNIVERSAL_TELEGRAM_VERSION`: `0.3.0 → 0.3.1` — a **patch** bump, per the plan's own recommendation and Master Architect confirmation: this milestone introduces no new end-user functional capability and no persistence change, only a navigation restructuring.
- `universal_telegram_db_version`: unchanged at `10`. No new table, no new option beyond `Settings`' already-declared fields (the Settings tab exposes existing, already-sanitized fields only).
- Distributable package: `universal-telegram-0.3.1.zip`, built and verified via package acceptance.
- **No Git tag, no GitHub Release, and no deployment action was created or performed for this milestone.**

## Security and reliability confirmation

- No new WordPress capability. `MANAGE`/`MANAGE_AUTOMATIONS` (ADR-0010) reused unchanged; every migrated tab keeps its own original capability check, re-verified independently inside `HubPage::render()` before that tab's content ever runs — the same defense-in-depth posture every page already had, now centralized rather than duplicated per page.
- No new persisted data, no new database table, no new public/unauthenticated endpoint. All navigation stays within the existing capability-gated `wp-admin` surface.
- Legacy-URL redirects are `GET`-only and `302`; a non-`GET` request never redirects, verified by `LegacyUrlRedirectorTest::test_a_non_get_request_never_triggers_a_redirect`.
- No `admin-post.php` route, nonce, or form field name changed; every existing mutation handler continued working unmodified throughout every work package (verified by the unmodified `BotManagementControllerTest`, `RuleBuilderRequestHandlerTest`, and the `VisitorTrackingPageTest`/`SettingsPageTest` handler-denial tests).
- No event, rule, persistence, Telegram, WooCommerce, visitor-tracking, chat, or AI behavior changed anywhere in this diff (confirmed by the unmodified event/rule/visitor test suites passing unchanged in the full local integration run and in CI).

## Product Owner acceptance

**PENDING Vlad Stormhaven's menu/navigation review and sign-off.** Per the M04.1 execution authorization, manual Product Owner acceptance is performed by Vlad Stormhaven after merge and was not attempted on his behalf as part of this work. Status is left as `Pending` rather than presumed, per governance's requirement that the Implementation Agent cannot self-certify closure.

## Other confirmations

- **M05 has not started.** This closure covers M04.1 only — an administration-navigation restructuring of already-shipped M00–M04 functionality. No Conversations-boundary code, plan, or branch exists as of this record.
- **No Git tag, no GitHub Release, no deployment action, no live Telegram bot token, and no live Telegram or WooCommerce API call occurred anywhere in this milestone.**
