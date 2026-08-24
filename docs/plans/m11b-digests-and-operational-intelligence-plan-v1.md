# M11B — Digests and Operational Intelligence (Remainder) — Implementation Plan v1

Status: Frozen — implementation authorized once M09 has Product Owner acceptance (ADR-0029,
M11's charter). Implements the remainder of M11's charter
(`docs/milestones/m11-digests-and-operational-intelligence.md`), complementing
M11A (ADR-0029, `docs/plans/m11a-visitor-activity-digests-plan-v1.md`,
frozen/implementation-complete but unvalidated and unmerged on
`feature/m11a-visitor-activity-digests`).

## 0. Verified baseline

- `feature/m11a-visitor-activity-digests` HEAD = `ff18ebae3f1f243b26db2afdf95818360365ef27` — matches `origin/feature/m11a-visitor-activity-digests` exactly; working tree clean (`git status --short` empty; `git fetch` + `git rev-parse` both refs identical).
- `origin/main` = `7011163fc645a0f8a9083f7eaa82530192215b16` (M09's baseline, post-hotfix-PR#25).
- On the M11A branch: plugin version `0.12.0`, `db_version` target `24` (`Migrator::target_version()`), M11A's tables/classes (`DigestEligibility`, `VisitorDigestSweep`, `VisitorDigestCounterRepository`, `VisitorDigestStateRepository`, `VisitorDigestRenderer`, `VisitorDigestAggregator`) exist under `src/Automations/Digest/`. This plan is written against that branch's actual code, not against the M11A plan document alone.
- M09 is technically PASS; Product Owner acceptance is PENDING (`docs/closure/m09-ai-draft-assistant-closure.md`). Per M11's charter and ADR-0029, M11B may not begin implementation until M09 acceptance lands, and M11A+M11B validate together in one combined gate.

## 1. M11A/M11B boundary

**Unchanged, reused as-is:** `DigestEligibility::is_active()`/`SUPPRESSED_EVENT_TYPES`, the `RuleEvaluator` suppression guard, the visitor-digest counters/state tables, `VisitorDigestSweep`, the `visitor_digest_*` Settings fields, the digest-target destination-eligibility rule (§4 of the M11A plan — reused verbatim as **the** eligibility rule for every M11B destination-selecting feature). M11B does not touch `src/Automations/Digest/*.php` or the visitor-digest schema.

**M11B adds**, all under `Automations\Intelligence\*` (a new subdomain of the existing `Automations` boundary — ADR-0005 already assigns "digests and operational intelligence" to `Automations`, so no boundary ADR is needed):
1. A scheduled **Operational Summary** (deterministic, daily) covering order/error/funnel aggregates beyond the visitor digest's scope.
2. **Threshold alerts** — a fixed, administrator-enabled/disabled catalogue of deterministic conditions (checkout-failure count, error-category spike, order-failure count), each independently configurable, default disabled.
3. **Checkout-failure detection** — deterministic, from `woocommerce.checkout_validation_failed` and `woocommerce.order_failed`/`order_cancelled` counts only.
4. **Funnel summary** — deterministic aggregate counts (`product_viewed → add_to_cart_intent → checkout_started_intent → order_created`) per fixed time window, no per-visitor linkage.
5. **JS-error clustering** — deterministic counts grouped only by M04's bounded `payload.error_category` enum (`runtime|promise_rejection|resource_load`), never raw error text.
6. **AI-assisted internal summary** — an operator-triggered, reviewable AI rendering of one Operational Summary's already-computed aggregate fields, reusing M09's provider abstraction, disabled until M09's AI is enabled.
7. **Destination-specific reporting configuration** — each report/alert independently targets a bot+destination pair validated by the reused M11A eligibility rule; no shared default target is assumed.

**No duplicate delivery:** M11B's own suppression additions (none — see §2) never touch the seven M11A-suppressed event types. M11B's new deterministic sends and M11A's visitor digest are independent Telegram messages, on independent schedules, never merged into one send or gated on each other's cadence — an administrator may enable any subset. `DigestEligibility::is_active()` governs only the M11A visitor digest; it is not consulted by, or extended for, any M11B feature.

## 2. Report/alert catalogue

All new tables under `Automations\Intelligence\*`; no existing table altered.

### 2.1 Operational Summary (scheduled, deterministic)
- **Source events:** `woocommerce.order_created`, `woocommerce.payment_completed`, `woocommerce.order_failed`, `woocommerce.order_cancelled`, `woocommerce.checkout_validation_failed` (WC required for all of these); `visitor.javascript_error` (`payload.error_category` only, WC not required); funnel counts (§2.3). `woocommerce.order_status_changed` is **not** a source — no aggregate column in §4's schema derives from it, and it is not listed to avoid implying an unused dependency.
- **Aggregate fields:** `orders_created`, `payments_completed` (a raw event count of `woocommerce.payment_completed` occurrences — not an order-status derivation, not a monetary figure), `orders_failed`, `orders_cancelled` — four independent integer event counts, never a monetary sum and never a per-currency breakdown; checkout-validation-failure count; JS-error counts by category; funnel stage counts.
- **Fixed window:** rolling 24 hours, evaluated once daily.
- **Trigger/schedule:** new recurring action `operational_summary_sweep`, registered like `VisitorDigestSweep::JOB_TYPE` (idempotent guard), internal 60s tick (matching existing sweep cadence) but only *acts* once per UTC day at a configurable hour (`operational_summary_hour_utc`, default `6`, clamp `0–23`). Before aggregating or sending, each acting tick first creates-or-returns the row for `summary_date = CURDATE()` (UTC) via `INSERT INTO operational_summary_runs (summary_date, window_started_at, window_ended_at, created_at) VALUES (...) ON DUPLICATE KEY UPDATE summary_date = summary_date` — the identical idempotent-open idiom M11A's own aggregator already uses for `window_started_at` (M11A plan §5) — so a crash or a retried tick for the same UTC day always resolves to the same row, structurally, via `summary_date`'s own `UNIQUE` constraint (§4), never creating a second "daily" row. Send-completion is tracked separately, via that same row's own `sent_at`/`send_status` columns, not a separate state-row timestamp (§4/§6 distinguish row-creation idempotency, structural via `UNIQUE(summary_date)`, from send idempotency, at-least-once via the state row's claim lease).
- **Destination:** independently configured `operational_summary_bot_id`/`operational_summary_destination_id`, validated by the reused M11A eligibility rule (§4 of the M11A plan).
- **Rate-limit/coalescing:** at most one row, and at most one send, per UTC day — structurally enforced two ways: row creation is `UNIQUE(summary_date)`-gated (above), and send-completion is gated on that same row's own `sent_at`/`send_status` columns (§4) rather than a separate state-row timestamp, so the two guarantees can never drift apart.
- **WC-absent behavior:** `orders_created`/`payments_completed`/`orders_failed`/`orders_cancelled`/checkout-failure-count lines omitted entirely (not zero-valued), matching M11A's own §6 precedent; JS-error and funnel lines render regardless (funnel lines omit WC-only stages).
- **Privacy:** PUBLIC-only aggregate integers/enums, no order ID, customer, or monetary line item ever included.
- **Deterministic.**

### 2.2 Threshold alerts (scheduled evaluation, deterministic)
Fixed catalogue, each independently toggleable, all **default disabled**:

| Alert | Condition | Source | WC required |
|---|---|---|---|
| `checkout_failure_count` | ≥N checkout-validation-failures in a rolling 1-hour window (N configurable, clamp 3–100, default 10) | `woocommerce.checkout_validation_failed` | Yes |
| `order_failure_spike` | ≥N `order_failed` events in a rolling 1-hour window (clamp 3–100, default 10) | `woocommerce.order_failed` | Yes |
| `js_error_spike` | ≥N JS-error events of one `error_category` in a rolling 1-hour window (clamp 5–500, default 50) | `visitor.javascript_error` | No |

- **Trigger/schedule:** evaluated on the same `operational_summary_sweep` 60s tick (no second recurring action) — bounded `COUNT` queries against `event_history` by rolling window and event type, same query shape as M11A's counter-sum.
- **Destination:** shared `alert_bot_id`/`alert_destination_id` fields (one pair for all three alerts, bounding settings surface), reused eligibility rule.
- **Rate-limit/coalescing:** each alert type has its own state row with `last_fired_at` and a fixed 1-hour re-fire cooldown regardless of continued condition — the structural anti-flood guarantee. A firing message states count+window; never fires per-event.
- **WC-absent behavior:** `checkout_failure_count`/`order_failure_spike` are structurally inert (source events cannot occur) — settings UI marks them "unavailable — WooCommerce not active."
- **Deterministic.**

### 2.3 Checkout-failure detection
Not a separate feature — it **is** `checkout_failure_count` (§2.2) plus a line in the Operational Summary (§2.1). No new event source; reuses `woocommerce.checkout_validation_failed`/`order_failed`/`order_cancelled` exactly as already emitted (ADR-0018/M03).

### 2.4 Funnel summary
- **Source events:** `visitor.product_viewed` → `visitor.add_to_cart_intent` → `visitor.checkout_started_intent` → `woocommerce.order_created` (the first three already WC-gated at registration per M11A §3; the last requires WC).
- **Aggregate fields:** one integer count per stage for the fixed window — independent per-stage totals, never a conversion rate, never a unique-visitor claim.
- **Fixed window:** same rolling 24 hours; rendered as a section of the Operational Summary, not a separate message.
- **Trigger/schedule:** computed within `operational_summary_sweep`.
- **Destination:** same as Operational Summary — a section, not independently targetable.
- **Rate-limit/coalescing:** inherits the summary's once-daily cap.
- **WC-absent behavior:** only the three visitor-stage counts render; `order_created` stage omitted.
- **Privacy:** integers only; each stage count is an independent `COUNT(*) FROM event_history WHERE event_type = ? AND created_at >= ?`, never a cross-event join, cookie, or session ID.
- **Deterministic.**

### 2.5 JS-error clustering
- **Source events:** `visitor.javascript_error`, `payload.error_category` only (PUBLIC-classified, bounded to `runtime|promise_rejection|resource_load` — `IngestRequestValidator`'s `error_category_enum` case).
- **Aggregate fields:** one count per category for the window. No text, stack, filename, URL, or hash — those fields do not exist on this event type (structurally absent per ADR-0019 §4/`VisitorEventCatalog.php:86`).
- **Fixed window:** same 24h, rendered as a summary section; also feeds `js_error_spike` (§2.2) on its own 1-hour window.
- **Trigger/schedule:** computed within `operational_summary_sweep`.
- **Destination:** same as Operational Summary.
- **WC-absent behavior:** unaffected.
- **Privacy:** category-only, PUBLIC. Does not touch M11A §3.3 at all — `visitor.javascript_error` stays excluded from digest suppression and its direct per-rule alerting is untouched; M11B only adds a category-count aggregate, never routes it through `SUPPRESSED_EVENT_TYPES`.
- **Deterministic** (a `GROUP BY error_category`, not an AI operation).

### 2.6 AI-assisted internal summary
- **Source:** the most recently computed Operational Summary row (§4 `operational_summary_runs` table) — never a live query, never conversation content, never raw events.
- **Aggregate fields:** exactly the same integers/enums already rendered in the deterministic Operational Summary message — no additional field, no join.
- **Trigger:** operator-initiated only, from a new "Summarize with AI" button on the Automations "Intelligence" panel (§5), against the latest completed summary row. Never scheduled, never automatic.
- **Destination:** never auto-sent to Telegram (§3). Rendered only in wp-admin.
- **Rate-limit/coalescing:** exactly one AI-summary draft row ever exists per summary row, enforced by a database `UNIQUE(summary_run_id)` constraint (§4), not a row lock — a repeat "Summarize with AI" request for the same summary row always returns the existing draft (whatever its status, including `discarded`), never inserts a second row and never triggers a second provider call. Discarding hides the draft from the review panel but does not free the unique slot for a fresh generation of the same summary row.
- **WC-absent behavior:** summarizes whatever fields the deterministic summary actually populated (WC-absent summaries simply have fewer input fields; the AI boundary never knows or cares why).
- **Both deterministic and AI-assisted:** the underlying data is deterministic (§2.1); only the natural-language rendering is AI-assisted.

### 2.7 Destination-specific reporting configuration
Every report/alert destination field in §2.1–§2.2 is an independent bot+destination pair (or, for funnel/error-clustering, inherits the Operational Summary's pair since they're sections of one message), each re-validated live by the reused M11A eligibility rule — which already, structurally, excludes every conversation-linked destination (`ConversationRepository::destination_ids_for_bot()`, M11A plan §4). No new exclusion logic is needed; M11B settings dropdowns reuse the identical filtered-population query M11A's `VisitorTrackingPage` fieldset already uses.

## 3. AI summary design

- **M09 reuse:** `AI\Provider\AiProviderInterface`/`OpenAiAdapter`/`AiFailureClassifier`; the shared `'ai_provider'` `CircuitBreaker` scope (both features hit the same OpenAI account, so sharing the scope is correct); and — the load-bearing reuse — M09's **existing** site-wide 2-slot concurrency admission mutex (the migration-seeded `universal_telegram_ai_config` row, `id=1`, `SELECT ... FOR UPDATE`), extended, not duplicated: M11B does not introduce a second, independent concurrency mutex. `PromptBuilder`'s `<source>`-delimited-data-not-instruction pattern is followed but not reused directly — a new `Automations\Intelligence\OperationalSummaryPromptBuilder` class, since the source content is aggregate counts, not WordPress excerpts, and sharing the class would conflate two unrelated data shapes.
- **New abstraction required:** `Automations\Intelligence\SummaryAiRequestHandler` (operator-request endpoint; idempotency is the database `UNIQUE(summary_run_id)` constraint on `operational_summary_ai_drafts`, §4/§2.6 — no row lock needed for this part) and `SummaryAiGenerationHandler` (queue worker, mirrors `AIDraftGenerationHandler`'s claim/lease/circuit-check tree).
- **Shared concurrency admission, designed to need no change to M09's frozen structural allow-list.** M09's `AiDraftRepository::claim_for_generation()` currently performs the config-row lock, the active-count read, the candidate-row select, and the claim update all inside one self-contained method/transaction — correct for M09 alone, but not something a second table's count can be summed into from outside without either duplicating locking logic or reaching across the boundary. This plan splits that already-shipped (merged, unreleased) method additively, the same "extend an existing repository's own write path without weakening its original guarantee" pattern M11A already used on `BotProfileRepository`/`DestinationRepository` (M11A plan §3.1/§9 WP1): `AiDraftRepository` gains two new public methods, `count_active_generating(): int` (the existing count query, extracted unchanged) and `claim_candidate_row( string $draft_uuid, int $lease_seconds ): ?array` (the existing candidate-select-and-claim logic, extracted unchanged, now assuming its caller already holds the outer lock/transaction rather than opening its own). The monolithic `claim_for_generation()` is removed — nothing outside `AIDraftGenerationHandler` called it. Neither new method's cap value (2), lease duration, or compare-and-set behavior changes for M09-only traffic.
  A new `AI\Provider\ProviderConcurrencyGate` class opens the transaction, locks the `ai_config` row (`id=1`, `SELECT ... FOR UPDATE`), sums a caller-supplied set of active-count `callable`s against the cap, and — if admitted — invokes a caller-supplied claim `callable`, then commits. **The gate's own file never imports, type-hints, or instantiates `AiDraftRepository` or `SummaryAiRepository`** — it accepts only plain `callable`/`int` values, so the static structural scan (which flags files by `use`/instantiation of the guarded class) finds nothing in `ProviderConcurrencyGate.php` to flag. Each domain's own repository reference stays exactly where it already legitimately lives: `AIDraftGenerationHandler` (already permitted) supplies `fn() => $ai_draft_repository->count_active_generating()` and `fn() => $ai_draft_repository->claim_candidate_row(...)`; `SummaryAiGenerationHandler` supplies the equivalent pair from its own `SummaryAiRepository`. `Core\Plugin.php` — the one documented, pre-existing exemption from every structural allow-list, since it "necessarily wires every service in the plugin" — constructs the one shared `ProviderConcurrencyGate` instance and hands each handler its own closures at composition time; no other class learns about both repositories at once. Net effect: `AiDraftRepository`'s existing six-class allow-list needs **zero entries added**. M09's cap of 2 is unchanged in value and now genuinely site-wide across both features, not per-feature.
- **Provider-disabled/circuit-open/timeout/queue-failure behavior:** identical decision tree to M09 — provider disabled or circuit-open → immediate `failed`, no call; timeout/5xx → `RETRYABLE` under the existing 5-attempt `RetryPolicy`; queue failure → same lease/sweep recovery as M09, via a new `operational_summary_ai_lease_sweep` (60s), mirroring `AiDraftLeaseSweep` exactly.
- **Draft lifecycle:** `queued → generating → generated → reviewed/discarded`, `failed` terminal — identical semantics to M09's state machine, on a new `operational_summary_ai_drafts` table (§4), never mixed into `ai_drafts` (different retention and grounding rule — the "source" here is always the plugin's own computed aggregate row, never approved WordPress content). Exactly one row per `summary_run_id` (§2.6/§4); the lifecycle governs that one row's status transitions, it does not permit a second row for the same summary.
- **Concurrency/retry/lease:** admission governed by the shared `ProviderConcurrencyGate` above (M09's existing cap of 2, now shared); 90s lease (M09's precedent), same compare-and-set completion guard.
- **Retention:** 30-day purge, no owning conversation to anchor a 90-day pass — administration-only artifacts on their own independent schedule. Retention deletes the row (including `body_ciphertext`) entirely at 30 days; it never deletes or nulls the owning `operational_summary_runs` row it points to (§4/account-deletion below).
- **Review/approval/discard:** displayed in wp-admin only; `reviewed` on operator open, `discarded` on explicit action. No `approved`-triggers-send transition exists — no code path in `Automations\Intelligence\*` ever calls `MessageDispatcher::send()` with AI-generated content; the deterministic summary (§2.1) is the only thing ever auto-sent.
- **No-auto-send guarantee, structural:** a new `StructuralBoundariesTest` assertion (extending M09 §6's pattern) asserts the AI-summary repository is referenced only by the fixed five-class set `SummaryAiRequestHandler`/`SummaryAiGenerationHandler`/`SummaryAiLeaseSweep`/`SummaryAiRepository` plus one `Administration\Automations\IntelligencePanel` review class, and — the M11B-specific addition — a static zero-reference assertion that `MessageDispatcher` never appears in `SummaryAiRequestHandler.php`/`SummaryAiGenerationHandler.php`/`SummaryAiLeaseSweep.php`.
- **No sensitive/raw data structurally:** the prompt builder's signature takes only the typed `operational_summary_runs` row object (§4) — never a string, arbitrary array, or event/order object — so raw event data cannot enter a prompt even by mistake, mirroring ADR-0028 decision 2's structural pattern.
- **`body_ciphertext` is not a public/aggregate field.** It is encrypted, variable-length AI-generated natural-language output (`CredentialVault::encrypt()`, matching `ai_drafts.body_ciphertext`'s own precedent) — not an integer, enum, or fixed-vocabulary column. It is never decrypted outside the review panel's own request, never included in a Diagnostics value, an audit-log entry, a Telegram message, or a prompt to the provider (it is the provider's *output*, never re-fed as input).

## 4. Data model and migration plan

Four new additive tables, `Migrator` steps 25–28, `db_version` 24 → 28. `utf8mb4_unicode_520_ci`, InnoDB, no FKs (matches every existing table).

**Step 25 — `universal_telegram_operational_summary_runs`** (one row per UTC calendar day):
```
id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
summary_date        DATE NOT NULL UNIQUE
window_started_at   DATETIME NOT NULL
window_ended_at     DATETIME NOT NULL
orders_created      INT UNSIGNED NOT NULL DEFAULT 0
payments_completed  INT UNSIGNED NOT NULL DEFAULT 0
orders_failed       INT UNSIGNED NOT NULL DEFAULT 0
orders_cancelled    INT UNSIGNED NOT NULL DEFAULT 0
checkout_failures   INT UNSIGNED NOT NULL DEFAULT 0
js_error_runtime    INT UNSIGNED NOT NULL DEFAULT 0
js_error_promise    INT UNSIGNED NOT NULL DEFAULT 0
js_error_resource   INT UNSIGNED NOT NULL DEFAULT 0
funnel_product_views    INT UNSIGNED NOT NULL DEFAULT 0
funnel_cart_intents     INT UNSIGNED NOT NULL DEFAULT 0
funnel_checkout_starts  INT UNSIGNED NOT NULL DEFAULT 0
funnel_orders_created   INT UNSIGNED NOT NULL DEFAULT 0
woocommerce_active_at_run TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
sent_at             DATETIME NULL
send_status         VARCHAR(32) NULL   -- sent | send_failed | skipped_invalid_target
created_at          DATETIME NOT NULL
```
`summary_date`'s own `UNIQUE` constraint is the sole row-creation-idempotency mechanism (§2.1) — structurally, not just by application discipline, at most one row can ever exist for a given UTC day, independent of how many sweep ticks, retries, or crash-recoveries touch that day. Retention: purged after 90 days (fixed, no setting — matches M11A precedent of no new retention-setting surface). Uninstall drops the table unconditionally.

**Step 26 — `universal_telegram_operational_alert_state`** (one row per fixed alert type):
```
alert_type          VARCHAR(32) NOT NULL PRIMARY KEY   -- checkout_failure_count | order_failure_spike | js_error_spike
last_fired_at        DATETIME NULL
last_evaluated_at    DATETIME NULL
```
Seeded with the three fixed rows during migration (same "singleton-row(s) as checkpoint" pattern M11A step 24 established). Retention: not purged (bounded, fixed cardinality — three rows forever). Uninstall drops the table.

**Step 27 — `universal_telegram_intelligence_settings_state`** (singleton, id=1, pure claim-lease mutex for the shared `operational_summary_sweep`'s send-handoff step — parallels `visitor_digest_state`'s role for `VisitorDigestSweep`; carries no "was it sent" timestamp of its own, since `operational_summary_runs.sent_at`/`send_status` on the per-day row, §step 25, is the single authoritative record of that — avoiding two fields that could drift):
```
id                    TINYINT UNSIGNED PRIMARY KEY (=1)
claim_token           VARCHAR(36) NULL
claim_expires_at      DATETIME NULL
```
Seeded with the one row at migration. Uninstall drops the table.

**Step 28 — `universal_telegram_operational_summary_ai_drafts`**:
```
id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
summary_run_id                BIGINT UNSIGNED NOT NULL, UNIQUE KEY uq_summary_run (summary_run_id)
draft_uuid                    CHAR(36) NOT NULL UNIQUE
status                        VARCHAR(16) NOT NULL, INDEX     -- queued|generating|generated|reviewed|discarded|failed
provider                      VARCHAR(32) NOT NULL
model                         VARCHAR(191) NOT NULL
prompt_policy_version         VARCHAR(32) NOT NULL
body_ciphertext                LONGTEXT NULL
failure_class                 VARCHAR(32) NULL
requested_by_user_id          BIGINT UNSIGNED NULL
reviewed_by_user_id           BIGINT UNSIGNED NULL
lease_token                   CHAR(36) NULL
generation_lease_expires_at   DATETIME NULL, INDEX (status, generation_lease_expires_at)
attempt_count                 INT UNSIGNED NOT NULL DEFAULT 0
created_at                    DATETIME NOT NULL
generated_at                  DATETIME NULL
updated_at                    DATETIME NOT NULL
```
`UNIQUE(summary_run_id)` is the entire idempotency mechanism for §2.6 — a database constraint, not an application-level row lock: a second insert attempt for the same `summary_run_id` fails at the database and `SummaryAiRequestHandler` catches that specific duplicate-key condition and returns the existing row instead, regardless of its current status (including `discarded`). Both `requested_by_user_id` and `reviewed_by_user_id` are nullable (matching M09's `ai_drafts.requested_by_user_id` nullable-widening precedent, ADR-0028/closure §Deviations 2, applied from creation this time rather than as a later corrective migration).

**Retention/account-deletion, exact:** 30-day purge deletes the entire row (§3), independent of account deletion. On operator account deletion (before the 30-day purge would otherwise run), the existing account-deletion routine is extended with one additional `UPDATE universal_telegram_operational_summary_ai_drafts SET requested_by_user_id = NULL WHERE requested_by_user_id = ?` and one for `reviewed_by_user_id`, mirroring `ai_drafts`' own two anonymization statements exactly — both are `UPDATE`s, never `DELETE`s, so `body_ciphertext`, `status`, and every other field are untouched, and the owning `operational_summary_runs` row is never touched by this path at all. Uninstall drops the table unconditionally.

**`db_version`: 24 → 28** (four additive steps, no existing table altered). No vague placeholders — every column above is exact.

## 5. Administration and diagnostics

New Hub sub-panel, **not a new top-level tab**: an "Intelligence" section composed into the existing **Rules** tab (`Administration\Automations\RuleBuilderPage`, `MANAGE_AUTOMATIONS`) — the natural existing home, since digests/alerts are already an `Automations` subdomain. Settings fields follow `Settings::defaults()`/`sanitize()` exactly like M11A's `visitor_digest_*` fields — no new settings system. Destination dropdowns reuse M11A's exact eligibility-filtered query via a shared helper (`DigestEligibility`'s eligibility logic factored into a reusable method in WP1, so M11A's dropdown and M11B's three new ones call one filter, not four copies).

- **Target eligibility/validation:** identical live-revalidated rule; a saved-but-invalid target surfaces `<feature>_paused_invalid_target` in Diagnostics and pauses only that feature — same recovery semantics as M11A §4.
- **Disabled/misconfigured/failure states:** each alert type and the summary independently show enabled/disabled, target valid/invalid, last-sent/fired timestamp, last status. WC-gated rows show "unavailable — WooCommerce not active" instead of a checkbox.
- **Capability/nonces:** `MANAGE_AUTOMATIONS` for the panel and alert toggles (matches Rules/Events/Simulator); `MANAGE` for the AI-summary button (matches M09's AI-adjacent precedent). Standard existing nonce pattern.
- **Operator language:** panel copy uses "Daily operations summary," "Checkout failure alert," "Error spike alert," "Funnel," never internal class/table names.
- **Diagnostics tab additions** (`DiagnosticsReport::generate()`, additive keys): `operational_summary_enabled`/`_target_valid`/`_last_status`; `operational_summary_last_sent_at` (read from the most recent `operational_summary_runs.sent_at`, not a separate state-row field — one source of truth, §4); `alert_{checkout_failure_count,order_failure_spike,js_error_spike}_enabled`/`_last_fired_at`; `ai_summary_last_status` (reuses M09's existing `ai_config`-derived provider-availability keys, not duplicated).

## 6. Reliability

- **Durable scheduling:** one recurring action, `operational_summary_sweep` (60s tick, acts once/day per §2.1), registered like `VisitorDigestSweep`/`AiDraftLeaseSweep` (idempotent guard). One AI-summary lease sweep, `operational_summary_ai_lease_sweep` (60s), mirroring `AiDraftLeaseSweep`. No bespoke cron.
- **Duplicate/crash semantics, stated honestly, in two separate parts:** (1) **row creation** for a given UTC day is structurally exactly-once, enforced by `summary_date`'s `UNIQUE` constraint (§2.1/§4) — a crash or retried tick can never produce a second row for the same day, full stop, not merely "unlikely." (2) **message send**, once a row exists, uses the same claim-lease pattern as M11A's window close and remains at-least-once, not exactly-once (a crash between handoff and the row's own `sent_at`/`send_status` update can, at worst, resend that day's summary before the next tick; bounded, disclosed, same posture as M11A §5/ADR-0028 decision 5). Alert firing is idempotent per hour via `last_fired_at` — a crash mid-fire can cause at most one duplicate message, never a flood.
- **Bounded queries:** every aggregate query is a `COUNT`/`GROUP BY` against `event_history` filtered by `event_type` and a bounded window (1h or 24h), same indexed columns M11A's own queries rely on.
- **Bounded message/prompt sizes:** deterministic messages are fixed-line-count and integer-bounded, same guarantee as M11A §6 (well under Telegram's 4096-char limit). AI-summary prompt input is a handful of bounded integers/enums plus a fixed template — no variable-length user content ever enters it (§3).
- **No front-end request delays:** all new sweeps run on Action Scheduler's background worker only.
- **Interaction with M11A's sweep and existing AS workload:** `operational_summary_sweep` is wholly independent from `VisitorDigestSweep` (different job type, table, state row) — no shared lock; both run on the same existing worker pool as `RetentionCleanupHandler`/`AiDraftLeaseSweep`/`VisitorDigestSweep` with no reported contention.

## 7. Testing and release evidence

Tests written with each WP, not run during implementation; one combined M11A+M11B focused local validation gate after all M11B WPs, only once M09 has Product Owner acceptance. GitHub Actions is the independent full-matrix validation, run against the same combined branch state.

| WP | Objective | Files | DB impact | Tests | Commit message |
|---|---|---|---|---|---|
| WP1 | Shared eligibility-rule extraction + Intelligence settings fields + Hub panel scaffold | `Automations/Digest/DigestEligibility.php` (extract static reusable eligibility-filter method, no behavior change to M11A), `Core/Configuration/Settings.php`, `Administration/Automations/RuleBuilderPage.php` (new Intelligence section), new `Automations/Intelligence/IntelligenceSettings.php` | none | `IntelligenceSettingsTest` (defaults/clamping); shared-eligibility-extraction regression test (M11A's own `DigestEligibilityTest` still passes unmodified) | `Add M11B Intelligence settings and shared destination-eligibility extraction` |
| WP2 | Operational Summary aggregation + sweep + deterministic render | `Persistence/Migrator.php` (steps 25, 27), new `Automations/Intelligence/{OperationalSummarySweep,OperationalSummaryRepository,OperationalSummaryRenderer}.php` | +2 tables, `db_version` 24→26 (interleave state table with runs table so the sweep's mutex exists before first use) | `OperationalSummarySweepTest`: once-daily cap, WC-absent omission, funnel/error sections, invalid-target pause; **duplicate-tick idempotency** (two overlapping/back-to-back ticks for the same UTC day, via the `INSERT ... ON DUPLICATE KEY UPDATE` idiom, assert exactly one `operational_summary_runs` row for that `summary_date`); **crash-recovery row idempotency** (simulate a crash after row creation but before send, force a retried tick, assert the retried tick reuses the same row — same `id`, no second insert — and only then proceeds to the claim-lease send-handoff, which keeps its own separate at-least-once send semantics, §6) | `Add operational summary aggregation, sweep, and deterministic rendering (db_version 26)` |
| WP3 | Threshold alerts (three fixed types) | `Persistence/Migrator.php` (step 26), new `Automations/Intelligence/{AlertEvaluator,AlertRepository}.php`, evaluation folded into `OperationalSummarySweep`'s tick | +1 table, `db_version` 26→27 | `AlertEvaluatorTest` per alert type (threshold trigger, 1-hour cooldown, WC-absent inert state, default-disabled) | `Add threshold alert catalogue with fixed 1-hour cooldown (db_version 27)` |
| WP4 | Funnel summary + JS-error clustering sections | `Automations/Intelligence/OperationalSummaryRepository.php` ext (funnel/cluster queries), `OperationalSummaryRenderer.php` ext | none (uses WP2 table's existing columns) | Funnel-count-independence test (no cross-join), error-category-only test (denylist: no raw text field ever queried) | `Add funnel summary and JS-error-category clustering to operational summary` |
| WP5 | AI-summary provider abstraction reuse + prompt builder | new `Automations/Intelligence/OperationalSummaryPromptBuilder.php`, `Persistence/Migrator.php` (step 28) | +1 table, `db_version` 27→28 | Prompt-input-typed-row-only test (cannot accept raw event data by construction), policy-version fixed-constant test | `Add AI-summary prompt builder and draft schema (db_version 28)` |
| WP6 | Shared provider-concurrency extraction + AI-summary request/generation handlers + lease sweep | `AI/Draft/AiDraftRepository.php` (additive split of `claim_for_generation()` into `count_active_generating()`/`claim_candidate_row()`, `claim_for_generation()` removed, no change to cap/lease/compare-and-set behavior), `AI/Draft/AIDraftGenerationHandler.php` (calls the gate + the two new repository methods instead of the removed monolithic method), new `AI/Provider/ProviderConcurrencyGate.php` (references no repository class — `callable`/`int` only), new `Automations/Intelligence/{SummaryAiRequestHandler,SummaryAiGenerationHandler,SummaryAiLeaseSweep,SummaryAiRepository}.php`, `Core/Plugin.php` (wires the one shared gate instance and both domains' closures) | none (uses WP5 table) | `AiDraftRepositoryTest` regression (M09's own existing claim/cap/lease/compare-and-set test suite still passes unmodified against the two new methods); `ProviderConcurrencyGateTest` (cross-feature race: concurrent M09 draft claims + M11B summary claims together never exceed 2 active; gate's own file asserted, via the same static-scan technique `StructuralBoundariesTest` uses, to contain zero references to `AiDraftRepository`/`SummaryAiRepository`); `UNIQUE(summary_run_id)` duplicate-request test (repeat request returns existing row, including after `discarded`, no second provider call); circuit-open/provider-disabled/timeout decision-tree tests (mirroring M09's); lease-expiry reclaim test; `'ai_provider'` shared-circuit-scope test | `Add shared provider-concurrency gate and AI-assisted operational summary generation` |
| WP7 | Operator review UI + structural no-auto-send guard | new `Administration/Automations/IntelligencePanel.php`, `StructuralBoundariesTest` ext | none | Structural allow-list test (`SummaryAiRepository` referenced only by the fixed five-class set), zero-`MessageDispatcher`-reference static assertion, capability/nonce test | `Add operational-intelligence review UI with structural no-auto-send guard` |
| WP8 | Diagnostics + audit integration | `Administration/Diagnostics/DiagnosticsReport.php`, settings-save audit call sites | none | Diagnostics-key test (all new keys; assert `body_ciphertext` never appears in any Diagnostics or audit value), audit classification test | `Add M11B diagnostics and settings audit coverage` |
| WP9 | Retention/uninstall/account-deletion/version bump/docs | `RetentionCleanupHandler` ext (90-day summary-run purge, 30-day AI-summary-draft purge), account-deletion routine ext (nulls `requested_by_user_id`/`reviewed_by_user_id` on `operational_summary_ai_drafts` only, never deletes the row or the owning summary), `Uninstaller.php`, `universal-telegram.php`, `readme.txt`, `docs/ARCHITECTURE.md` | none (assert `db_version` 28) | Retention-by-table tests, account-deletion-anonymizes-without-deleting test, uninstall-drops-four-tables test, full-suite privacy-denylist re-run | `Bump to {next version}, extend retention/account-deletion/uninstall, and document M11B` |

**Manual Product Owner checklist** (mock vs. live evidence kept separate): *mock evidence* — every automated test above, plus one local gate (phpcs → phpstan → unit → integration WP-only → integration WC-present → build → package-acceptance for `db_version` 28); *live evidence* (dev-only OpenAI credential + dev Telegram bot, separately authorized) — enable AI summary, confirm key masking; trigger "Summarize with AI," confirm the text never reaches Telegram; click "Summarize with AI" twice on the same summary and confirm only one provider call and one draft row exist; discard that draft and confirm re-clicking still returns the same (discarded) draft, not a new generation; force a checkout failure, confirm `checkout_failure_count` fires once then holds its 1-hour cooldown under continued failures; run WC-absent, confirm correct render/omit; drive an M09 draft request and an M11B summary request concurrently and confirm the combined active-generation count never exceeds 2; force circuit-open, confirm the AI-summary request defers with no HTTP call; confirm no conversation-linked destination is selectable in any new dropdown; delete an operator account with an existing AI-summary draft and confirm the draft row survives with both user-id fields nulled and its content untouched.

## 8. ADRs, documentation, versioning

**One ADR required** (ADR-0005's own criterion: a new persistence model and a new AI-boundary interaction point). No boundary ADR is needed (`Automations` already owns this subdomain per ADR-0005).

Full text: `docs/adr/0030-m11b-operational-summaries-threshold-alerts-and-operator-reviewed-ai-summarization.md`.

**Version recommendation:** branch is already `0.12.0`/`db_version 24`. M11B is a genuine new capability class — recommend **`0.12.0 → 0.13.0`** (minor), applied once for the combined M11A+M11B release (M11A is never independently released — below). `db_version`: `24 → 28`.

**M11A is not independently released or closed.** Per ADR-0029, M11A's own closure record documents technical completion only; milestone-level closure (acceptance, tag, changelog) happens once, for the combined M11 release, after the single combined gate (§9) passes. No `v0.12.0` tag is cut for M11A alone.

## 9. Combined-validation and release dependency statement

M11B implementation may not begin until (1) M09 has Product Owner acceptance, and (2) this plan and ADR-0030 have Master Architect review and Product Owner approval. Once WP1–WP9 are implemented, M11A and M11B validate together in **one** combined local gate plus one GitHub Actions run against the combined branch state — never two separate gates. Only after that gate passes, and Vlad's independent acceptance (mandatory from M10 onward per ADR-0011 — M11 is post-M10, unlike M09's exemption) is obtained, does M11 become eligible for Product Owner closure, citing ADR-0029, ADR-0030, and both work packages' evidence.

## Self-review

- M11A unweakened/undeduplicated: §1 — zero modification to M11A's suppression/aggregation/schema files; the one shared extraction (WP1) is mechanical, with a regression test proving M11A's own suite still passes unmodified.
- No sensitive data reaches AI or Telegram: §3/§8 decision 4/5 — AI prompt *input* is a typed aggregate-row object only; every new column is an integer, enum, or fixed-vocabulary string with the one stated exception (`body_ciphertext`, encrypted AI *output*, never fed back into a prompt and never surfaced outside the review panel).
- No conversation destination selectable: §2.7/§5 — every dropdown reuses M11A's exact eligibility-filtered query.
- No alert flood: §2.2/§6 — fixed 1-hour cooldown per alert type, independent of continued condition.
- WC-absent paths complete: every §2 entry states its WC-absent behavior.
- M10 untouched: nothing here references `Conversations\*` visitor-facing paths, `ChatWidget\*`, or any M10 concept.
- M09's site-wide provider cap unweakened, and its allow-list untouched: §3/§8 decision 4 — no second, independent concurrency mutex exists; the shared `ProviderConcurrencyGate` counts active generations across both `ai_drafts` and `operational_summary_ai_drafts` against M09's unchanged cap of 2, with a cross-feature race test (WP6); the gate itself references no repository class, so `AiDraftRepository`'s existing six-class allow-list needs zero new entries, verified by a static-scan test.
- Daily-summary row creation is structurally exactly-once: §2.1/§4 — `summary_date DATE NOT NULL UNIQUE` on `operational_summary_runs`, created via the same `INSERT ... ON DUPLICATE KEY UPDATE` idiom M11A already established, with duplicate-tick and crash-recovery tests (WP2) confirming one row per UTC day regardless of retries.
- Account-deletion correctness: §4 step 28 — both `requested_by_user_id`/`reviewed_by_user_id` are nullable from creation; account deletion nulls both via `UPDATE`, never deletes the draft row, `body_ciphertext`, or the owning summary row.
- M09 acceptance status stated accurately: §0/ADR-0030 Context — pending, not "available or imminently expected"; M11B implementation stays blocked until it is recorded.
- DB/version/ADR numbers consistent: `db_version` 24→28 stated identically in §4, §7, §8, ADR-0030's own Compatibility section; ADR-0030 is the next free number after ADR-0029.
- Work-package test traceability: every WP in §7 names files, DB impact, and tests, including the new cross-feature concurrency and account-deletion tests.
