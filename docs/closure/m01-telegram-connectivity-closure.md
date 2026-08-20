# Milestone Closure Record — M01 Telegram Connectivity

- **Frozen plan commit SHA:** `2a865f5` (`docs(m01): freeze Telegram connectivity implementation plan`), materializing `docs/plans/m01-telegram-connectivity-plan-v1.md` (v1, revision 2) and ADR-0012 through ADR-0014.
- **Superseding plan commit SHA(s):** None. The plan was not revised during implementation.
- **Implementation commits** (branch `feature/m01-telegram-connectivity`, merged to `main` via merge commit `59725ed`):
  - `55927c2` — WP1: six-table Telegram schema (bots, destinations, outbound messages, inbound updates, circuit breaker, rate limit state)
  - `29d3605` — WP2: bot profile domain and credential-vault-backed repository
  - `e3cc1b9` — WP3: destination domain (private, group, supergroup, channel, forum topic)
  - `2fae3af` — WP4: Telegram Bot API client and failure classifier
  - `4efa0fe` — WP5: per-bot/per-destination rate limiter and circuit breaker
  - `7ccb784` — WP6: durable outbound message store and queue-integrated send handler
  - `bb22569` — WP7: authenticated, replay-protected inbound webhook route
  - `1ac17a7` — WP8: rate limiting, circuit breaking, and dead-letter handling wired into the send path
  - `7dcd400` — WP9: retention-based cleanup of message content and delivery-log rows
  - `a06d19e` — WP10: bot/destination management screen with the failure-safe webhook registration/rotation coordinator
  - `a77e40f` — WP11: Telegram health and queue-health alerts on the diagnostics page
  - `ef67a5b` — WP12: uninstall extended with Telegram table cleanup and best-effort webhook deregistration
  - `af8bc1f` — WP13: architecture reference, changelog, version bump, package-acceptance extension
  - `ac911c2` — chore: upgrade `actions/checkout` from v4 to v7 (CI tooling maintenance, not a plan work package; included in this PR at the Product Owner's request, touches only `.github/workflows/ci.yml`)
  - WP14 (requirements traceability finalization) produced no commit of its own, per the plan's own description of that work package as a verification pass; its evidence is the final full-matrix run recorded below, run on commit `af8bc1f` and reconfirmed on `ac911c2`.
- **PR:** [magpern/universal-telegram#2](https://github.com/magpern/universal-telegram/pull/2), merged via merge commit `59725ed` (all fourteen work-package commits plus the CI chore commit preserved individually, not squashed).
- **Requirements-to-evidence mapping:** `docs/plans/m01-telegram-connectivity-plan-v1.md`, section 12 ("Requirements traceability") and section 13 ("Complete Definition of Done"), both satisfied item-by-item by the work packages and validation commands listed above and the automated test results below.
- **Automated test results summary** (full matrix, run clean end to end on the final commit before merge (`ac911c2`), and independently reproduced by GitHub Actions on both the push and pull-request triggers — all green, 24/24 checks):
  - `bin/docker/composer.sh install --no-interaction` — clean.
  - `bin/docker/phpcs.sh` — clean.
  - `bin/docker/phpstan.sh` (level 5, no baseline) — clean.
  - `bin/docker/test-unit.sh` — PHP 8.1 / 8.3 / 8.4 — 75 tests, 149 assertions, each.
  - `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1` — 126 tests, 347 assertions.
  - `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` — 126 tests, 347 assertions.
  - `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1` — 126 tests, 347 assertions.
  - `bin/docker/build-zip.sh` — builds `universal-telegram-0.1.0.zip`.
  - `bin/docker/test-package.sh --wp-version=6.9 --php-version=8.1` — PASSED, including the new bots-table and token/ciphertext non-exposure assertions.
  - `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3` — PASSED.
  - `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1` — PASSED.
  - `bin/docker/composer.sh run-script check-doc-links` — clean.
  - GitHub Actions: all 12 jobs green on both the `push` (direct-to-branch) and `pull_request` triggers for the final commit — `phpcs`, `static-analysis`, `unit` (×3 PHP versions), `integration-wp-only-floor`, `integration-wp-only-current`, `integration-wc-present-current`, `build`, `package-acceptance` (×3 configurations).
- **Vlad's independent acceptance results:** Not applicable for M01 per ADR-0011 — formal, independent manual acceptance is deferred until M10. Required quality evidence for M01 is the frozen plan, code review, mandatory automated validation, and green CI, all listed above, exactly matching M00's closure record's evidentiary shape. Per the frozen plan section 9, an optional, non-gating sandbox-bot smoke procedure (a real Telegram bot via BotFather against a real HTTPS endpoint) is recommended as a one-time manual sanity check before M02 begins building on this transport, but is explicitly not a closure requirement and is not presented as one here.
- **ADRs introduced or superseded in this milestone:** ADR-0012 (Telegram bot cardinality, webhook routing, and outbound delivery architecture), ADR-0013 (Telegram webhook authenticity, replay protection, and inbound handling — including the failure-safe registration/rotation protocol), ADR-0014 (Telegram provider reliability policy: rate limiting, circuit breaking, dead-letter, queue-health alerting, at-least-once delivery) — all accepted in the freeze commit. None superseded.

## Security and privacy confirmation

- No bot token or webhook secret is ever rendered on any admin screen, plaintext or ciphertext — proven by `BotManagementPageTest::test_the_rendered_page_never_exposes_the_plaintext_token_or_any_ciphertext` and the packaged-plugin acceptance run's own `wp eval` smoke assertion (WP13).
- No token, webhook secret, or message body ever reaches a queued job's payload — proven structurally by `OutboundPayloadClassificationTest`, which asserts `Queue\JobEnvelope`'s existing fail-closed classification policy rejects a `telegram_send_message` payload carrying a `body` or `token` field.
- Every bot isolation boundary (encrypted token, encrypted webhook secret, rate-limiter state, circuit-breaker state, all keyed per bot and per destination) is independently verified; a failure or throttling condition on one bot or destination never affects another (`CircuitBreakerTest`, `RateLimiterTest`, `SendMessageHandlerTest`).
- Webhook authenticity uses constant-time (`hash_equals()`) secret comparison; every authenticity failure mode (missing header, wrong secret, unknown `bot_uuid`, undecryptable stored secret) returns an identical generic 401 with no distinguishing detail (`WebhookControllerTest`).
- Replayed inbound updates are idempotent via the `UNIQUE(bot_id, update_id)` database constraint; no message text is ever persisted, only metadata (`WebhookControllerTest`, `WebhookSecretVerifierRotationTest`).
- The webhook registration/rotation protocol is failure-safe against an uncertain remote outcome: indefinite active/pending dual-secret acceptance, no automatic expiry, resolution only via explicit retry, explicit rollback (clearing pending only on confirmed clean success), or organic traffic-based confirmation. No automated code path (including the retention cleanup job) ever writes to, discards, promotes, or replaces a pending secret — proven directly by `WebhookRegistrationCoordinatorTest`'s six required scenarios, matching the plan's own Master Architect review requirement.

## Reliability confirmation

- Rate-limit and circuit-open deferrals never consume `Queue\RetryPolicy`'s bounded attempt budget — proven by `RateLimiterDeferralTest` (a locally rate-limited destination defers via a fresh Action Scheduler action without incrementing the message's attempt count and without a real Telegram call being attempted at all).
- The bot-scope circuit breaker opens after 5 consecutive `RETRYABLE` failures, the destination-scope breaker after 3, each independently, and both integrate with the real `SendMessageHandler` send path, not only the standalone primitive (`CircuitBreakerTest`).
- `TOKEN_INVALID` (HTTP 401) opens the bot-scope breaker indefinitely with no scheduled probe and marks the bot `invalid`; only an explicit admin token replacement (itself validated via `getMe` before committing) can clear it (`SendMessageHandlerTest`).
- Terminal (`TERMINAL`) and token-invalid failures dead-letter immediately, on the very first attempt, without exhausting the generic retry budget; a `RETRYABLE` failure on the final permitted attempt also dead-letters before rethrowing so `WorkerRunner`'s own generic terminal-failure audit entry still fires (`SendMessageHandlerTest`, `DeadLetterLifecycleTest`).
- **Explicit at-least-once delivery limitation, confirmed by test:** a network-transport-level send failure (no HTTP response received at all) sets `possible_duplicate_delivery` on the message; a definite HTTP error response never does (`DuplicateDeliverySignalTest`). This is documented operator-facing in `readme.txt`'s "Delivery guarantees" section, per ADR-0014.
- The queue-health alert (dead-letter count, any open circuit breaker, stale-pending messages, stale unresolved webhook registrations/rotations) is surfaced only as a local WordPress diagnostics-page section and a capability-gated, 60-second-cached `admin_notices` banner — never as a Telegram message (`QueueHealthAlertTest`, `DiagnosticsPageTest`).

## Version confirmation

- `UNIVERSAL_TELEGRAM_VERSION`: `0.0.1` → `0.1.0` (minor bump — M01 is the plugin's first genuine functional capability beyond foundation scaffolding), reflected in `universal-telegram.php` and `readme.txt`'s stable-tag field.
- `universal_telegram_db_version`: `1` → `7` (six new Telegram migration steps), confirmed by `MigratorTest::test_clean_install_creates_all_six_telegram_tables` and the packaged-plugin acceptance run.
- Distributable package: `universal-telegram-0.1.0.zip`, built and verified across all three package-acceptance configurations.

## Implementation notes (material deviations, each fully resolved before its own commit)

- **`TelegramApiClientTest` location.** The frozen plan placed this test under `tests/unit/Telegram/Client/`. It was implemented under `tests/integration/Telegram/Client/` instead: WordPress's `pre_http_request` filter and `wp_remote_post()`, the fake-transport seam the plan itself specifies for this test, require a real WordPress bootstrap, which `tests/unit/bootstrap.php` deliberately carries none of. This is a code-detail correction, not an architectural one — the test's own content and coverage are unaffected, and it is still picked up automatically by the existing `integration-wp-only-*` CI jobs with no workflow change, consistent with the plan's own finding that directory-suffix auto-discovery covers every new test file.
- **`Settings` field count.** The frozen plan's WP9 description said "the five new fields," while its own section 8 schema enumerated seven. All seven fields from section 8 (`telegram_message_retention_days`, `telegram_delivery_log_retention_days`, `telegram_max_pending_seconds`, `telegram_webhook_max_body_bytes`, `telegram_stale_pending_alert_seconds`, `telegram_rate_limit_fallback_wait_seconds`, `telegram_webhook_rotation_max_pending_hours`) were added at WP9, since `telegram_rate_limit_fallback_wait_seconds` and `telegram_max_pending_seconds` are consumed by `SendMessageHandler` (wired at WP8) and `telegram_webhook_max_body_bytes` by `WebhookController` (wired at WP7) — WP9 is where `Settings` itself was introduced, so all Telegram-specific fields were added together and the already-wired WP7/WP8 code was updated in the same commit to source its previously-hardcoded defaults from `Settings` instead. No behavior changed; the frozen numeric defaults are unchanged.
- **MySQL `NULL` handling in dynamically built `INSERT` statements.** `CircuitBreaker::write()` and `UpdateRepository::record()` initially passed a `null` argument through a `%s`/`%d` placeholder to `$wpdb->prepare()` for optional columns (`opened_at`, `next_probe_at`, `chat_id`, `message_thread_id`). This was caught by `CircuitBreakerTest::test_401_opens_indefinitely_with_no_scheduled_probe` (WP5/WP8) failing: the column was stored as an empty string, not SQL `NULL`, causing an indefinitely-open breaker to be treated as having a (misinterpreted) scheduled probe time. Fixed by rendering each nullable column as an explicit SQL `NULL` literal or a single-value `prepare()`d fragment, never a placeholder for a potentially-null argument, before splicing into the outer statement. No production impact reached a commit; caught and fixed within the same work package.
- **`WebhookRegistrationCoordinatorTest` traffic-confirmation ordering.** An early version of the scenario-2 test asserted that a pending secret both "still authenticates after being aged" and, separately, that the stale-rotation count remained 1 — but calling `WebhookSecretVerifier::verify()` with the pending secret is itself the traffic-based confirmation mechanism, which promotes the secret and resolves the rotation as a side effect. The test was corrected to check the stale count before performing the confirming `verify()` call, and to then assert the rotation is fully resolved (`registered`, no pending secret) afterward — this is the intended, correct behavior per ADR-0013, not a defect; only the test's own assertion order needed correction.

## Final status

**PASS**. All Definition of Done items (plan section 13) and all requirements-traceability entries (plan section 12) are met with the automated evidence listed above; no known defect or scope gap remains open.

- **Explicit at-least-once delivery limitation:** stated and accepted as designed (ADR-0014), not a defect — see "Reliability confirmation" above.
- **No live Telegram token or live Telegram API call was used anywhere in this milestone.** Every Bot API interaction across the unit, integration, and package-acceptance suites is faked via WordPress's own `pre_http_request` filter, per the frozen plan's mock/fake HTTP transport seam.
- **No manual/Vlad acceptance was required or performed for M01, per ADR-0011.**
- **Product Owner acceptance:** Pending. Not yet independently recorded by Magnus Pernemark as of this closure record's commit.
