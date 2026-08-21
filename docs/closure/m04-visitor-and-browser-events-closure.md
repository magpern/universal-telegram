# Milestone Closure Record — M04 Visitor and Browser Events

- **Frozen plan commit SHA:** `9526369` (`docs: freeze M04 visitor and browser events plan`), materializing `docs/plans/m04-visitor-and-browser-events-plan-v1.md` (v1) and, in the following commit, ADR-0019.
- **Superseding plan commit SHA(s):** None. The plan was not revised during implementation.
- **Baseline SHA (M03 final, `main` before this milestone):** `77dc0a5`.
- **Implementation commits** (branch `feature/m04-visitor-and-browser-events`, merged to `main` via merge commit `ced5adf`):
  - `9526369` — docs: freeze M04 visitor and browser events plan
  - `57f8cb6` — WP0: ADR-0019 (visitor event source threading and browser ingestion boundary) and cross-reference M04 milestone doc
  - `fef8a0a` — WP1: thread `EventSource` through the public emission contract
  - `2e5eaa5` — WP2: visitor settings and six-type core event catalog
  - `0381f7d` — WP3: public visitor event ingestion endpoint with non-bypassable rate limiting
  - `d7608d9` — WP4: dependency-free tracking client, cache-safe enqueue, and behavioural tests
  - `8f16a53` — WP5: WooCommerce-gated visitor product-view and classic add-to-cart intent events
  - `e8e0e69` — WP6: Visitor Tracking settings page and diagnostics
  - `b7ae1a3` — WP7: version bump to 0.3.0 and documentation
  - `cffa2ab` — ci: add JS behavioural test job for the visitor tracker
  - `c1ca3d5` — fix: full-matrix PHPCS/PHPStan violations across the visitor tracking implementation
  - `aab132d` — fix: avoid WordPress-only `sanitize_key()` in the WordPress-free Settings class
  - `ce20f3a` — fix: cross-realm object comparison in JS behavioural test (vm sandbox)
  - `86e4a66` — fix: full-matrix WordPress-only integration test failures (tracker asset enqueue tests)
