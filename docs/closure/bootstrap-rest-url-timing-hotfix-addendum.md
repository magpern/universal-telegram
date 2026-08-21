# Addendum — Bootstrap Hotfix (post-M04)

Closure-record addendum. Not a milestone; no ADR, feature, migration, dependency, or settings change. Filed against the M04 closure record because it is the most recent milestone closure at the time this defect was found and fixed.

## Defect

A real WordPress 7.1 activation with pretty permalinks configured fataled during plugin activation:

```
Call to a member function using_index_permalinks() on null
```

thrown from WordPress core's `get_rest_url()`, called via `rest_url('universal-telegram/v1/webhook/')`, during `UniversalTelegram\Core\Plugin::init()` on the `plugins_loaded` hook.

## Root cause

`Plugin::init()` runs on `plugins_loaded` — a point at which WordPress' rewrite state (`$GLOBALS['wp_rewrite']`) is not guaranteed to exist yet. WordPress core's own `get_rest_url()` (`wp-includes/rest-api.php`) only dereferences `$wp_rewrite->using_index_permalinks()` when a non-empty `permalink_structure` is configured (pretty permalinks) — plain-permalink installs never hit that branch, which is why this was not caught by the existing test matrix or by prior package-acceptance runs (none of which configure pretty permalinks). `Plugin::init()` called `rest_url()` **eagerly**, while constructing `WebhookRegistrationCoordinator`, so any real site with pretty permalinks active fataled on every activation.

## Fix

`WebhookRegistrationCoordinator`'s fourth constructor parameter changed from an eagerly-evaluated `string $webhook_base_url` to a lazily-invoked `callable(): string $webhook_base_url_provider`, called only inside `attempt_set_webhook()` — the sole place the URL is ever actually needed, always well after WordPress' `init` hook has fired (webhook register/rotate/retry/rollback operations only ever run from admin-triggered requests, never during bootstrap). `Core\Plugin::init()` now passes a closure (`static fn(): string => rest_url('universal-telegram/v1/webhook/')`) instead of calling `rest_url()` eagerly. No route, no settings field, no ADR, and no M01–M04 behavioural change: the webhook route path, the registration/rotation protocol, and every existing feature are unaffected — only the *timing* of one function call moved.

## Regression coverage

`tests/integration/Core/PluginBootstrapTest.php`:

- Resets the `Plugin` singleton to a fresh, un-booted instance via reflection (restored afterward, so no other test is affected).
- Sets a non-empty `permalink_structure` (required to reach the `$wp_rewrite`-dereferencing branch of `get_rest_url()`, matching the real-world crash condition).
- Unsets `$GLOBALS['wp_rewrite']`.
- Re-runs the real `Plugin::init()` — reproducing the actual `plugins_loaded` → `init` lifecycle ordering.
- Runs in a separate PHPUnit process (`@runInSeparateProcess`, `@preserveGlobalState disabled`) so the singleton reset never contaminates the rest of the suite.

**Confirmed to fail against the pre-fix code** (verified directly via `git stash` before committing the fix), with the identical error and stack trace as the reported production defect:

```
Error: Call to a member function using_index_permalinks() on null
/tmp/wordpress-7.1/wp-includes/rest-api.php:537
/tmp/wordpress-7.1/wp-includes/rest-api.php:600
/app/src/Core/Plugin.php:517
```

A second, unit-level regression was added directly to `WebhookRegistrationCoordinatorTest.php`, asserting the URL provider is never invoked at construction time — a narrower, faster-running companion to the full-lifecycle test above.

## Validation evidence

Full local validation matrix run once, clean end to end, on commit `7583e8a` before the PR was opened, and independently reproduced by GitHub Actions on both the `push`/`pull_request` triggers (run `32497992724`, all 13 jobs green) and again on merge commit `01247a3` (run `32498415076`):

- `bin/docker/composer.sh install` — clean.
- `bin/docker/phpcs.sh` — clean.
- `bin/docker/phpstan.sh` — clean.
- `bin/docker/test-unit.sh` — PHP 8.1 / 8.3 / 8.4 — 154 tests, 420 assertions each, all green.
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1` — 260 tests, 597 assertions, 38 skipped.
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` — 260 tests, 597 assertions, 38 skipped.
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1` — 260 tests, 1217 assertions, 1 skipped.
- `bin/docker/test-js.sh` — 7 tests, all green (unaffected by this change).
- `bin/docker/build-zip.sh` — builds `universal-telegram-0.3.0.zip` (no version bump; this is a bug fix, not a new capability).
- `bin/docker/test-package.sh` — PASSED across all three configurations (WP 6.9/PHP 8.1, WP 7.1/PHP 8.3, WP 7.1/PHP 8.3/WooCommerce 11.0.1).
- Focused regression re-run against the fix (after the pre-fix failure was confirmed): `PluginBootstrapTest`, `WebhookRegistrationCoordinatorTest`, `BotManagementControllerTest` — 14 tests, 41 assertions, all green.

## Status

**PASS.** PR [magpern/universal-telegram#6](https://github.com/magpern/universal-telegram/pull/6), merged via merge commit `01247a3` (all required checks green). Final `main` SHA: `01247a3`. `main == origin/main`, working tree clean. No Git tag, GitHub Release, deployment action, live Telegram call, or live WooCommerce call occurred. **M05 remains unstarted.**
