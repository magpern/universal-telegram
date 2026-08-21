# Milestone Closure Record — M02 Normalized Events and Notifications

- **Frozen plan commit SHA:** `ebd08d9` (`docs(m02): freeze normalized events and notifications plan`), materializing `docs/plans/m02-normalized-events-and-notifications-plan-v1.md` (v1, revision 2) and ADR-0015 through ADR-0017.
- **Superseding plan commit SHA(s):** None. The plan was not revised during implementation.
- **Baseline SHA (M01 final, `main` before this milestone):** `780ed9e`.
- **Implementation commits** (branch `feature/m02-normalized-events-and-notifications`, merged to `main` via merge commit `92a9d45`):
  - `8cda98b` — WP1: Events/Automations schema (migration steps 8-10, four new tables) and capability scaffolding
  - `ab33d9e` — WP2: deterministic event identity, envelope, registry, and safety-wrapped emission facade
  - `1d798f2` — WP3: PUBLIC-only event history projection and retention cleanup
  - `b77a94e` — WP4: core WordPress event emitters, bounded fatal-error capture, feedback-loop exclusions
  - `ad27def` — WP5: notification rule storage and condition model
  - `1f0ea74` — WP6: deterministic rule evaluation and safe template rendering
  - `aad9ed7` — WP7: idempotent, honestly-scoped dispatch state model and M01 transport wiring
  - `5d01a86` — WP8: capability-gated event catalog and rule builder admin screens
  - `f09ac16` — WP9: rule simulation and event history browser admin screens
  - `44bd1d5` — WP10: diagnostics integration, version bump to 0.2.0, architecture reference sync
  - `6cf0f58` — fix: full-matrix PHPCS violations across Events/Automations (post-implementation validation gate)
  - `674979b` — fix: full-matrix PHPStan findings
  - `c0d3ca9` — fix: un-final three Automations classes to allow unit-test doubling
  - `82bc231` — fix: full-matrix WordPress-only integration test failures
  - `3e43ac6` — test: extend package acceptance with M02 security/privacy assertions
