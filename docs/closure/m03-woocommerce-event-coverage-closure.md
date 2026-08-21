# Milestone Closure Record — M03 WooCommerce Event Coverage

- **Frozen plan commit SHA:** `654d056` (`docs: freeze M03 WooCommerce event coverage plan`), materializing `docs/plans/m03-woocommerce-event-coverage-plan-v1.md` (v1) and, in the following commit, ADR-0018.
- **Superseding plan commit SHA(s):** None. The plan was not revised during implementation.
- **Baseline SHA (M02 final, `main` before this milestone):** `8172794`.
- **Implementation commits** (branch `feature/m03-woocommerce-event-coverage`, merged to `main` via merge commit `2e93144`):
  - `654d056` — docs: freeze M03 WooCommerce event coverage plan
  - `71dc032` — WP0: ADR-0018 (WooCommerce event catalog) and cross-reference M03 milestone/master-plan docs
  - `bc399ee` — WP1: WooCommerce order-created and order-status-changed event emitters
  - `c431644` — WP2: WooCommerce payment, cancellation, and refund event emitters
  - `82aa22b` — WP3: WooCommerce stock-threshold event emitter
  - `4c84ebc` — WP4: WooCommerce cart and coupon event emitters
  - `209c8a3` — WP5: WooCommerce checkout-validation-failed event emitter, classic checkout only
  - `1d3d5f0` — WP6: WooCommerce activation and HPOS status added to diagnostics report
  - `845397c` — WP7: cross-cutting WooCommerce event catalog privacy and checkout-safety tests
  - `a7955a9` — fix: full-matrix PHPCS/PHPStan violations across WooCommerce event emitters (post-implementation validation gate)
  - `81674a2` — fix: full-matrix WordPress-only and WooCommerce-present integration test failures