- **PR:** [magpern/universal-telegram#5](https://github.com/magpern/universal-telegram/pull/5), merged via merge commit `ced5adf` (all fourteen commits preserved individually, not squashed, matching the M00–M03 merge-commit precedent).
- **Final `main` SHA:** `ced5adf` (verified `main == origin/main`, clean working tree, immediately after merge).
- **Closure commit SHA:** recorded by this document's own commit, immediately following.
- **Requirements-to-evidence mapping:** `docs/plans/m04-visitor-and-browser-events-plan-v1.md` §8 (work packages), §9 (requirements traceability table), §12 (definition of done) — satisfied item-by-item by the work-package commits and validation evidence below.

## Implementation scope

Nine `visitor.*` event types, all `EventSource::VISITOR`, `schema_version = 1`, added as a third, anonymous, browser-originated event source atop M02's frozen `Registry`/`EventEnvelope`/`EventEmitter`/`EventIdentity` contracts:

| # | Event type | Family | Notes |
|---|---|---|---|
| 1 | `visitor.session_started` | page_views | One per new tab session; idempotency key `visit:<visit_ref>`, no event fields |
| 2 | `visitor.page_viewed` | page_views | `subject.path`, `subject.page_type` |
| 3 | `visitor.navigation` | navigation | `subject.from_path`, `subject.to_path` |
| 4 | `visitor.search_performed` | search | `payload.result_count` only — no search term or hash |
| 5 | `visitor.click` | clicks | `subject.target_key`, admin-allowlisted |
| 6 | `visitor.javascript_error` | errors | `payload.error_category` (bounded enum) only — no message, stack, or location |
| 7 | `visitor.product_viewed` | commerce (WC-gated) | `subject.product_id`, validated via `wc_get_product()` |
| 8 | `visitor.add_to_cart_intent` | commerce (WC-gated) | Classic checkout only; `subject.product_id` |
| 9 | `visitor.checkout_started_intent` | commerce (WC-gated) | Page-entry signal only, no fields |

Two new emitter/catalog classes: `Events\Visitor\VisitorEventCatalog` (six always-on types, registered unconditionally at `universal_telegram_register_event_types` priority 20) and `Integrations\WooCommerce\Visitor\VisitorCommerceEventCatalog` (three types, registered only when `WooCommerceSupport::is_active()`, mirroring M03's exact gating pattern). New public, unauthenticated ingestion boundary: `POST universal-telegram/v1/visitor-events` (`Events\Visitor\IngestController`), backed by `IngestRequestValidator` (strict allow-listed short-code schema), `BotFilter`, and `Sampler`. A dependency-free frontend tracker (`assets/js/visitor-tracker.js`, 7265 bytes, well under the 8192-byte cap) delivers via `sendBeacon`/`fetch(keepalive)`, enqueued cache-safely by `Events\Visitor\TrackerAssets` (nine excluded contexts: admin, AJAX, cron, REST, feeds, robots.txt, trackbacks, JSON requests, wp-login.php). `universal_telegram_emit_event()` gained an additive, optional fourth `EventSource` parameter (ADR-0019); every existing three-argument call site is unaffected. No new database table (`rate_limit_state` reused with two new `scope_type` values, plus one new non-autoloaded HMAC-secret option); `db_version` stays `10`. Administration reuses the existing `CapabilityRegistrar::MANAGE` capability — no new capability constant introduced. Plugin version moves `0.2.0 → 0.3.0`.

## Privacy and consent confirmation

- No field at any classification level, across all nine event types, is an IP address, raw user-agent, cookie value, `localStorage`/session-storage content beyond the tracker's own `visit_ref` key, email, name, form value, query string, fragment, referrer URL, raw error message, stack trace, or client error location (file/line/column). Verified catalog-wide by `VisitorCatalogPrivacyAuditTest` (a denylist scan of every `visitor.*` classification-map field path for `visit_ref`, `search_term`, `location`, `ip_address`, `user_agent`, `cookie`, `email`, `referrer`, `query`, `fragment`, `stack`, `message` substrings — zero matches) and confirms the catalog is exactly nine types.
- `context.visit_ref` is never an event field: it exists only transiently in the browser's request body and as raw idempotency-key input, never placed into `actor`/`subject`/`context`/`payload`, never classified, never persisted (`EventHistoryRepository` and `AuditLogger` persist only derived `event_id`s, never the raw idempotency key).
- Consent (`window.universalTelegramConsent`) is documented, in ADR-0019, the M04 plan, and the settings-page copy itself, as an explicitly **client-side, non-server-verifiable suppression signal** — the endpoint's privacy guarantee is structural (what the schema cannot accept), never consent-dependent. Settings-page copy states this in plain language (`VisitorTrackingPageTest::test_an_administrator_can_render_the_page` asserts the exact disclosure text is present).
- Tracking is disabled by default at two independent levels: the master switch (`visitor_tracking_enabled`) and every per-family toggle, all `false` by default (`SettingsTest::test_visitor_tracking_and_every_family_toggle_default_off`).
- Administrators are excluded from collection by default (`visitor_exclude_administrators`, default `true`), checked both server-side (`IngestController`) and client-enqueue-side (`TrackerAssets`).

## Security confirmation

- The ingestion endpoint is unauthenticated at the WP-REST layer by design (anonymous browsers cannot hold a secret); the Origin/Referer same-origin check is documented throughout as CSRF friction only, never authentication — the endpoint is treated as writable by any non-browser client that forges those headers.
- Two independent, non-bypassable rate-limit tiers reuse `Telegram\Reliability\RateLimiter` unchanged: a per-client HMAC-keyed daily fairness bucket (`visitor_ingest`, key = `hash_hmac('sha256', ip . UA . day, $secret)`, secret is a 32-byte `random_bytes()` value in a non-autoloaded option, never exposed in any UI/export, never reversible, classified as transient security processing rather than visitor-tracking data) and a global site-wide hard cap (`visitor_site`, `scope_id = 0`, independent of any client-supplied value). `IngestControllerTest::test_the_site_wide_bucket_rejects_requests_regardless_of_forged_headers_or_rotated_identifiers` confirms the hard cap holds under rotated User-Agent/visit/uuid values across three consecutive attempts.
- Uniform `202 Accepted` (empty body) for every accepted-or-silently-suppressed case (tracking disabled, family disabled, bot detected, sampled out) — confirmed by `IngestControllerTest::test_a_bot_user_agent_is_silently_suppressed_with_202` and `test_disabled_tracking_returns_202_and_records_nothing`, neither of which produces a distinguishable response from a genuinely accepted, recorded request.
- Strict allow-listed schema: body capped at 8192 bytes (413 if exceeded), 1–10 events per batch, ≤6 flat data keys per event, ≤190-byte scalar values, unknown top-level key/event type/malformed `visit`/`uuid` shape → 400 (`IngestControllerTest::test_a_malformed_body_returns_400`, `test_an_oversized_body_returns_413`).
- A forged or nonexistent `product_id` on a WC-gated event is rejected 400, never recorded (`VisitorCommerceEventCatalogTest::test_a_forged_nonexistent_product_id_is_rejected_400`, WC-present configuration only).
- Duplicate/retried batches with the same client-generated `uuid` collapse to one `event_history` row via the existing `event_id` unique-constraint mechanism (`IngestControllerTest::test_a_resubmitted_batch_with_the_same_uuid_is_deduplicated`); the JS behavioural suite independently confirms the tracker itself reuses the same `uuid` on a failed-then-retried flush rather than regenerating one.
- No raw IP or user-agent is ever persisted in any form; both are read from the request transiently, used only for the duration of one HMAC computation, and discarded.

## Reliability and correctness confirmation

- `EventEmitter::emit()`'s existing never-throws contract (M02, unchanged) is unaffected by the new fourth parameter — `EventEmitterTest::test_a_three_argument_emit_call_still_records_wordpress_core_as_the_source` and `test_a_four_argument_emit_call_with_visitor_source_reaches_the_history_source_column` confirm both the backward-compatible default and the new explicit path reach `event_history.source` correctly.
- Session/reload semantics: `sessionStorage` survives reloads, so a reload does not produce a new `session_started` event, only another `page_viewed` under the same `visit_ref` (`tests/js/visitor-tracker.test.mjs::'a reload (same sessionStorage) does not produce a new session_started event'`).
- Error volume bound: capped at 5 `javascript_error` events per tab session, a flat volume bound rather than location-based deduplication, since no location data is ever collected (`tests/js/visitor-tracker.test.mjs::'a 6th simulated error after 5 produces no further outbound call'`).
- Navigation pairing: one `from_path`/`to_path` pair per same-tab transition (`tests/js/visitor-tracker.test.mjs::'a simulated same-tab navigation records one from_path/to_path pair per transition'`).
- The three WooCommerce-gated types register only when WooCommerce is active (`VisitorCommerceEventCatalogTest::test_the_three_commerce_types_are_registered_only_when_woocommerce_is_active`, WC-present job only) and are distinct in type/source/`event_id` from M03's `woocommerce.cart_item_added` (`test_add_to_cart_intent_is_distinct_from_the_m03_cart_item_added_event`) — no correlation is attempted or stored between an intent event and its possible M03 confirmation, an accepted limitation per the frozen plan.
- Cache-safety: the tracker's inline configuration is computed entirely from the current request's own settings/page-context (never a per-user/per-session value), confirmed static-per-URL by `TrackerAssetsTest::test_inline_config_is_static_per_url_and_carries_the_current_page_context`; `IngestController` re-validates every setting live on each request regardless of what a stale cached config claims.
- No new `RuleEvaluator`, `NotificationRuleRepository`, `TemplateRenderer`, `NotificationDispatcher`, `DispatchLogRepository`, `Queue\Dispatcher`, or `MessageDispatcher` behaviour was touched by this milestone; all nine types integrate purely additively through the unchanged M02 registration/emission/dispatch pipeline — the only new step is the ingestion endpoint feeding `universal_telegram_emit_event()` exactly as any other emitter would.

## WooCommerce coverage and known, documented functional limitation

- `visitor.add_to_cart_intent` supports **classic (shortcode-rendered) checkout only** — a delegated click listener matching `.single_add_to_cart_button, .add_to_cart_button`. No public, verifiable WooCommerce block browser-event API was confirmed available for the block-based cart/checkout flow; rather than infer coverage from undocumented block markup or internal CSS class names, block add-to-cart is explicitly and intentionally unsupported in M04 (ADR-0019, M04 plan §4.6). This is an accepted, documented design boundary, not a defect.
- `visitor.checkout_started_intent` is a page-entry signal (`is_checkout()`, a server-rendered WordPress conditional true for both classic and block checkout equally, requiring no CSS-selector inference) — it signals only that the visitor's browser rendered the checkout page, never any checkout step, field, or payment action.

## Version confirmation

- `UNIVERSAL_TELEGRAM_VERSION`: `0.2.0 → 0.3.0`, per the frozen plan's explicit version target and `docs/ARCHITECTURE.md`'s minor-bump-per-capability-class convention (a new functional-capability class, unlike M03's purely additive registrations).
- `universal_telegram_db_version`: unchanged at `10`. No new migration, table, or schema change — `rate_limit_state` is reused with two new `scope_type` string values (`visitor_ingest`, `visitor_site`), both fitting the table's existing `VARCHAR(16)` column; confirmed by the pre-existing `MigratorTest` assertions (untouched, still asserting `db_version === 10`) and by package acceptance's unchanged `db_version=10` assertion across all three configurations.
- Distributable package: `universal-telegram-0.3.0.zip`, built and verified across all three package-acceptance configurations.
- **No Git tag, no GitHub Release, and no deployment action was created or performed for this milestone.**

## Automated test results summary

Full local validation matrix, run in the repo's established CI order, clean end to end in a single final pass on commit `86e4a66` before the PR was merged, and independently reproduced by GitHub Actions on both the `push` and `pull_request` triggers for that same commit (runs `32490358983`/`32491259096`, all jobs green), and again on merge commit `ced5adf` (run `32493990965`, success):

- `bin/docker/composer.sh install` — clean.
- `bin/docker/phpcs.sh` — clean (0 errors, 0 warnings).
- `bin/docker/phpstan.sh` — clean (0 errors).
- `bin/docker/test-unit.sh --php-version=8.1` — 154 tests, 420 assertions, all green.
- `bin/docker/test-unit.sh --php-version=8.3` — 154 tests, 420 assertions, all green.
- `bin/docker/test-unit.sh --php-version=8.4` — 154 tests, 420 assertions, all green.
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1` — 257 tests, 594 assertions, 38 skipped (WooCommerce-gated tests correctly self-skip).
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` — 257 tests, 594 assertions, 38 skipped.
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1` — 257 tests, 1214 assertions, 1 skipped.
- `bin/docker/test-js.sh` (Node's built-in `node --test`, zero npm dependencies) — 7 tests, all passing: short-code event-object schema, consent-gate suppression under `required` mode with consent unset, consent-granted collection, retry-reuses-uuid, same-tab navigation pairing, 5-error cap, reload-does-not-duplicate-session_started.
- Manual documentation-link validation (no dedicated tool exists in this repository; a targeted Python scan of every relative Markdown link under `docs/`) — 0 broken links.
- `bin/docker/build-zip.sh` — builds `universal-telegram-0.3.0.zip`.
- `bin/docker/test-package.sh --wp-version=6.9 --php-version=8.1` — PASSED.
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3` — PASSED.
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1` — PASSED.
- GitHub Actions: all jobs green on both the `push` and `pull_request` triggers for commit `86e4a66` (`phpcs`, `static-analysis`, `unit` ×3 PHP versions, `integration-wp-only-floor`, `integration-wp-only-current`, `integration-wc-present-current`, `js-behavioural`, `build`, `package-acceptance` ×3 configurations), and again on merge commit `ced5adf`.
- No site-, customer-, host-, or environment-specific material found in any tracked M04 file or the built ZIP (direct grep of `docs/plans/m04-*`, `docs/adr/0019-*`, `docs/milestones/m04-*`, `src/Events/Visitor/`, `src/Integrations/WooCommerce/Visitor/`, `src/Administration/Visitor/`, and `assets/js/visitor-tracker.js`).
- No live Telegram token, live Telegram Bot API call, or live WooCommerce API call occurred anywhere in this milestone's tests, CI, or package acceptance.
- No AI, chat, or future-scope code was introduced anywhere in this diff.

## Material deviations from the frozen plan (each fully resolved before its own commit, none touching a frozen M01–M03 contract, catalog scope, or weakening any assertion)

- **`Settings::sanitize()`'s click-allowlist sanitizer replaced `sanitize_key()` with a hand-written regex normalizer.** `Settings` is documented as "pure, WordPress-free, unit-testable without a bootstrap" (its own class docblock, unchanged from M00); `sanitize_key()` is a WordPress-only function unavailable under the plain-PHPUnit unit-test configuration, discovered when `SettingsTest::test_sanitize_bounds_the_click_target_allowlist_to_eight_sanitized_keys` failed under `bin/docker/test-unit.sh`. Fixed with an equivalent lowercase-alphanumeric/underscore/hyphen filter, preserving identical sanitization behavior with no WordPress dependency.
- **`TrackerAssets`'s `CapabilityRegistrar` constructor dependency removed.** PHPStan flagged the injected instance as write-only/never-read, since the administrator-exclusion check uses the class constant `CapabilityRegistrar::MANAGE` directly (a static reference, not an instance method) — an unused dependency, not a design gap. Removed the parameter; `Core\Plugin::init()` and the corresponding test updated accordingly.
- **`IngestController::section_for()` dropped its unused `$field_name` parameter**, flagged by PHPCS (`UnusedFunctionParameter`) — the actor/subject/context/payload section a field belongs to is determined entirely by event type in this catalog, never by the individual field name.
- **`TrackerAssetsTest`'s admin/REST-exclusion test switched from `set_current_screen()` to a directly-defined `REST_REQUEST` constant**, and the enqueue tests gained a `setUp()` step forcing a fresh `$wp_scripts` global. Root cause: WordPress core's own test harness does not reset the `$wp_scripts` singleton between test methods, so inline-script data attached by an earlier test's call leaked onto the same script handle in a later one; separately, `set_current_screen()`'s simulated admin-context state was found to leak across test methods in a way that made `is_admin()` unreliable as a test signal. `REST_REQUEST` is a real PHP constant that cannot be undefined once set, so that one exclusion check is deliberately ordered last in the test class (documented in the test's own docblock) rather than attempted per-test.
- **`WP_Scripts::add_inline_script()`'s own behavior — casting `get_data()`'s initial `false` return to `(array) false = [false]` on a handle's first call — required reading the inline-script assertion from the *last* array element (`end($data)`), not index `0`.** This is WordPress core's own documented behavior, not a defect in this milestone's code; the test was simply written against an incorrect assumption about array shape, corrected once diagnosed via direct inspection of `class-wp-scripts.php`.
- **`tests/js/visitor-tracker.test.mjs`'s navigation-pairing assertions switched from `assert.deepEqual` to a JSON-stringify comparison.** Objects constructed inside the Node `vm` sandbox belong to a different realm than the test file's own main-realm object literals; Node's `assert/strict` `deepEqual` (aliased to `deepStrictEqual`) compares prototype identity as well as own-property values, so a structurally identical cross-realm object fails strict equality. Comparing via `JSON.stringify()` normalizes this without weakening the assertion's actual content check.

None of the above touches a security boundary, persistence model, public contract, frozen M01–M03 contract, or milestone boundary requiring a superseding ADR — each is either a WordPress-dependency removal from an intentionally WordPress-free class, an unused-dependency/unused-parameter cleanup, or a test-isolation/test-assumption correction, consistent with governance's "ordinary defect fixes and refactors that preserve existing contracts" carve-out.

## ADRs introduced or superseded in this milestone

ADR-0019 (Visitor Event Source Threading and Browser Ingestion Boundary) — accepted in commit `57f8cb6`. None superseded. Event identity (ADR-0015), history projection (ADR-0017), privacy classification (ADR-0009), and the WooCommerce optional-integration boundary (ADR-0003) are reused entirely unchanged and are not reopened by ADR-0019.

## Final status

**PASS**. Every acceptance criterion in `docs/milestones/m04-visitor-and-browser-events.md` and every requirements-traceability row in the frozen plan (§9) is met with the automated evidence above; no known defect or unaccepted scope gap remains open. The two stated functional limitations — classic-only `add_to_cart_intent`, and consent enforcement being an explicitly non-verifiable, client-side-only signal — are explicit, accepted design boundaries per ADR-0019 and the frozen plan, not defects, and are documented in the settings-page copy, ADR-0019, and this closure record alike.

- **No separate manual acceptance requirement under ADR-0011.** Per ADR-0011, M00–M09 (including M04) are exempt from Vlad Stormhaven's independent manual acceptance session. Required quality evidence for M04 is the frozen plan, ADR-0019, code review, mandatory automated validation, and green CI — all satisfied above, matching M00–M03's closure-record evidentiary shape. **Vlad's independent acceptance results:** Not applicable for M04 per ADR-0011.
- **No Git tag, no GitHub Release, no deployment action, no live Telegram bot token, and no live Telegram or WooCommerce API call occurred anywhere in this milestone.**
- **M05 was not started.** This closure covers M04 only; no M05 branch, plan, or code exists as of this record.
- **Product Owner acceptance:** Pending. Not yet independently recorded by Vlad Stormhaven as of this closure record's commit — status is left as `Pending` rather than presumed, per governance's requirement that the Implementation Agent cannot self-certify closure.
