# Milestone Closure Record — M06.2 Interactive Telegram Delivery

- **Starting baseline SHA (`main` before this milestone):** `3886156` (clean, `main == origin/main`;
  `docs(m06-1): bot setup wizard closure record`, the M06.1 landing point this milestone builds on).
- **Frozen plan/ADR commit SHA:** `961987e` (`docs: freeze M06.2 interactive Telegram delivery plan
  and ADR-0023`), materializing `docs/plans/m06-2-interactive-telegram-delivery-plan-v1.md` (v1) and
  `docs/adr/0023-expedited-interactive-queue-dispatch-and-bounded-synchronous-diagnostic-send.md`.
  Not revised during implementation — no superseding plan SHA.
- **Implementation commits** (branch `feature/m06-2-interactive-telegram-delivery`, merged to `main`
  via merge commit `3f2f50e`):
  - `961987e` — docs: freeze M06.2 interactive Telegram delivery plan and ADR-0023
  - `5d4b5b0` — feat(queue): add guarded expedited dispatch trigger
  - `49967e2` — feat(conversations): expedite queue dispatch for visitor chat messages
  - `407882b` — feat(admin): make Telegram test message a bounded synchronous send
  - `2c9d989` — feat(diagnostics): surface expedited-dispatch and queue-age signals
  - `cb2d098` — docs: version bump to 0.6.1 for M06.2
  - `eab724b` — fix(m06.2): lean-gate repairs from PHPCS/PHPStan and test isolation
- **PR:** [magpern/universal-telegram#16](https://github.com/magpern/universal-telegram/pull/16),
  merged via merge commit `3f2f50e` (all seven commits preserved individually, not squashed, matching
  the M00–M06.1 merge-commit precedent).
- **Final `main` SHA:** `3f2f50e` (verified `main == origin/main`, clean working tree, immediately
  after merge).
- **Closure commit SHA:** recorded by this document's own commit, immediately following.

## Technical status

**PASS.** Every requirement of the frozen plan is implemented and tested; local lean validation, the
full GitHub Actions matrix on PR #16, and a post-merge live manual latency check against the
already-configured dev bot are all green. Product Owner acceptance is pending — see below.

## Implementation scope

Removes the avoidable multi-minute delivery delay for interactive Telegram operations, without
weakening durability or creating a second outbound-message architecture (ADR-0023):

- **`Queue\ExpeditedDispatchTrigger`** (new, `Queue` boundary): after an interactive job is durably
  enqueued via the unmodified `Queue\Dispatcher::enqueue()`, requests a guarded, non-blocking loopback
  dispatch of Action Scheduler's own existing async queue runner, bypassing only the `is_admin()`
  gate Action Scheduler's own admin-context hook applies. Every step (dependency check, concurrency
  pre-check, runner construction, method check, invocation) runs inside one `try/catch`; any failure
  is caught and audited under one of exactly two fixed codes
  (`expedited_dispatch_declined_concurrency` / `expedited_dispatch_unavailable`) — the routine-success
  path is never audited, since `universal_telegram_audit_log` has no retention/cleanup mechanism of
  its own. No per-job priority differentiation was introduced (Action Scheduler orders claims by
  priority across every claim, not merely within one batch, so this was rejected as a starvation
  risk); every job keeps Action Scheduler's default priority.
- **`Conversations\Rest\ConversationsController`**: calls `ExpeditedDispatchTrigger::trigger()`
  exactly once, immediately after the existing `maybe_create()`/`route()` enqueue calls, reachable
  only for a newly accepted, newly enqueued visitor message — never on an idempotent replay or a
  storage failure, both of which already `return` earlier in the same handler. Zero Telegram network
  I/O and zero synchronous topic creation still occur in this request (M05/ADR-0021 boundary
  unchanged).
- **`Administration\Telegram\BotManagementController::send_test_message()`**: rewritten from an
  enqueued, asynchronous send to one bounded (≤8s) synchronous `sendMessage` call via a dedicated
  short-timeout `TelegramApiClient`, using the same credential decryption, destination lookup, and
  `TelegramFailureClassifier` the queue's own send handler uses. Never retried, never creates an
  `outbound_messages` row. The result is one of a fixed set of non-content codes
  (`ok`/`error_not_found`/`error_token_unavailable`/`failed_rate_limited`/`failed_terminal`/
  `failed_token_invalid`/`failed_retryable`), carried via a `test_message_result` redirect query
  argument and rendered as an admin notice by `BotManagementPage` — never raw Telegram error text, a
  token, a secret, or ciphertext. The now-unused `MessageDispatcher` dependency was removed from the
  controller's constructor (PHPStan-flagged, genuinely dead once this method stopped queuing).