- **PR:** [magpern/universal-telegram#4](https://github.com/magpern/universal-telegram/pull/4), merged via merge commit `2e93144` (all eleven commits preserved individually, not squashed, matching the M00/M01/M02 merge-commit precedent).
- **Final `main` SHA:** `2e93144` (verified `main == origin/main`, clean working tree, immediately after merge).
- **Requirements-to-evidence mapping:** `docs/plans/m03-woocommerce-event-coverage-plan-v1.md` §8 (work packages), §9 (test strategy and requirements traceability table), §11 (final consistency checks) — satisfied item-by-item by the work-package commits and validation evidence below.

## Implementation scope

Eleven `woocommerce.*` event types, thirteen WooCommerce hook/filter bindings, added as a second, optional event source atop M02's frozen `Registry`/`EventEnvelope`/`EventEmitter`/`EventIdentity` contracts, gated on `Integrations\WooCommerce\WooCommerceSupport::is_active()`:

| # | Event type | Hook(s) | Bindings |
|---|---|---|---|
| 1 | `woocommerce.order_created` | `woocommerce_checkout_order_processed` + `woocommerce_store_api_checkout_order_processed` | 2 |
| 2 | `woocommerce.order_status_changed` | `woocommerce_order_status_changed` | 1 |
| 3 | `woocommerce.payment_completed` | `woocommerce_payment_complete` | 1 |
| 4 | `woocommerce.order_failed` | `woocommerce_order_status_failed` | 1 |
| 5 | `woocommerce.order_cancelled` | `woocommerce_order_status_cancelled` | 1 |
| 6 | `woocommerce.refund_created` | `woocommerce_order_refunded` | 1 |
| 7 | `woocommerce.stock_threshold_crossed` | `woocommerce_low_stock`, `woocommerce_no_stock` | 2 |
| 8 | `woocommerce.checkout_validation_failed` | `woocommerce_after_checkout_validation` (classic only) | 1 |
| 9 | `woocommerce.cart_item_added` | `woocommerce_add_to_cart` | 1 |
| 10 | `woocommerce.coupon_applied` | `woocommerce_applied_coupon` | 1 |
| 11 | `woocommerce.coupon_rejected` | `woocommerce_coupon_error` (filter) | 1 |

Five new emitter classes under `src/Integrations/WooCommerce/Events/` (`OrderEventEmitter`, `StockEventEmitter`, `CartEventEmitter`, `CouponEventEmitter`, `CheckoutEventEmitter`), wired into `src/Core/Plugin.php`'s existing composition-root `init()` behind a single `WooCommerceSupport::is_active()` gate, exactly mirroring the pre-existing core-emitter wiring pattern. Two new diagnostics keys (`woocommerce_hpos_enabled`, `woocommerce_event_emitters_registered`) added to `Administration\Diagnostics\DiagnosticsReport::generate()`. One new ADR (0018). Zero admin-UI code changes: the Event Catalog, Rule Builder, and Rule Simulator pages surface all eleven new types automatically via their existing `Registry::all()`/`allowed_variable_fields_for()` iteration. No new database migration, table, queue job type, public plugin hook, admin page, or Telegram transport path. Every emitter calls `universal_telegram_emit_event()` exclusively — no emitter constructs `EventEnvelope`/`EventDispatcher` directly.

**`woocommerce.order_failed` naming**: sourced from `woocommerce_order_status_failed`, part of the generic `woocommerce_order_status_{status}` hook family. It observes "order entered failed status" by *any* cause (payment-gateway failure, manual admin edit, or third-party extension calling `set_status()`/`update_status()` directly) — it is **not** a guaranteed, gateway-verified payment-failure signal, and is named and documented accordingly throughout the code, ADR-0018, and this closure record.

**Bounded-coalescing idempotency policy** (ADR-0018 Decision §12, M03 plan §5.2): this is a deliberate, stated trade-off, not audit-grade one-event-per-hook-invocation behaviour.

- *State-keyed* (`stock_threshold_crossed`): keyed on `(product_id, status, stock_quantity)`, no time component — a repeat firing at the identical quantity/status collapses to one event regardless of elapsed time.
- *Attempt-window* (`order_status_changed`, `order_failed`, `order_cancelled`): keyed with a second-resolution timestamp — transitions within the same wall-clock second collapse.
- *Coarse time-bucket* (`checkout_validation_failed`, `coupon_applied`, `coupon_rejected`): keyed with a 5-second bucket, chosen because WooCommerce core exposes no more precise, stable, pre-write identity for these occurrences.
- *Line-identity* (`cart_item_added`): keyed on the cart line's own identity alone, no quantity or time component — every add-to-cart call for the same line collapses permanently to one event; the recorded `payload.quantity`/`payload.cart_total` reflect the state at the **first** such call for that line, never a later one. Verified by `CartEventEmitterTest::test_repeated_additions_to_the_same_cart_line_coalesce_to_the_first_emission`.

**Checkout-validation limitation (known, documented, accepted — not a defect)**: `checkout_validation_failed` is implemented for classic (shortcode) checkout only, bound to `woocommerce_after_checkout_validation`. No block/Store-API equivalent hook exists in WooCommerce core as of 11.0.1 (block validation is exception/schema-based with no catch-all validation-failure action). No unofficial Store API/REST-exception workaround was added (ADR-0018 Decision §8). This does not affect the charter's "classic and block checkout compatibility" acceptance criterion for the *other* ten event types, which are all confirmed to work identically under both flows (`order_created`, `cart_item_added`, `coupon_applied`, `coupon_rejected` all fire from hooks shared between classic and block/Store API code paths).

## Security and privacy confirmation

- No field across all eleven event types, at any classification level, is billing/shipping name, email, phone, address, IP address, payment method, payment token, card data, gateway id, or gateway response body. The only payment-adjacent field in the entire catalog is a boolean `context.has_transaction_id` flag on `payment_completed` — never a transaction id, token, or gateway payload (`OrderEventEmitterTest::test_payment_completed_is_emitted_with_has_transaction_id_boolean_only`).
- Structurally enforced by `Registry::register()`'s existing fail-closed validation (`UnclassifiedFieldException`, `NonPublicHistoryFieldException`, ADR-0017, unchanged) and additionally verified catalog-wide by `NoPiiFieldAuditTest` — a denylist scan of every `woocommerce.*` classification-map field path for email/phone/address/billing_/shipping_/token/payment_method/gateway/card_/name/ip_address/transaction_id substrings, with a single documented exception (`context.has_transaction_id`, a boolean, never the id itself).
- Fields that would have required genuinely sensitive data are omitted from the envelope entirely, not merely reclassified: refund `reason` (free text), checkout validation error message text (only WC's own stable machine error-code slugs are kept), coupon `error_message` text, transaction id itself. Verified directly: `OrderEventEmitterTest::test_refund_created_is_emitted_without_reason_text`, `CheckoutEventEmitterTest::test_error_message_text_never_appears_only_stable_error_codes`, `CouponEventEmitterTest::test_coupon_rejected_never_records_the_error_message_text`.
- No raw `WC_Order`/`WC_Product`/`WC_Coupon`/`WC_Order_Refund` object and no `serialize()`d structure ever enters an envelope — every emitter extracts individual scalar getter values only (`$order->get_id()`, `$order->get_total()`, `$product->get_stock_quantity()`, etc.), and `EventEnvelope`'s classification-map validation cannot accept an object as a leaf value regardless.
- `woocommerce.coupon_rejected`'s emission from a WooCommerce filter callback (`woocommerce_coupon_error`, registered via `add_filter`) rather than an action was reviewed and confirmed safe: the callback returns `$message` unmodified (`CouponEventEmitterTest::test_coupon_rejected_is_emitted_via_the_filter_and_returns_the_message_unmodified`), and `EventEmitter::emit()`'s synchronous, non-throwing contract is identical regardless of the calling hook type.
- `EventEmitter::emit()`'s existing never-throws contract (M02, unchanged) continues to protect every WooCommerce request thread from any event-processing failure: proven end to end, fired from real WooCommerce hook callbacks, under a forced downstream `EventDispatcher` failure (`CheckoutSafetyTest`, both the order-created and no-stock hooks).
- No WordPress-only-mode regression: `StructuralGuardTest::test_woocommerce_absent_registers_zero_woocommerce_event_types` (run in the WP-only CI configuration) asserts zero `woocommerce.*` entries in `Registry::all()` and `is_registered()` returns `false` for all eleven types; the companion `test_woocommerce_present_registers_the_full_catalog` (run in the WC-present configuration) asserts the affirmative — exactly 11 types registered, matching the frozen catalog with no stray twelfth type.
- No environment-specific material (host names, paths, credentials, IPs) appears anywhere in the tracked M03 files or the built `universal-telegram-0.2.0.zip` — confirmed by direct grep of the source/test/docs tree and a Python zip-namelist scan for `biopentra`.
- No live Telegram token or live Telegram Bot API call occurs anywhere in this milestone's tests, CI, or package acceptance.
- No AI, chat, or future-scope code was introduced anywhere in this diff.

## Reliability and correctness confirmation

- Classic and block checkout dual-hook dedup for `order_created`: both hooks fire for the literal same order → single `event_id`, confirmed directly (`OrderEventEmitterTest::test_classic_and_block_hooks_for_the_same_order_collapse_to_one_event_id`).
- Stock dual-code-path dedup for `stock_threshold_crossed`: two firings for the identical `(product_id, status, stock_quantity)` triple collapse to one event regardless of which internal WooCommerce code path triggered them (`StockEventEmitterTest::test_dual_code_path_for_the_same_quantity_and_status_collapses_to_one_event`); a different quantity is confirmed distinct (`test_a_different_quantity_is_a_distinct_occurrence`).
- `order_failed` and `order_status_changed` both firing for one status transition to `failed` is confirmed intentional, not double-counting: both are recorded, with distinct `event_id`s (`OrderEventEmitterTest::test_order_failed_and_order_status_changed_both_fire_for_one_transition_with_distinct_event_ids`).
- Attempt-window coalescing for status-transition types confirmed both ways: repeats within the same wall-clock second collapse (`test_order_status_changed_idempotency_key_coalesces_within_the_same_second`); distinct status pairs remain independent (`test_order_status_changed_distinct_status_pairs_are_independent`).
- Coarse time-bucket coalescing for `coupon_applied` confirmed (`CouponEventEmitterTest::test_coupon_applied_repeated_within_the_same_bucket_coalesces`).
- `refund_created`'s exact-identity key (refund id alone) confirmed: `wc_create_refund()`'s own implicit hook firing plus two additional manual firings for the same refund id still produce exactly one history row (`OrderEventEmitterTest::test_refund_created_idempotency_key_is_the_refund_id_alone`).
- HPOS order-retrieval correctness: emitted fields for `order_created` match `wc_get_order()`'s own getters on a freshly reloaded order object (`OrderEventEmitterTest::test_order_fields_match_hpos_storage_agnostic_getters`), run in the WC-present CI job (WooCommerce 11.0.1, HPOS enabled by default). No storage-backend branching exists anywhere in any emitter's own order- or product-access code — `wc_get_order()` is used exclusively, matching M03 plan §9's storage-agnosticity requirement.
- `payload.cart_total` on `cart_item_added` is confirmed present in `Registry::allowed_variable_fields_for()`, making the deferred "cart-value-threshold" use case buildable via the existing `GREATER_THAN` rule condition with no rule-engine change (`CartEventEmitterTest::test_cart_item_added_is_emitted_with_rule_condition_usable_cart_total`).
- No new `RuleEvaluator`, `NotificationRuleRepository`, `TemplateRenderer`, `NotificationDispatcher`, `DispatchLogRepository`, `Queue\Dispatcher`, or `MessageDispatcher` behaviour was touched by this milestone; all eleven types integrate purely additively through the unchanged M02 registration/emission/dispatch pipeline.

## Version confirmation

- `UNIVERSAL_TELEGRAM_VERSION`: unchanged at `0.2.0`. The frozen M03 plan does not call for a version bump (unlike M02's explicit "Version target" section); M03 is additive registrations against the existing event system, not a new top-level capability class in the sense M02's bump reflected.
- `universal_telegram_db_version`: unchanged at `10`. No new migration, table, or schema change — confirmed directly (M03 plan §6, §11) and by package acceptance's unchanged `db_version=10` assertion across all three configurations.
- Distributable package: `universal-telegram-0.2.0.zip`, built and verified across all three package-acceptance configurations.
- **No Git tag, no GitHub Release, and no deployment action was created or performed for this milestone.**

## Automated test results summary

Full local validation matrix, run in the repo's established CI order, clean end to end in a single final pass on commit `81674a2` before the PR was merged, and independently reproduced by GitHub Actions on both the `push` and `pull_request` triggers for that same commit, and again on merge commit `2e93144`:

- `bin/docker/composer.sh install` — clean.
- `bin/docker/phpcs.sh` — clean (0 errors, 0 warnings).
- `bin/docker/phpstan.sh` (level 5, no baseline) — clean (0 errors). Required a dev-only `php-stubs/woocommerce-stubs` addition (declaration-only, no runtime code — WooCommerce itself remains a genuinely optional runtime dependency per ADR-0003, never a production Composer require) and a raised PHPStan worker memory limit (`bin/docker/phpstan.sh`, `--memory-limit=1G`) to accommodate the added stub file.
- `bin/docker/test-unit.sh` — PHP 8.1 / 8.3 / 8.4 (CI) — 127 tests, 242 assertions each (unchanged from M02; M03 added no unit tests, only integration tests, per its own emitters' WordPress/WooCommerce-hook-bound nature).
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1` — 235 tests, 550 assertions, 35 skipped (all M03's WooCommerce-gated tests correctly self-skip via `markTestSkipped()` when `UT_TEST_WC_ACTIVE` is unset).
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` — 235 tests, 550 assertions, 35 skipped.
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1` — 235 tests, 1170 assertions, 1 skipped.
- `bin/docker/composer.sh run-script check-doc-links` — clean.
- `bin/docker/build-zip.sh` — builds `universal-telegram-0.2.0.zip`.
- `bin/docker/test-package.sh --wp-version=6.9 --php-version=8.1` — PASSED.
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3` — PASSED.
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1` — PASSED.
- GitHub Actions: all jobs green on both the `push` and `pull_request` triggers for commit `81674a2` (`phpcs`, `static-analysis`, `unit` ×3 PHP versions, `integration-wp-only-floor`, `integration-wp-only-current`, `integration-wc-present-current`, `build`, `package-acceptance` ×3 configurations), and again on merge commit `2e93144`.

**M03-specific verification, beyond the general matrix above**: exactly 11 registered `woocommerce.*` event types and 13 hook/filter bindings confirmed (`StructuralGuardTest`, `NoPiiFieldAuditTest`, and direct code review of each emitter's `register_hooks()`); WooCommerce-absent mode registers zero `woocommerce.*` types (`StructuralGuardTest`, run in the WP-only job); classic and block order creation both confirmed to dedupe to one event; stock dual-path dedup confirmed; cart-line coalescing confirmed to preserve first-emission values; `order_failed` confirmed accurately named and described throughout code, ADR-0018, and this record; no excluded sensitive field found anywhere in the catalog; an induced downstream dispatch failure confirmed never to break a WooCommerce request thread; no environment-specific material found in tracked files or the built ZIP.

## Material deviations from the frozen plan (each fully resolved before its own commit, none touching a frozen M01/M02 contract, catalog scope, or weakening any assertion)

- **`payload.error_codes` (array of strings, per plan §5.10) implemented as `payload.error_codes_csv` (a single comma-joined string).** `EventEnvelope`'s fail-closed classification validation recursively walks every array value, requiring a classification-map entry at each leaf's own dot-path — this is structurally incompatible with a variable-length list of scalars (a per-index path like `payload.error_codes.0` cannot be pre-declared for an unbounded list). Followed this codebase's own established precedent for exactly this shape of problem (`Events\Emitters\UserLifecycleEmitter`'s pre-existing `payload.old_roles_csv` field). The underlying data (WC's own stable machine error-code slugs, e.g. `billing_email_invalid`) is unchanged — only its wire representation.
- **`OrderEventEmitter::order_modified_timestamp()` helper added.** `WC_Order::get_date_modified()` can legitimately return `null` (e.g. for a status transition applied without a save that touches this field); the three status-transition idempotency-key call sites (`order_status_changed`, `order_failed`, `order_cancelled`) now fall back to `0` rather than raising a fatal error out of a live WooCommerce hook callback. Discovered and fixed during the held-until-the-end validation pass, before any PR was opened.
- **Two `OrderEventEmitterTest` assertions switched from `assertSame()` to `assertEqualsWithDelta()`** for order-total comparisons. `EventHistoryRepository`'s existing JSON round-trip (unchanged M02 behaviour) correctly decodes a whole-number float (e.g. `50.0`) back as a PHP int (`50`) on `json_decode()`, since PHP's `json_encode()` omits the trailing `.0` by default — a pre-existing, correct encoding behaviour, not a defect introduced by M03. The test's exact-type assertion was simply too strict for this known round-trip characteristic.
- **`CheckoutSafetyTest`'s forced-downstream-failure technique** uses a reflection-based, single-test-scoped swap of the live `Core\Plugin` singleton's private `EventEmitter` for one wired to an always-throwing `EventDispatcher` subclass — the identical technique M02's own `EventEmitterTest` already uses, applied here to the live composition-root instance for the duration of one WooCommerce hook call, then restored unconditionally in a `finally` block. An earlier draft used a `RENAME TABLE` trick on the event-history table instead; this was replaced because MySQL DDL statements cause an implicit `COMMIT`, which silently broke `WP_UnitTestCase`'s per-test transaction-rollback isolation for every later test in the same PHPUnit process — discovered via full-matrix contamination (`SchemaDegradedExecutionTest` and others failing only when the full suite ran, never in isolation) before any PR was opened.
- **`tests/integration/Queue/SchemaDegradedExecutionTest.php` (a pre-existing M01 test, unmodified in substance) gained two test-hygiene `setUp()` steps**: clearing any stale `actionscheduler_claims` rows, and cancelling Action Scheduler's own internal `action_scheduler/migration_hook` housekeeping action. Root-caused via targeted debug instrumentation (removed before commit): once WooCommerce's own Action Scheduler bundle is active and the fuller M03-expanded suite runs long enough (~13s vs. ~5s pre-M03) for Action Scheduler's own internal migration housekeeping action to become due, that unrelated action could be claimed and processed instead of, and in place of, this test's own newly-enqueued action within the same `ActionScheduler_QueueRunner::run()` call, making the test's own queue-runner assertion fail non-deterministically depending on unrelated Action Scheduler internal state and total suite wall-clock time — never depending on any M03 production code correctness. No production code was touched by this fix; the test's own assertions about degraded-schema behaviour are unchanged.

None of the above touches a security boundary, persistence model, public contract, frozen M01/M02 contract, or milestone boundary requiring a superseding ADR — each is either a data-representation accommodation following an existing codebase precedent, a defensive null-guard, a test-assertion-strictness correction, or a test-isolation/test-hygiene fix, consistent with governance's "ordinary defect fixes and refactors that preserve existing contracts" carve-out.

## Known, documented functional limitation

- **`woocommerce.checkout_validation_failed` is classic (shortcode) checkout only.** No block/Store-API equivalent hook exists in WooCommerce core as of version 11.0.1 (block validation is exception/schema-based, with no catch-all validation-failure action for third-party code to observe). This is accepted as a documented, known gap (ADR-0018 Decision §8) rather than an unofficial Store API/REST-exception workaround, which would rely on undocumented internals and risk breaking across WooCommerce's own block-checkout refactors. It does not block the charter's broader "classic and block checkout compatibility" acceptance criterion, which concerns whether *covered* event types work correctly under both flows — they do, for all ten of the other event types, including `order_created`, `cart_item_added`, `coupon_applied`, and `coupon_rejected`, all confirmed to fire identically under classic and block/Store API checkout.

## ADRs introduced or superseded in this milestone

ADR-0018 (WooCommerce event catalog and hook binding for M03) — accepted in commit `71dc032`. None superseded. Event identity (ADR-0015), history projection (ADR-0017), and privacy classification (ADR-0009) are reused entirely unchanged and are not reopened by ADR-0018.

## Final status

**PASS**. Every acceptance criterion in `docs/milestones/m03-woocommerce-event-coverage.md` and every requirements-traceability row in the frozen plan (§9) is met with the automated evidence above; no known defect or unaccepted scope gap remains open. The one stated functional limitation (classic-only `checkout_validation_failed`) is an explicit, accepted design boundary per ADR-0018, not a defect, and is documented in the Event Catalog's own description text and this closure record.

- **No separate manual acceptance requirement under ADR-0011.** Per ADR-0011, M00–M09 (including M03) are exempt from Vlad Stormhaven's independent manual acceptance session. Required quality evidence for M03 is the frozen plan, ADR-0018, code review, mandatory automated validation, and green CI — all satisfied above, matching M00/M01/M02's closure-record evidentiary shape. **Vlad's independent acceptance results:** Not applicable for M03 per ADR-0011.
- **No Git tag, no GitHub Release, no deployment action, no live Telegram bot token, and no live Telegram or WooCommerce API call occurred anywhere in this milestone.**
- **M04 was not started.** This closure covers M03 only; no M04 branch, plan, or code exists as of this record.
- **Product Owner acceptance:** Pending. Not yet independently recorded by Vlad Stormhaven as of this closure record's commit — status is left as `Pending` rather than presumed, per governance's requirement that the Implementation Agent cannot self-certify closure.
