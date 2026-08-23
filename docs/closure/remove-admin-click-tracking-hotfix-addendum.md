# Addendum — Remove Developer-Only Click Tracking From Normal Admin Settings (post-M09)

Closure-record addendum. Not a milestone; no ADR, feature, migration, dependency, or M11 work. Filed against the M09 closure record because it is the most recent milestone closure at the time this defect was found and fixed.

## Defect

Two related problems in the Visitor Tracking settings page (`src/Administration/Visitor/VisitorTrackingPage.php`):

1. Unchecking any checkbox on the page (most visibly "Exclude administrators", which defaults on) and clicking Save did not persist the uncheck.
2. The "Clicks" family toggle and "Click target allowlist" textarea exposed a developer-only mechanism — a click event only records if it carries an exact, case-sensitive `target_key` string from an admin-entered allowlist — that an ordinary WordPress administrator cannot configure safely or meaningfully, since nothing in the shipped product assigns such keys to page elements.

## Root cause

1. `VisitorTrackingPage::handle_request()` merged submitted fields over the currently-stored settings (`array_merge( $this->settings->get(), $input )`) before sanitizing. Unchecked HTML checkboxes are omitted from `$_POST` entirely, so the merge always fell back to the old stored value for any box the administrator had just unchecked. The identical bug was independently present, and separately fixed, on the plugin-wide Settings page's `chat_widget_allow_anonymous` checkbox (PR #24), which had been left out of that page's own prior fix for the same problem.
2. The click-tracking controls were shipped with the M04 visitor/browser-events milestone as a raw, developer-facing mechanism with no admin-friendly key-assignment workflow ever built around it — a product decision to remove it from the ordinary admin surface until such a workflow exists.

## Fix

- `VisitorTrackingPage::handle_request()` now explicitly treats every checkbox field's absence from the submitted request as `false` before merging (matching the pattern already used on the plugin-wide Settings page), fixing the general unchecked-checkbox bug.
- The "Clicks" toggle and "Click target allowlist" textarea, their help text, and their request-handling are removed entirely from the ordinary admin page. No replacement raw technical field, selector, key, shortcode, or developer-facing explanation was added.
- `Settings::sanitize()` no longer processes `visitor_family_clicks` or `visitor_click_target_allowlist` from any submitted input at all — both fields always resolve to their unchanged defaults (`false` / `array()`) regardless of what a request, crafted or otherwise, submits.
- `Events\Visitor\IngestController`'s event-type-to-settings-family gate no longer maps `visitor.click` to any family, so a `visitor.click` event is dropped unconditionally at the point of ingestion, independent of any settings value — including a legacy `visitor_family_clicks = true` value already persisted in the database from before this fix. This makes the change effective immediately at runtime, without requiring an administrator to re-save settings.
- No schema, database-version, ADR, milestone, or M11 change. All other Visitor Tracking settings and event families (page views, navigation, search, JavaScript errors, WooCommerce, exclude-administrators, consent mode, sampling percent) are unchanged.

## Regression coverage

- `tests/integration/Administration/Visitor/VisitorTrackingPageTest.php`: unchecking "Exclude administrators" and saving persists the uncheck; the page renders neither click control; a crafted POST containing `visitor_family_clicks`/`visitor_click_target_allowlist` cannot enable them; a legacy persisted click setting is cleared by an unrelated save.
- `tests/integration/Events/Visitor/IngestControllerTest.php`: a `visitor.click` event is never recorded even when legacy settings (`visitor_family_clicks = true` plus a matching allowlist entry) are already stored.
- `tests/unit/Core/Configuration/SettingsTest.php`: `sanitize()` ignores any submitted click-tracking input, always returning the unchanged defaults.
- `src/Administration/Hub/SettingsPage.php`'s own `chat_widget_allow_anonymous` checkbox received the same isset()-as-false fix (PR #24), independently regression-tested there.

## Validation evidence

Full local validation run clean before each PR was opened, and independently reproduced by GitHub Actions on both PRs:

- **PR #24** (`fix/ai-model-dropdown-and-checkbox-save-bug`, merge commit `1d99ebe`): PHPCS clean, PHPStan clean, unit 233/233, WP-only integration 759/759 (50 skipped as expected). All 13 CI checks green on both push/pull_request triggers.
- **PR #25** (`fix/remove-admin-click-tracking`, merge commit `a109421`): PHPCS clean, PHPStan clean, unit 232/232, WP-only integration 763/763 (50 skipped as expected), JS 57/57. All 13 CI checks green.

## Status

**PASS.** PR [magpern/universal-telegram#24](https://github.com/magpern/universal-telegram/pull/24), merged via merge commit `1d99ebe`; PR [magpern/universal-telegram#25](https://github.com/magpern/universal-telegram/pull/25), merged via merge commit `a109421`. Final `main` SHA: `a109421`. `main == origin/main`, working tree clean. Plugin version bumped 0.11.0 → 0.11.1 (patch, corrective release per existing convention) as part of PR #25; no other version change. No Git tag, GitHub Release, deployment action, live OpenAI call, or live Telegram call occurred. No click-target replacement feature was added. **M10 and M11 remain unstarted.**