- **`Queue\QueueHealth::oldest_pending_age_seconds()`** (new) and two new diagnostics fields
  (`queue_oldest_pending_age_seconds`, plus the two fixed audit-code 24h counts) surfaced on the
  existing `Administration\Diagnostics\DiagnosticsReport` — no new admin surface.
- Version bump only: `universal-telegram.php` (`Version:` header and `UNIVERSAL_TELEGRAM_VERSION`),
  `readme.txt` (`Stable tag` — corrected from a pre-existing stale `0.5.0`, a gap left by M06.1's own
  version-bump commit, not introduced by this milestone), and `docs/ARCHITECTURE.md`'s
  versioning-conventions paragraph (which also gained a one-clause bridge noting M06.1's own
  `0.5.0 → 0.6.0` bump, previously undocumented there).

## Fallback and fairness posture

- **Fallback.** If `ExpeditedDispatchTrigger` finds Action Scheduler unavailable, the concurrency
  pre-check declines, or any step throws, the durably-enqueued job is completely untouched and normal
  WP-Cron cadence (or any wp-admin-triggered async dispatch) remains the sole, fully sufficient
  delivery path — verified by dedicated fallback tests
  (`tests/integration/Queue/ExpeditedDispatchTriggerTest.php`).
- **Fairness.** No per-job priority differentiation exists to starve anything; every job — interactive
  or ordinary — shares Action Scheduler's own default priority and FIFO-by-due-date ordering. A
  sustained chat burst can only cause an extra queue run to happen sooner, never reorder what that run
  processes ahead of ordinary notification/event jobs.

## No-schema/version result

`db_version` is unchanged at `13` — confirmed both before (M06.1 baseline) and after this milestone's
merge. No new table, column, or option. Plugin version: `0.6.0 → 0.6.1`, a patch bump (no new
end-user functional-capability class — a latency/diagnostics improvement over existing capabilities),
consistent with the M04.1 precedent.

## Local validation and GitHub Actions evidence