- **PR:** [magpern/universal-telegram#3](https://github.com/magpern/universal-telegram/pull/3), merged via merge commit `92a9d45` (all fifteen commits preserved individually, not squashed).
- **Final `main` SHA:** `92a9d45` (verified `main == origin/main`, clean working tree, immediately after merge).
- **Requirements-to-evidence mapping:** `docs/plans/m02-normalized-events-and-notifications-plan-v1.md`, section 12 (work packages), section 13 (requirements traceability), and section 14 (definition of done), all satisfied item-by-item by the work-package commits and validation evidence below.
- **Targeted-validation timing deviation, authorized by the Product Owner:** per the Product Owner's explicit instruction for this milestone, PHPCS/PHPStan/unit/integration/package validation was intentionally held until after all ten work packages were implemented, rather than run per work package as the frozen plan's own section 11 otherwise describes. This overrode only the *timing* of interim validation; the final required test coverage and quality gate were not reduced — the one complete local matrix below is the same evidence the frozen plan's WP10 already required, run once, in full, before the PR was opened.
- **Automated test results summary** (full matrix, run clean end to end in a single final pass on commit `3e43ac6` before the PR was opened, and independently reproduced by GitHub Actions on both the `push` and `pull_request` triggers — all green, 24/24 checks):
  - `bin/docker/composer.sh install` — clean.
  - `bin/docker/phpcs.sh` — clean (0 errors, 0 warnings).
  - `bin/docker/phpstan.sh` (level 5, no baseline) — clean (0 errors).
  - `bin/docker/test-unit.sh` — PHP 8.1 / 8.3 / 8.4 (CI) — 127 tests, 242 assertions, each.
  - `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1` — 195 tests, 510 assertions.
  - `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3` — 195 tests, 510 assertions.
  - `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1 --php-version=8.3` — 195 tests, 510 assertions.
  - `bin/docker/build-zip.sh` — builds `universal-telegram-0.2.0.zip`.
  - `bin/docker/test-package.sh --wp-version=6.9 --php-version=8.1` — PASSED, including all M02-specific assertions below.
  - `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3` — PASSED.
  - `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1` — PASSED.
  - `bin/docker/composer.sh run-script check-doc-links` — clean.
  - GitHub Actions: all 24 jobs green on both the `push` and `pull_request` triggers for the final commit (`3e43ac6`) — `phpcs`, `static-analysis`, `unit` (×3 PHP versions), `integration-wp-only-floor`, `integration-wp-only-current`, `integration-wc-present-current`, `build`, `package-acceptance` (×3 configurations), each doubled by the two trigger types.
- **Vlad's independent acceptance results:** Not applicable for M02 per ADR-0011 — formal, independent manual acceptance is deferred until M10. Required quality evidence for M02 is the frozen plan, code review, mandatory automated validation, and green CI, all listed above, matching M00's and M01's closure records' evidentiary shape and the frozen plan's own §0 acceptance-model note.
- **ADRs introduced or superseded in this milestone:** ADR-0015 (event identity, envelope, registry, and safety-wrapped emission), ADR-0016 (notification rule engine: storage, deterministic evaluation, and an honestly-scoped dispatch state model), ADR-0017 (event history as a PUBLIC-only redacted projection) — all accepted in the freeze commit. None superseded.

## Security and privacy confirmation

- No event-history JSON contains anything except declared PUBLIC fields — enforced structurally at registration time (`Events\Registry::register()` rejects any non-PUBLIC field in `history_projection_fields`, throwing `NonPublicHistoryFieldException`; `RegistryTest`), applied a second time as defense in depth via `Privacy\Redactor` at write time (`EventHistoryRepositoryTest`), and confirmed against a real emitted event in packaged-plugin acceptance (`wordpress.user_role_changed`'s INTERNAL-classified `old_roles_csv` never appears in the stored row; the PUBLIC-classified `new_role` does).
- INTERNAL fields are transient only: usable in rule conditions and message templates (in-memory, never persisted) but structurally excluded from `history_projection_fields` — never appear in event history, diagnostics, audit output, package artifacts, or fixtures (`RegistryTest`, `EventHistoryRepositoryTest`, `TemplateRendererTest`).
- No secret, token, password, raw request body, stack trace, raw path, or fatal-error message is persisted or exposed anywhere in this milestone's own new surfaces: `wordpress.password_reset` never reads `$new_pass` (`UserLifecycleEmitterTest`); `wordpress.email_sending_failed` stores only the fixed `WP_Error` code, never the message (`MailFailureEmitterTest`); the fatal-error mechanism stores only a fixed error-type constant and a SHA-256 location hash, never message text, a stack trace, or a raw file path (`FatalErrorMarkerWriterTest`, `FatalErrorPromotionJobTest`, and confirmed end to end with a deliberately sensitive simulated message/path in packaged-plugin acceptance).
- `Events\EventEmitter` protects the originating WordPress request from any event-processing exception: the entire call graph from `universal_telegram_emit_event()` through history write and rule evaluation is wrapped in one `try/catch`, reduced to a fixed diagnostic code (`EventEmitterTest`, including the explicit assertion that no `do_action('universal_telegram_event', ...)` — or any other public hook — exists as an emission surface).
- A dispatch log never labels a claimed or failed-before-handoff state as handed off: the seven-state `DispatchLogResult` model is exclusively transitioned via `NotificationDispatcher::dispatch()`'s own honest sequence, and `handed_off_to_m01` is written only after `MessageDispatcher::send()`'s returned `DispatchResult` confirms `DispatchState::SCHEDULED` (`NotificationDispatcherTest`).
- Stuck claims are diagnosable and never automatically retried: `DispatchLogRepository::stuck_claim_count()` surfaces claimed rows older than a 30-minute threshold on the Diagnostics page (`automations_stuck_claim_count`); no code path resumes a stuck claim (`DispatchLogRepositoryTest`).
- M01's transport remains correctly documented as at-least-once: M02 introduces no exactly-once claim anywhere; ADR-0016 explicitly states M02 claims exactly-once *decision-making*, not exactly-once *delivery*, and `readme.txt`'s existing "Delivery guarantees" section is unchanged.
- The plugin never emits `wordpress.scheduled_task_failed` for the `universal-telegram` Action Scheduler group (`ScheduledTaskFailureEmitterTest::test_excludes_universal_telegram_group_actions`) and never emits `wordpress.rest_request_failed` for `/universal-telegram/v1/` (`RestRequestFailureEmitterTest::test_excludes_own_rest_namespace`) — both exclusions unconditional and non-configurable.
- No WooCommerce, visitor/browser, AI, chat, or future-scope code was introduced anywhere in this diff.
- No live Telegram token or live Telegram Bot API call occurs anywhere in this milestone's tests, CI, or package acceptance.

## Reliability and correctness confirmation

- Rule evaluation is deterministic: `NotificationRuleRepository::for_event_type()`'s own `ORDER BY priority ASC, id ASC` is the mechanism, not application-level sorting — proven by `RuleEvaluatorTest`'s ordering assertions and `RuleSimulatorTest`'s byte-for-byte ordering-parity assertion against the same fixture shape.
- **Replayed occurrences with the same stable event identity do not produce a second rule-engine handoff:** `Events\EventIdentity::derive()` is deterministic (`EventIdentityTest`); the `UNIQUE(rule_id, event_id)` constraint on `notification_dispatch_log`, enforced via `DispatchLogRepository::claim_or_reject()`'s atomic `INSERT IGNORE`, guarantees a second attempt for the same pair is `skipped_duplicate` and writes nothing further — proven directly by `DispatchLogRepositoryTest` and end to end by `NotificationDispatcherTest::test_replaying_the_same_event_id_never_calls_send_a_second_time` (asserts the `outbound_messages` row count stays at 1 across two dispatch attempts for the same event).
- **Separate independent occurrences remain independent:** distinct idempotency keys derive distinct `event_id`s and are evaluated, claimed, and dispatched independently (`EventIdentityTest`, `EventEnvelopeTest`, `DispatchLogRepositoryTest::test_a_different_event_id_for_the_same_rule_is_independent`, `LoginEmitterTest::test_two_login_failed_firings_are_independent_occurrences`, `PluginLifecycleEmitterTest::test_two_activations_of_the_same_plugin_are_independent_occurrences`).
- One rule's own exception, or one rule's own invalid configuration, never affects another rule's evaluation (`RuleEvaluatorTest::test_one_rule_throwing_does_not_prevent_the_next_rule_from_evaluating`).
- A single event independently matches and dispatches through multiple rules (`RuleEvaluatorTest::test_a_single_event_can_independently_match_multiple_rules`).
- Cooldown is checked only against a rule's most recent `handed_off_to_m01` row, never a `claimed` row, avoiding in-flight-claim timing confusion (`NotificationDispatcherTest::test_cooldown_is_checked_only_against_handed_off_rows`).
- `NotificationDispatcher` has no dependency on `Queue\JobEnvelope` or `Queue\Dispatcher` — the falsifiable, reflection-based evidence that M01's opaque-queue-payload rule remains enforced by construction (`NotificationDispatcherTest::test_notification_dispatcher_has_no_dependency_on_job_envelope_or_queue_dispatcher`).
- Rule simulation never invokes `MessageDispatcher::send()` and never writes to `notification_dispatch_log` — proven via call-counting/never-invoked mock assertions (`RuleSimulatorTest`), so it never consumes real idempotency space.

## Version confirmation

- `UNIVERSAL_TELEGRAM_VERSION`: `0.1.0` → `0.2.0` (minor bump — M02 is a new functional-capability class, not a patch), reflected in `universal-telegram.php` and `readme.txt`'s stable-tag field.
- `universal_telegram_db_version`: `7` → `10` (three new migration steps: step 8 creates `event_history` and `fatal_error_markers`, step 9 creates `notification_rules`, step 10 creates `notification_dispatch_log`), confirmed by `MigratorEventsSchemaTest` and the packaged-plugin acceptance run's explicit `db_version=10` assertion.
- Distributable package: `universal-telegram-0.2.0.zip`, built and verified across all three package-acceptance configurations.
- **No Git tag, no GitHub Release, and no deployment action was created or performed for this milestone.**

## Known, documented limitations

- **`notification_dispatch_log.outbound_message_uuid` is not populated on a successful handoff.** M01's unmodified `Telegram\Outbound\MessageDispatcher::send()` does not return the created outbound message's own UUID in its `DispatchResult` — only the Action Scheduler action ID or a failure code. Populating this column would require either modifying `MessageDispatcher`'s return contract (outside the strictly additive M01-file-extension list this plan authorizes) or an unreliable secondary lookup. The `result` column's own terminal value (`handed_off_to_m01`) remains the complete, authoritative, honest signal that M01 accepted durable queue ownership; this is a traceability convenience gap, not a correctness or duplicate-delivery risk.
- **Stuck-claim diagnostic limitation, accepted by design (ADR-0016):** a `claimed` row from a mid-request termination between the atomic claim and the terminal-state update is never automatically resumed or retried. It is surfaced, counted, and never hidden via `automations_stuck_claim_count` on the Diagnostics page. No code path anywhere resumes it.
- **Fatal-marker scope limitation, stated explicitly in `Events\Emitters\FatalErrorMarkerWriter`'s own docblock:** a parse or compile error in this plugin's own bootstrap file, in a plugin that loads earlier than this one, or in `wp-config.php` itself, occurs before this plugin's shutdown handler can be registered and is not, and cannot be, observed by this mechanism. This is a hard PHP-execution-model boundary, not a design gap.
- **M01's transport remains at-least-once, not exactly-once**, unchanged by this milestone (ADR-0014); M02's own dedup guarantee is scoped precisely to "no second rule-engine handoff decision," stated exactly in ADR-0016, never to exactly-once Telegram delivery.

## Material deviations from the frozen plan (each fully resolved before its own commit)

- **PHPCS's `WordPress.WP.CapitalPDangit` sniff autofix corrupted several literal `wordpress.*` event-type string values in test files during the held-until-the-end validation pass** (e.g. `'wordpress.user_registered'` → `'WordPress.user_registered'`), since the sniff's "WordPress" capitalization heuristic cannot distinguish this plan's fixed, lower-case, dot-namespaced event-type format (§5.1) from prose about the WordPress project. This was caught immediately (the corrupted literals would have silently broken every affected test's own event-type matching) via `grep` before any test run, reverted, and the sniff was then scoped out of `src/Events`, `src/Automations`, `src/Administration/Automations`, and their corresponding test directories in `phpcs.xml.dist`, with the rationale documented inline. No production code was affected — every corrupted literal was in a test file, caught before any commit.
- **`NotificationRuleRepository`, `DispatchLogRepository`, and `NotificationDispatcher` were changed from `final` to non-final** during the held-until-the-end validation pass: `tests/unit/Automations/RuleEvaluatorTest.php` and `RuleSimulatorTest.php` are deliberately pure unit tests with no WordPress bootstrap (per the frozen plan's own WP6/WP9 test placement), and PHPUnit's `createMock()` cannot double a `final` class. This is a narrow, code-level accommodation for test doubling with no behavioral change; production code continues to construct only the real implementations, and no public contract's *behavior* changed.
- **`Automations\DispatchLogRepository::record_rejected()` gained a `reason_code` parameter**, persisting a rejected rule's rejection reason (`invalid_condition_field` / `invalid_condition_operator` / `condition_not_matched`) into the existing, already-present, previously-unused `reason_code` column on `notification_dispatch_log`. The frozen plan's schema already included this nullable column for exactly this kind of use; wiring it through was a genuine improvement caught by PHPStan flagging the parameter as unused in the initial implementation, not a schema change.
- **`Automations\ConditionOperator::matches()` required one `phpcs:ignore` suppression** for a PHPCompatibility sniff false positive (`$this` inside a backed enum's own instance method, valid under PHP 8.1+, misidentified by this sniff version as outside object context), documented inline in the source and in `phpcs.xml.dist`.
- **MySQL `NULL` handling in `DispatchLogRepository::claim_or_reject()`'s dynamically built `INSERT` statement**, following the exact precedent already documented in M01's own closure record: a `null` `$reason_code` is rendered as an explicit SQL `NULL` literal, never passed through a `%s` placeholder (which `$wpdb->prepare()` would coerce to an empty string). Caught during implementation, before any commit, by cross-referencing the M01 precedent directly.

None of the above touches a security boundary, persistence model, public contract, or milestone boundary in a way requiring a superseding ADR — each is either a coding-standard/tooling accommodation or an additive use of already-frozen schema, consistent with governance's "ordinary defect fixes and refactors that preserve existing contracts" carve-out.

## Final status

**PASS**. All Definition of Done items (plan section 14) and all requirements-traceability entries (plan section 13) are met with the automated evidence listed above; no known defect or unaccepted scope gap remains open. The two stated limitations (unpopulated `outbound_message_uuid`, non-retried stuck claims) are explicitly accepted, deliberate design limitations per ADR-0016, not defects, and are documented operator-facing on the Diagnostics page.

- **No separate manual acceptance requirement under ADR-0011.** Required quality evidence for M02 is the frozen plan, code review, mandatory automated validation, and green CI — all satisfied above.
- **No Git tag, no GitHub Release, no deployment action, no live Telegram token, and no live Telegram Bot API call occurred anywhere in this milestone.**
- **M03 was not started.** This closure covers M02 only; no M03 branch, plan, or code exists as of this record.
- **Product Owner acceptance:** Pending. Not yet independently recorded by Magnus Pernemark as of this closure record's commit — status is left as `Pending` rather than presumed, per governance's requirement that the Implementation Agent cannot self-certify closure.