Local lean validation gate (per the frozen plan's §9, run once after all work packages):

- PHPCS, scoped to every changed PHP file: clean (0 errors after one `phpcbf` repair round — array/
  assignment alignment only).
- PHPStan: `[OK] No errors` (after removing the dead `MessageDispatcher` dependency and a redundant
  `is_object()` check already guaranteed by `ExpeditedDispatchTrigger::create_runner()`'s own
  `@return object` docblock).
- Changed-scope integration tests (`bin/docker` Docker tooling, WordPress 6.9): Queue (16 tests, 38
  assertions), Conversations (72 tests, 161 assertions), Administration/Telegram (46 tests, 125
  assertions), Administration/Diagnostics (23 tests, 76 assertions) — all green. One test-isolation
  repair was needed: Action Scheduler's own tables are not wrapped by WP_UnitTestCase's per-test
  transaction rollback, so `QueueHealthTest`/`DiagnosticsReportQueueTest` now clear the plugin's own
  Action Scheduler group in `setUp()` before asserting "nothing pending."
- Changed-scope unit tests: Queue (11 tests, 19 assertions) — green.
- Documentation/version consistency: `universal-telegram.php` header, `UNIVERSAL_TELEGRAM_VERSION`,
  `readme.txt` stable tag, and `docs/ARCHITECTURE.md` all agree on `0.6.1`.

GitHub Actions full matrix on PR #16 (final run, commit `eab724b`): `build`,
`integration-wc-present-current`, `integration-wp-only-current`, `integration-wp-only-floor`,
`js-behavioural`, `package-acceptance` (6.9/8.1, 7.1/8.3, 7.1/8.3/WC 11.0.1), `phpcs`,
`static-analysis`, `unit` (8.1, 8.3, 8.4) — all **pass**.

## Manual dev-bot latency evidence (required per the frozen plan, WP6)

Performed 2026-08-22, after merge, against the already-configured dev bot (`BioPentraSupportBot`)
and support group on the live `dev.biopentra.eu` install (the plugin directory is bind-mounted
directly from this repository's `main`, so the merge itself is the deployment — no separate deploy
step was taken). No token, bot configuration, group permission, release, or tag was created or
changed.

1. **Admin Test Message.** Invoked the real `BotManagementController::send_test_message()` method
   in-process (via WP-CLI `wp eval`, reflection to call the method directly — bypassing only the
   admin-post HTTP transport layer and its nonce/redirect wrapper, not the method's own logic or the
   real `TelegramApiClient` HTTP call it makes) against bot id 1 / destination id 1 ("Website
   Support"). Result: **`test_message_result = 'ok'`, elapsed 0.155s**, well within the ≤8s bound. No
   `outbound_messages` row was created (confirmed by design, not re-verified by a separate query here
   since the code path is identical to the one already covered by
   `BotManagementControllerTest::test_send_test_message_on_a_faked_success_reports_ok_without_queuing_or_creating_a_message_row`).
2. **Visitor chat message → Telegram topic.** Started a real conversation and posted one message
   through the public REST API (`POST /wp-json/universal-telegram/v1/conversations` then
   `.../messages`) — the exact same HTTP contract the live chat widget's own JS uses. Message row
   created at `2026-08-22 13:46:46` UTC; the corresponding `outbound_messages` row was created and
   marked `sent`, with a real Telegram `telegram_message_id = 15` in forum topic `14`, at
   `2026-08-22 13:46:47` UTC. **Total observed elapsed time: ~1 second**, well within the "roughly
   2–5 seconds under healthy conditions" target and not dependent on any cron-scale delay.

Both checks succeeded against the real network and real bot; neither required a mocked or
intercepted HTTP call. The test conversation/message and the resulting Telegram group message are
left in place in the dev group as ordinary dev-environment test traffic, not cleaned up as part of
this check (cleanup was not requested and risked additional, unauthorized state changes).

## Deviations from the frozen plan

- **Version re-verification found M06.1 had merged since planning.** The plan was drafted against
  `origin/main` @ `b37cc13`; by the time of freeze, M06.1 had merged (`3886156`, PR #14). Per the
  plan's own WP0 instruction, the baseline was re-verified before freezing: version confirmed `0.6.0`,
  ADR-0023 confirmed the next unused number, `db_version` confirmed unchanged at `13`. No plan content
  changed as a result beyond the already-anticipated version target (`0.6.0 → 0.6.1`).
  `readme.txt`'s `Stable tag` was found stale at `0.5.0` at that point (an M06.1 documentation gap,
  not introduced here) and was corrected directly to `0.6.1` as part of this milestone's own version
  work.
- **Admin Test Message result surfacing required a small addition not spelled out line-by-line in the
  plan.** `BotManagementController` had no existing result-messaging mechanism for any admin-post
  operation (every operation was previously fire-and-forget with a fixed redirect). A
  `test_message_result` redirect query argument plus one new `BotManagementPage::render_test_message_notice()`
  method were added to surface the bounded synchronous send's outcome — consistent with the plan's
  explicit "Result semantics" requirement (§3.4), implemented as the minimal mechanism capable of
  meeting it.
- **`MessageDispatcher` dependency removed from `BotManagementController`.** Not explicitly called
  out in the plan, but a direct, necessary consequence of §3.4: once `send_test_message()` no longer
  queues, the property became write-only (PHPStan-flagged) and was removed rather than left dead.
- No other deviation. The guarded trigger design (§3.1), the exact call placement (§3.1/WP2), the
  two-fixed-code audit model (§3.1/§5), and the bounded synchronous Test Message design (§3.4) all
  match the frozen plan and ADR-0023 exactly.

## Unresolved limitations

None known. The webhook-to-widget leg (§2 finding: 3-second active poll interval already within
target, no change needed) was left unmodified as planned and is unaffected by this milestone.

## Independent (Vlad) acceptance

Not applicable — per ADR-0011, milestones M00 through M09 do not require a separate Vlad acceptance
session. Per this milestone's own correction round, that carve-out removes the *session*, not the
need for empirical evidence of the specific latency requirement — which the manual dev-bot check
above independently supplies, in addition to the frozen plan, code review, automated validation, and
green CI.

## Final status

**PASS**, pending Product Owner acceptance below.

## Product Owner acceptance

**Pending.** Awaiting Product Owner review of this closure record, including the manual latency
evidence above, before final sign-off.

- Name:
- Date:
- Conditions attached:

## Related pending acceptance

M06.1's own Product Owner acceptance (a real Bots-tab wizard walkthrough) remains separately pending,
per its own closure record (`docs/closure/m06-1-bot-setup-wizard-closure.md`) — unrelated to, and not
blocking, this milestone's closure.

## M07 status

M07 (operator workflow) remains unstarted. Nothing in this milestone touches
`ConversationRepository::assign()`, the `resolved` status transition, or any operator-facing surface.

## Deployment/configuration confirmation

No release, tag, or deployment action was taken — the plugin directory is bind-mounted directly from
this repository's `main` into the live dev WordPress container, so merging this PR is the only
"deployment" that occurred. No new Telegram bot, token, webhook secret, or group permission was
created or changed; the manual latency check (above) used only the already-configured dev bot and
support group, read-only beyond the two authorized live actions themselves.

## Addendum — corrective v2: claim-protected immediate delivery (ADR-0023 amendment)

Live testing on `dev.biopentra.eu` after this milestone's original merge (`3f2f50e`) found the
manual dev-bot latency check above did not generalize: a real chat message on the busy,
multi-plugin, real-world install sat **33 seconds** before its outbound send even began, because
`Queue\ExpeditedDispatchTrigger`'s fire-and-forget loopback nudge and normal cron cadence both
depend on the same contended, shared Action Scheduler batch slot. A widget bug independently minted
a fresh idempotency key/secret pair on every retry, and the server checked rate limits before the
idempotency-replay lookup, exhausting the original 5/day per-IP start bucket within minutes and
surfacing as an undifferentiated `{"ok":false}`. This addendum records the corrective pass that
fixes both, delivered on branch `fix/m06-2-interactive-delivery-v2`
([PR #17](https://github.com/magpern/universal-telegram/pull/17), merged via merge commit
`6726c87`).

### Root causes (recap, verified before the corrective plan was frozen)

1. **Latency.** `ExpeditedDispatchTrigger` alone is not a reliable *primary* latency mechanism on a
   busy, shared-queue install — verified directly (33s gap between message persistence and outbound
   send start, on `main` at the correct commit; Cloudflare/WAF loopback blocking directly ruled out
   by a same-host test).
2. **Double-send exposure latent in the original design** (not yet triggered in production, but
   real): `OutboundMessageRepository::mark_sending()` was an unconditional `UPDATE`, and
   `ConversationRepository::try_begin_topic_creation()`'s `none → pending` compare-and-set had no
   expiry — a caller that won either guard and then crashed mid-flight left the state unrecoverable
   except by the original job's own bounded retry count.
3. **Widget retry bug.** `ensureStarted()`'s own key/secret reuse logic was already correct, but
   `handle_start()` checked rate limits *before* the idempotency-replay lookup, so any retry against
   a still-pending start (network blip, `5xx`, `429`) consumed a fresh rate-limit token regardless.
4. **No machine-readable failure signal.** Every response was `{"ok": bool}` only.

### Corrective architecture (M06.2 corrective plan v2, ADR-0023 amendment)

- **Claim-protected delivery** (`db_version` 13 → 14): `outbound_messages.claim_expires_at` and
  `conversations.topic_claim_expires_at`, both nullable `DATETIME`. `OutboundMessageRepository::try_claim_for_sending()`
  and `ConversationRepository::try_begin_topic_creation()`'s lease-reclaim extension ensure at most
  one caller is ever active on a row at once, with automatic crash recovery via lease expiry — a
  non-owning queue worker self-reschedules just after the observed lease's expiry rather than
  dropping the job. **The delivery guarantee is honestly at-least-once, not exactly-once**: a
  duplicate remains possible only if a claimant crashes after Telegram accepts the call but before
  the local terminal-state write commits — rare, accepted, and not otherwise detectable.
- **`try_once(): Queue\AttemptOutcome`** extracted from both `SendMessageHandler` and
  `TopicCreationHandler`'s `handle_job()` cores, so the exact same claim-protected delivery logic is
  shared between the durable queue worker and the new in-process attempt layers below.
- **`Conversations\ImmediateDeliveryAttempt`** (new primary mechanism, replacing the direct
  `ExpeditedDispatchTrigger::trigger()` call): a 4-second budgeted, claim-protected synchronous
  attempt, wired into `ConversationsController::handle_post_message()` immediately after the
  existing durable enqueue calls. Every Telegram API call's own timeout is capped by the attempt's
  *remaining* wall-clock budget, never a fixed value, so a first message's two-call sequence (topic
  creation, then send) can never exceed its stated budget.
- **`Conversations\PromptDeliveryFallback`** (new, §3.3): on a `PENDING` outcome, up to two further
  bounded sub-attempts within a 5-second ceiling, entirely in-process — never touching Action
  Scheduler's shared batch slot, and deliberately **not** using `fastcgi_finish_request()` or any
  other early-response trick inside the REST callback (a `WP_REST_Server` callback returns a
  response object that WordPress itself serializes and emits afterward, so nothing can reliably
  flush the JSON body early from inside it). `ExpeditedDispatchTrigger` is retained, unchanged, but
  demoted to this fallback's own final branch.
- **Rate-limit/idempotency correction:** `handle_start()` now checks start-idempotency replay, and
  verifies its secret, *before* any new-conversation rate-limit token is consumed. The per-IP
  new-start limit is split into hourly (`conv_start_ip_h`, 10/hour) and daily (`conv_start_ip_d`,
  50/day) buckets; a new, independent per-IP auth-failure bucket (`conv_auth_fail`, 20/hour) is
  consumed only on a genuine secret mismatch against a valid replay key.
- **Response contract:** an optional, fixed-value `reason` field (`rate_limited` |
  `conversation_expired` | `request_failed` | `temporary_delivery_pending`) and a `delivery`
  field (`delivered` | `pending`) on the message-post response; `delivery_state` on each polled
  message. ADR-0021's uniform-404 guarantee is preserved exactly — `conversation_expired` is
  attached identically to every `controlled_not_found()` 404 regardless of cause.
- **Widget fix:** pending-start/idempotency-key retention across network/5xx/429 failures was
  already correct in the shipped JS; the missing piece was a distinct `rate-limited` UI state for
  `429` (instead of the generic `transient-failure`) and a distinct `pending` state for a `200`
  response carrying `delivery: 'pending'`.

### Implementation commits (branch `fix/m06-2-interactive-delivery-v2`)

- `5475188` — docs(m06-2-v2): freeze corrective plan v2 and ADR-0023 amendment
- `7a061bc` — feat(m06-2-v2): WP1 — claim/lease schema and repository CAS methods
- `aaa5261` — feat(m06-2-v2): WP2 — extract try_once() in both delivery handlers
- `6c453ae` — feat(m06-2-v2): WP3-WP5 — bounded immediate delivery, fallback, response contract
- `ae5256a` — feat(m06-2-v2): WP7 — widget 429/pending distinct UI states
- `0ed3bac` — chore(m06-2-v2): WP8 — version bump 0.6.1 -> 0.6.2 and doc updates
- `15ce469` — style(m06-2-v2): lean gate repair — phpcbf formatting, drop unused property

### Database/version transition

`db_version` `13 → 14` (one additive migration step, `step_14_add_claim_lease_columns`, two nullable
lease columns, no backfill). Plugin version `0.6.1 → 0.6.2` — a patch bump, per
`docs/ARCHITECTURE.md`'s own `db_version`/SemVer independence convention: this remains a reliability
correction to an already-shipped capability, not a new one.

### Lean validation and CI evidence (PR #17)

- PHPCS, scoped to every changed file: clean (0 errors after one `phpcbf` repair round).
- PHPStan (level 5): `[OK] No errors` (after removing `ConversationsController`'s now-unused
  `ExpeditedDispatchTrigger` constructor property — `PromptDeliveryFallback` owns its own reference
  instead).
- Unit suite: 173/173.
- Integration suite: 460/460 (full local run, not merely changed-scope).
- JS behavioural suite: 41/41, including new coverage for pending-key reuse under `429`, the
  `rate-limited` state, and the `pending` delivery state.
- ZIP build + package-acceptance script: **PASS** on a real WordPress 7.1/PHP 8.3 install —
  `db_version=14` and both new lease columns confirmed present after activation.
- GitHub Actions full matrix on PR #17 (merge commit `6726c87`): `build`,
  `integration-wc-present-current`, `integration-wp-only-current`, `integration-wp-only-floor`,
  `js-behavioural`, `package-acceptance` (6.9/8.1, 7.1/8.3, 7.1/8.3/WC 11.0.1), `phpcs`,
  `static-analysis`, `unit` (8.1, 8.3, 8.4) — all **pass**.

### Final `main` SHA (after PR #17)

`6726c87` (verified `main == origin/main`, clean working tree, immediately after merge).

### Real browser acceptance

Authorized after merge, using only the already-configured development bot and support group,
performed via the actual browser widget (not WP-CLI, reflection, curl, or direct REST) — see the
Product Owner acceptance section below for status and evidence.

## Product Owner acceptance (corrective v2)

**Pending.** Real-browser acceptance evidence per the frozen corrective plan's §7 (first message
within 5 seconds including topic creation; five subsequent messages each within 2 seconds; ambient
Action Scheduler workload present; no message loss or duplicate on the exercised normal path) has
not yet been recorded in this session — no interactive browser session is available in this
environment. This corrective pass's own technical work (freeze, implementation, lean validation, PR,
green CI, merge) is complete and closed technically; Product Owner acceptance of *this addendum*
remains open pending that live-browser evidence, separately from M06.2's own original Product Owner
acceptance above (also still pending).

- Name:
- Date:
- Conditions attached: real-browser acceptance evidence (§7 of the corrective plan) still required.
