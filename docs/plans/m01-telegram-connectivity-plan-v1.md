# M01 — Telegram Connectivity — Implementation Plan v1

Target materialization path: `docs/plans/m01-telegram-connectivity-plan-v1.md`
Target branch (future, not created by this planning task): `feature/m01-telegram-connectivity`
Repository: `magpern/universal-telegram`, checkout `/opt/biopentra/dev/universal-telegram`

This is a **planning document only**. No branch was created, no file in the
repository was modified, no dependency installed, no credential generated,
no Telegram API called, no commit/push/tag/release/deploy performed. All
repository inspection was read-only (`git status`, `git log`, `git fetch`,
`Read` of docs/source/test files). This document is drafted for Master
Architect and Product Owner review per `docs/governance.md` step 1 (Draft).

---

## Context

M00 (Product Foundation) closed **PASS** with Product Owner acceptance
("Magnus Pernemark — PASS") at commit `e5fa455` on `origin/main`, which is
also the current `HEAD`. The working tree is clean. M01 is the next
milestone in the v1.0 execution sequence (M00 → M01 → M02 → … → M07 → M12)
and depends only on M00, which is satisfied. M01's mission is to make
Telegram connectivity real: an administrator configures a bot, confirms
messages flow bidirectionally, before any event/rule/chat logic exists on
top of it. M02 (events/rules) depends on M01's transport being in place.

During drafting, the Product Owner resolved one load-bearing scope question
that could not be settled from repository evidence alone: **M01 must
support multiple Telegram bots per WordPress installation**, modeled as
generic, interchangeable bot profiles (no hard-coded "technical bot" vs
"customer bot" roles — later milestones assign functional roles). Each bot
profile owns its own credential, webhook identity, destinations, delivery
log, dead-letter records, rate limiter, and circuit breaker. The webhook
route identifies the bot via an opaque non-secret UUID; Telegram's
per-request secret header authenticates the request. This decision is
recorded as ADR-0012 (§14 below) and drives the schema and module design
throughout this plan.

---

## 1. Repository findings at plan-drafting time

- **Namespace root**: `UniversalTelegram\` maps to `src/` via a single
  blanket PSR-4 entry in `composer.json`. No per-boundary autoload entry is
  needed to add `UniversalTelegram\Telegram\*` or
  `UniversalTelegram\Administration\Telegram\*` classes — they are already
  covered.
- **Composition root**: `src/Core/Plugin.php` is a `final` singleton
  (`instance()` + idempotent `init()` guarded by `bool $booted`). Every
  M00 service is constructed by hand, in a fixed order, inside `init()`,
  with a public accessor per service. There is no DI container and no
  hook-based registration point for a new service — M01 services must be
  wired into this exact same method, in the same style, using the same
  singleton file (`src/Core/Plugin.php` will be modified, not replaced).
- **Structural boundary guard**: `tests/unit/Core/StructuralBoundariesTest.php`
  currently asserts that `Events`, `Automations`, `Telegram`, `Conversations`,
  `ChatWidget`, and `AI` directories are absent from `src/`. M01 is the
  first milestone authorized (per ADR-0005) to create `src/Telegram/`, and
  this test must be **updated, not deleted**, to permit `Telegram` while
  continuing to assert absence of the remaining five.
- **Queue contract** (`src/Queue/JobEnvelope.php`): the constructor enforces
  a fail-closed classification policy — every payload field must appear in
  an explicit `classification_map` and must be `PUBLIC` or `INTERNAL`;
  `SENSITIVE`/`SECRET` fields, or any unclassified field, throw
  `PayloadRejectedException` immediately. This is the single hardest
  constraint on M01's outbound design: **no bot token and no message text
  may ever appear in a queued job's payload.**
- **Credential vault** (`src/Core/Security/CredentialVault.php`): AES-256-GCM
  authenticated encryption; `encrypt(string $plaintext, string $context): string`,
  `decrypt(string $stored, string $context): CredentialResult`,
  `reencrypt(...)`. The `$context` string is used as AEAD additional
  authenticated data — a ciphertext only decrypts successfully under the
  exact same context string it was encrypted with. Three-tier fail-closed
  key resolution (explicit constant → all four WP auth salts → `wp_salt('auth')`),
  no fourth hardcoded fallback. `decrypt()` never throws and never erases
  ciphertext on failure; it returns a `CredentialResult` in one of three
  states (`AVAILABLE`, `INVALIDATED`, `UNAVAILABLE`). ADR-0008 names M01's
  Telegram bot token as this vault's first genuine consumer. This plan
  additionally uses the same vault for the per-bot webhook secret and for
  outbound message body content at rest — see §5 (ADR-0012) for rationale.
- **Retry policy** (`src/Queue/RetryPolicy.php`): entirely generic —
  `max_attempts()` = 5, exponential backoff `30·2^(n-1)` seconds capped at
  900s plus ≤20% jitter, deterministic clock/jitter seams for testing. It
  has no awareness of Telegram-specific failure semantics by design (per
  ADR-0006, this genericness is deliberate and must not be broken). M01
  must build Telegram-specific retry classification **on top of**, not
  inside, this class.
- **Worker execution** (`src/Queue/WorkerRunner.php`): a single callback,
  always registered on `WorkerRunner::HOOK` (`universal_telegram_run_job`)
  regardless of schema availability, checks `SchemaHealth::is_available()`
  first, looks up a handler in `HandlerRegistry` by `job_type`, invokes it,
  and **always rethrows** any exception after recording an audit entry and
  consulting `RetryPolicy`. A handler that does not throw is treated as a
  fully successful attempt — no partial-success signal exists. This shapes
  how M01 must signal "don't retry, this is permanent" vs "retry me" (see
  ADR-0014).
- **Dispatch** (`src/Queue/Dispatcher.php`): `enqueue(JobEnvelope): DispatchResult`
  never throws; checks schema availability first; treats a caught exception
  and a non-positive Action Scheduler action ID identically as failure.
- **QueueHealth** (`src/Queue/QueueHealth.php`): raw pending/failed counts
  scoped to Action Scheduler group `universal-telegram`
  (`WorkerRunner::GROUP`) — no thresholds or alerting exist yet; this is a
  read surface only, reused (not duplicated) by M01's queue-health alert.
- **Migration framework** (`src/Persistence/Migrator.php`,
  `MigrationLock.php`, `SchemaHealth.php`): numbered steps, raw DDL (never
  `dbDelta`), `CREATE TABLE IF NOT EXISTS`, information-schema-guarded
  column additions, a postcondition-verification method per step, an
  atomic-SQL lock (insert-with-unique-constraint, compare-and-swap reclaim,
  compare-and-swap release). `target_version()` currently returns `1` for
  the single M00 step (the audit log table). M01 adds new numbered steps to
  this **same class** and bumps `target_version()`.
- **Audit/Privacy** (`src/Audit/AuditLogger.php`, `src/Privacy/Classification.php`,
  `src/Privacy/Redactor.php`): `AuditLogger::record()` requires a mandatory,
  complete classification map on every call and redacts internally before
  persisting (`SECRET` stripped, `SENSITIVE` masked to `***`, anything
  unclassified silently dropped — fail-closed, not a caller responsibility).
  Only one table exists: `{prefix}universal_telegram_audit_log`, columns
  `id, occurred_at, actor_type, actor_id, action, context, privacy_classification`.
- **Capability model** (`src/Core/Capabilities/CapabilityRegistrar.php`):
  exactly one capability exists, `universal_telegram_manage`
  (`CapabilityRegistrar::MANAGE`), granted to `administrator` on activation,
  revoked from all roles unconditionally on uninstall. No M01 requirement
  implies a genuinely distinct authorization boundary (bot management is
  gated the same as diagnostics), so this plan reuses the existing
  capability rather than adding a new one — see §3 assumption A5.
- **WooCommerce presence** (`src/Integrations/WooCommerce/WooCommerceSupport.php`):
  `is_active(): bool`, the single canonical presence check. M01's transport
  has no WooCommerce dependency; this plan confirms it stays that way (no
  Telegram code calls into WooCommerce).
- **Diagnostics pattern** (`src/Administration/Diagnostics/DiagnosticsPage.php`,
  `DiagnosticsReport.php`, `SelfTest.php`): the concrete reference pattern
  this plan follows for admin surfaces — menu-capability gate +
  in-handler capability/nonce re-verification, `JobEnvelope` built with
  only `PUBLIC`/`INTERNAL` fields, `HandlerRegistry::register()` +
  `Dispatcher::enqueue()`, fixed/bounded synthetic inputs, no arbitrary
  execution.
- **Lifecycle** (`Activator`, `Deactivator`, `Uninstaller`): activation
  grants the capability only (schema provisioning is lazy, on the next
  `plugins_loaded`); deactivation is intentionally empty; uninstall
  unconditionally cancels all pending Action Scheduler actions in the
  plugin's group, then — only if `remove_data_on_uninstall` is `true` —
  drops the plugin's own tables and deletes its own options, using only
  Action Scheduler's public store API, never raw SQL against its tables.
- **CI/tooling** (`.github/workflows/ci.yml`, `bin/docker/*.sh`,
  `docker/docker-compose.yml`, `docker/Dockerfile`): everything runs inside
  a Docker `php` service (PHP 8.1/8.3/8.4 matrix for unit; WordPress
  6.9/7.1 for integration; WooCommerce 11.0.1 for the WC-present
  configuration) against a `mariadb:11.4` service. No host PHP/Composer
  installation is used or assumed anywhere. Jobs: `phpcs`, `static-analysis`
  (PHPStan level 5), `unit` (PHP matrix), `integration-wp-only-floor` (WP
  6.9/PHP 8.1), `integration-wp-only-current` (WP 7.1/PHP 8.3),
  `integration-wc-present-current` (WP 7.1/WC 11.0.1), `build`,
  `package-acceptance` (3-way matrix: WP-only floor, WP-only current,
  WC-present current). `phpunit.xml.dist`/`phpunit-integration.xml.dist`
  auto-discover `*Test.php` under `tests/unit`/`tests/integration` by
  directory suffix — **no per-file registration is needed**, so no CI job
  definition changes are required by this plan; new test files are picked
  up automatically.
- **Versioning** (`docs/ARCHITECTURE.md`): current version `0.0.1`
  (`UNIVERSAL_TELEGRAM_VERSION`); SemVer, pre-1.0 minor/patch may break;
  `1.0.0` reserved for the M00–M07 + M12 release. Schema version option
  `universal_telegram_db_version`, currently `1`.

---

## 2. Actual baseline and M00 dependency verification

Executed read-only, in order, in `/opt/biopentra/dev/universal-telegram`:

- `git status` → `On branch main. Your branch is up to date with
  'origin/main'. nothing to commit, working tree clean.`
- `git branch --show-current` → `main`
- `git fetch --all --tags --prune` → no output (already current, no tags exist)
- `git rev-parse HEAD` → `e5fa455d56bb8d93ebb0ba8b6e33122fd67db509`
- `git rev-parse origin/main` → `e5fa455d56bb8d93ebb0ba8b6e33122fd67db509` (identical — no divergence)
- `git log --oneline --decorate -20` → confirms linear history ending in
  `e5fa455 (HEAD -> main, origin/main, origin/HEAD) docs(m00): record
  Product Owner acceptance and close milestone`, preceded by
  `3548bc9 docs(m00): close product foundation milestone` and
  `ebbabda Merge pull request #1 from magpern/feature/m00-product-foundation`,
  which merges 12 individually-preserved WP commits plus a frozen-plan
  commit `704bd55` and a governance-baseline commit `89357cc`.
- `docs/milestones/README.md` registry: M00 row status = `Closed (PASS)`,
  M01 row status = `Not Started`, depends on `M00`.
- `docs/closure/m00-product-foundation-closure.md`: **Final status: PASS**;
  **Product Owner acceptance: Magnus Pernemark — PASS**; full automated
  test matrix (phpcs 64 files/0 errors, phpstan level 5/0 errors, unit 34
  tests/57 assertions, integration × 3 configurations all 38 tests/98
  assertions, package acceptance × 3 configurations all PASSED,
  `check-doc-links` clean) recorded as the closure evidence, consistent
  with ADR-0011 (no independent Vlad acceptance gate for M00–M09).

**Entry gate result: SATISFIED.**
- M00 is `Closed (PASS)` on current `origin/main` (`e5fa455`). ✅
- The M00 closure record contains explicit Product Owner acceptance. ✅
- The working tree is clean. ✅

Starting `main` SHA for this plan: **`e5fa455d56bb8d93ebb0ba8b6e33122fd67db509`**.
This plan assumes M01 implementation begins from this exact commit; if
`main` advances before the freeze commit, the plan-freeze step must record
the actual SHA implementation begins from.

---

## 3. Settled assumptions and decisions

Each assumption below is evidence-backed or Product-Owner-confirmed; genuine
open items are listed in §15, not here.

- **A1 — Bot cardinality (Product Owner decision, confirmed during
  drafting)**: M01 supports **multiple** Telegram bot profiles per
  installation. Bots are generic and interchangeable; M01 assigns no
  functional role to any bot. See ADR-0012.
- **A2 — Webhook routing**: each bot is addressed in its webhook URL by an
  opaque, non-secret UUID (`bot_uuid`), never by the bot token. Telegram's
  `secret_token`/`X-Telegram-Bot-Api-Secret-Token` header is the sole
  authentication mechanism for the inbound request. See ADR-0012, ADR-0013.
- **A3 — No conversation store in M01**: inbound updates are recorded as
  **metadata only** (chat ID, thread ID, update type, timestamps, dedup
  key) — no message text is ever persisted or logged by M01. This directly
  satisfies the charter's "M01 must not silently implement a conversation
  store" instruction and the task's explicit guidance. Full message
  content storage and conversation modeling is M05's job.
- **A4 — Outbound message content is durably stored outside the queue
  payload, encrypted at rest.** `JobEnvelope` fail-closed rejects any
  `SENSITIVE`/`SECRET` payload field (§1), and message text is at minimum
  `SENSITIVE`. M01 therefore persists message bodies in a dedicated table,
  encrypted via the existing `CredentialVault` (reused, not reimplemented),
  and queues only an opaque `message_uuid` plus `INTERNAL` metadata
  (`bot_id`, `destination_id`). See ADR-0012.
- **A5 — No new capability.** M01's admin actions (bot/destination
  management, connection testing, dead-letter requeue) are gated by the
  existing `CapabilityRegistrar::MANAGE` (`universal_telegram_manage`)
  capability, following ADR-0010's explicit "later milestones extend this
  registrar only when a genuinely distinct authorization need exists."
  Bot-command authorization (a genuinely distinct need — Telegram *user*
  identity, not WP capability) is explicitly M08's concern, not M01's.
- **A6 — Reliability mechanisms are scoped per bot AND per destination**,
  independently. A provider-wide or token-level outage should not be
  masked by, or conflated with, a single bad destination (e.g., "bot was
  removed from this one group"). See ADR-0012 §"per-bot reliability
  isolation" and ADR-0014.
- **A7 — Rate-limit and circuit-breaker deferrals are not `RetryPolicy`
  failures.** A proactive rate-limit wait or an honored `retry_after` is
  expected, routine throttling — not a failure — and must not consume the
  generic 5-attempt retry budget meant for genuine unexpected failures. The
  Telegram send handler defers these cases by directly rescheduling via
  Action Scheduler's own public scheduling function, without throwing. See
  ADR-0014.
- **A8 — Terminal (non-retryable) Telegram errors dead-letter immediately**,
  without exhausting the generic 5-attempt budget, by having the handler
  perform its own terminal-state transition and audit entry, then return
  without throwing (so `WorkerRunner` sees a completed attempt, not a
  retry-eligible failure). See ADR-0014.
- **A9 — Queue-health alerting is a local WordPress admin surface, never a
  Telegram message** — the transport itself may be the thing that's
  failing. Implemented as an extension of the existing `DiagnosticsPage`/
  `DiagnosticsReport` plus a capability-gated `admin_notices` banner, both
  reusing `QueueHealth` rather than duplicating it.
- **A10 — Version bump, resolved.** M01 completion moves
  `UNIVERSAL_TELEGRAM_VERSION` from its current `0.0.1` to **`0.1.0`**.
  This is a decided part of this plan, not an open question: SemVer's
  pre-1.0 latitude permits either a patch or minor bump, and a minor bump
  is the correct one here because M01 is the plugin's first genuine
  functional capability beyond foundation scaffolding (M00 was
  infrastructure with no user-facing behavior; M01 is the first milestone
  an administrator can actually use) — the same distinction SemVer's
  minor-version position exists to signal, independent of the pre-1.0
  breaking-change latitude. `universal_telegram_db_version` moves `1` →
  `7` in the same release (§8, §11).
- **A11 — Rate-limit, circuit-breaker, retention, and stale-rotation-alert
  numeric defaults are this plugin's own product decisions, resolved and
  frozen by this plan.** (The webhook-rotation threshold is an alert
  trigger only — it never discards, replaces, or promotes a secret; see
  ADR-0013.) Telegram's Bot API reference does not publish a
  stable numeric throughput ceiling; only its FAQ page gives
  qualitative/example guidance (§6). Every default in §5.3/§8/ADR-0014 is
  set as a **frozen M01 default** — no numeric value in this plan is left
  open pending Architect or Product Owner input — while remaining an
  ordinary `Settings`-configurable value an administrator may adjust
  post-deployment.
- **A12 — Diagnostics/admin UI does not display bot tokens, webhook
  secrets, or message body plaintext under any circumstance**, including
  on the "edit bot" form (a token can be *replaced*, never *revealed*,
  mirroring how the existing `CredentialVault` design already treats
  decryption as an internal, purpose-bound operation, never an
  admin-display operation).

---

## 4. Technical identity and module ownership impact

- **New top-level boundary created**: `Telegram` (`UniversalTelegram\Telegram`),
  the 8th of the 13 authoritative boundaries in `docs/ARCHITECTURE.md`'s
  table, moving from "Not implemented — owned by M01" to "Implemented."
  No new top-level boundary beyond this one is created (per ADR-0005, M01
  must not invent a 14th boundary; the task's own instructions reiterate
  this).
- **New subdomain of the existing `Administration` boundary**: `Telegram`
  (parallel to the existing `Diagnostics` subdomain) —
  `UniversalTelegram\Administration\Telegram\*` — holding the admin-facing
  bot/destination management screen and its request handlers. This mirrors
  ADR-0005's precedent that internal implementation surfaces (rendering,
  request handling) belong to `Administration`, while domain logic
  (`BotProfile`, `Destination`, delivery, reliability) belongs to the
  owning boundary (`Telegram`).
- **Existing `Administration\Diagnostics` files are extended, not
  replaced**: `DiagnosticsReport::generate()` gains Telegram health fields;
  `DiagnosticsPage::render()` gains a Telegram health section and a
  queue-health alert banner. No new Diagnostics classes are introduced.
- **`Core\Plugin`, `Core\Configuration\Settings`, `Core\Lifecycle\Uninstaller`,
  `Persistence\Migrator`, `tests/unit/Core/StructuralBoundariesTest.php`,
  `tests/package/run.sh`** are modified in place — this is the "necessary
  extension of already-implemented boundaries" explicitly permitted by the
  task's framing, not a violation of ADR-0005's boundary discipline.
- **No modification to `Queue\JobEnvelope`, `Queue\Dispatcher`,
  `Queue\WorkerRunner`, `Queue\RetryPolicy`, `Queue\HandlerRegistry`,
  `Core\Security\CredentialVault`, `Privacy\Classification`,
  `Privacy\Redactor`, `Audit\AuditLogger`, or `Core\Capabilities\CapabilityRegistrar`.**
  All are used exactly as designed at M00; none of M01's requirements
  justify changing their generic contracts (per ADR-0006's explicit
  instruction that `RetryPolicy` stay provider-agnostic, and per
  ADR-0008/0009/0010's "used as-is by future consumers" framing).
- **`Events`, `Automations`, `Conversations`, `ChatWidget`, `AI` remain
  absent from `src/`** — `StructuralBoundariesTest` continues to assert
  their absence after this milestone; only `Telegram` moves from
  "asserted absent" to "asserted present with an explicit file list."

---

## 5. Detailed architectural decisions

### 5.1 Bot cardinality, webhook routing, and outbound delivery (ADR-0012)

Full ADR text is in §14. Summary of the decision and alternatives
considered:

- **Decision**: multiple bot profiles per install; each is a fully
  independent unit (credential, webhook identity, destinations,
  reliability state). Webhook URL: WordPress REST API route
  `universal-telegram/v1/webhook/(?P<bot_uuid>[0-9a-f-]{36})`, registered
  on `rest_api_init` with `permission_callback` set to allow-all (Telegram
  cannot authenticate as a WP user; authenticity is proven by the secret
  header inside the callback, not by WP's REST auth layer — a standard,
  documented pattern for public webhook receivers in WordPress).
  `bot_uuid` is generated once at bot-profile creation (`wp_generate_uuid4()`),
  stored, never regenerated (regenerating it would silently break Telegram's
  existing webhook registration until the admin re-registers).
- **Alternatives considered and rejected**: (a) single bot per install —
  rejected per explicit Product Owner decision; (b) encoding bot identity
  in the secret token itself instead of the URL — rejected because it
  would force a linear scan of all bots' decrypted secrets to identify
  which bot a request is for, defeating the point of authenticated
  per-bot lookup, and because Telegram's own webhook path can safely carry
  a non-secret identifier while the header carries the actual secret;
  (c) `admin-ajax.php` instead of the REST API — rejected, REST routes are
  the current, documented WordPress mechanism for a structured public JSON
  endpoint and integrate cleanly with `WP_REST_Request`/`WP_REST_Response`.
- **Outbound delivery pattern**: message content is written to
  `universal_telegram_outbound_messages` (encrypted via `CredentialVault`,
  context bound to the specific `message_uuid`) **before** any
  `JobEnvelope` is constructed. The envelope's payload carries only
  `message_uuid` (opaque, `INTERNAL`), `bot_id` (`INTERNAL`),
  `destination_id` (`INTERNAL`) — never the token, never the text. The
  send handler re-reads and decrypts the message row at execution time.
  This is the only way to reconcile "queue every send" with `JobEnvelope`'s
  fail-closed payload rule (§1), and is the pattern ADR-0006 anticipated
  when it named M01 as introducing "the first provider-specific retry
  classification" on top of the generic foundation.
- **Per-bot reliability isolation**: rate-limit state, circuit-breaker
  state, and dead-letter status are all keyed by `(scope_type, scope_id)`
  where `scope_type` is `bot` or `destination` — never a single
  installation-wide value. A failing bot never throttles or trips the
  breaker for an unrelated bot. Full mechanics are ADR-0014's concern;
  ADR-0012 establishes only the isolation *principle* and the schema keys
  that make it possible.

### 5.2 Webhook authenticity, replay protection, and inbound handling (ADR-0013)

Full ADR text is in §14. Summary:

- **Authenticity**: Telegram's `setWebhook` `secret_token` parameter
  (1–256 chars, `A-Z a-z 0-9 _ -` only per the Bot API reference) is
  generated per bot (`bin2hex(random_bytes(24))` → 48 hex chars, well
  within the allowed length and charset), encrypted at rest via
  `CredentialVault` with context `telegram.webhook_secret:{bot_uuid}`, and
  compared against the incoming `X-Telegram-Bot-Api-Secret-Token` header
  using `hash_equals()` (constant-time). A missing header, a bot not found
  for the route's `bot_uuid`, a `CredentialVault` result other than
  `AVAILABLE`, or a mismatch all produce the **same** generic 401 response
  with **no distinguishing detail** — never revealing which failure mode
  occurred, to avoid giving an attacker an oracle.
- **Rotation/registration is failure-safe against an uncertain remote
  outcome, with no automatic expiry.** A bot's initial webhook
  registration sends its one existing active secret — no second secret is
  ever generated for a first registration, so there is nothing to
  desynchronize: WordPress already accepts the exact secret it just asked
  Telegram to use, regardless of whether that `setWebhook` call's own
  response was received. Rotation, by contrast, genuinely introduces a
  second secret (replacing an already-working one), so it uses an
  indefinitely-persistent active/pending dual-secret model: both are
  accepted until the outcome is definitively known, with no time limit on
  that acceptance. Resolution happens only through an explicit retry
  (resending the *same* pending secret, never a freshly generated one),
  an explicit rollback (re-affirming the active secret and only then
  discarding pending, once Telegram confirms it), or organically
  observing which secret real inbound traffic authenticates with. **No
  timer ever discards, replaces, or promotes a secret on its own** — an
  unresolved registration or rotation instead surfaces as a stale-rotation
  administrator diagnostic alert past a configurable age, measured from
  `webhook_last_attempt_at` (registration) or `webhook_secret_pending_since`
  (rotation), never `created_at`/`updated_at`. Full mechanics are in
  ADR-0013 §"Webhook secret registration and rotation are failure-safe
  against an uncertain remote outcome" (§14) and the schema/WP additions
  in §8 and §10 (WP1, WP2, WP7, WP10).
- **Replay/duplicate protection**: Telegram's `update_id` is documented as
  sequential and is the mechanism Telegram itself recommends for ignoring
  repeated updates. M01 stores `(bot_id, update_id)` under a `UNIQUE`
  database constraint in `universal_telegram_inbound_updates`; a duplicate
  insert is detected via the resulting DB error and answered with a fast
  200 (idempotent acknowledgment — Telegram must not be told to keep
  retrying something already recorded) without any reprocessing.
- **Malformed/oversized/unsupported input**: request body capped at a
  configurable size (default 1 MiB, generous over any real Telegram
  update, rejecting larger payloads with 413 before JSON-decoding them);
  invalid JSON → 400 with a fixed generic message (raw body never echoed
  back, never logged); a missing/non-integer `update_id` → 400; an update
  type outside the small M01-supported set (`message`, `edited_message`,
  `channel_post`, `edited_channel_post`) is still deduplicated and stored
  with `update_type = 'unsupported'`, then acknowledged 200 — Telegram is
  never made to retry-storm an update type M01 doesn't yet act on.
- **Fast acknowledgment**: the entire webhook handler does only
  synchronous, bounded-cost work (header check, size check, JSON decode,
  one indexed unique-keyed insert) — no Telegram API call, no queue
  dispatch, no heavy processing — before returning 200. This satisfies
  both Telegram's documented retry-on-non-2xx behavior and the charter's
  "must never place sensitive update content in Action Scheduler
  arguments" constraint by simply never routing inbound updates through
  the queue at all in M01 (nothing downstream needs asynchronous
  processing yet, since M01 does not implement conversations).
- **What M01 does with an inbound message**: records metadata (chat ID,
  thread ID if present, update type, timestamp) for deduplication, audit
  visibility, and a connection-test signal ("last inbound update received
  at TIMESTAMP" surfaced per bot in the bot management screen) —
  **explicitly not** a conversation store, per A3.

### 5.3 Provider reliability policy — rate limiting, circuit breaking, dead-letter, queue-health alerts (ADR-0014)

Full ADR text is in §14. Summary:

- **Telegram-specific failure classification**, computed from the HTTP
  status and Telegram's own `error_code`/`description`/`parameters` fields,
  sits in a new `Telegram\Client\TelegramFailureClassifier`, entirely
  outside `RetryPolicy`:
  - `RATE_LIMITED` (HTTP 429): reads `parameters.retry_after` (confirmed
    Bot API field — §6) as the wait duration; the handler reschedules
    directly via `as_schedule_single_action()` at `now + retry_after`
    seconds **without throwing** — not a `RetryPolicy`-counted attempt
    (A7), and does not affect circuit-breaker state (throttling is not
    unavailability). If `retry_after` is absent, non-integer, or
    non-positive, the handler uses a configurable fallback wait
    (`telegram_rate_limit_fallback_wait_seconds`, default 30 seconds)
    instead — never zero, never an unbounded immediate retry loop.
  - `TERMINAL` (e.g. 400 "chat not found", 403 "bot was blocked/kicked",
    400 "message thread not found"): the handler transitions the message
    to `dead_letter` itself, writes its own audit entry, and returns
    **without throwing** (A8) — no circuit-breaker impact (a single bad
    destination is not a provider-availability signal).
  - `TOKEN_INVALID` (401): the handler transitions the **bot**-scope
    circuit breaker to `open` with no automatic half-open probe (a bad
    token does not self-heal with time), sets the bot's `status` to
    `invalid`, dead-letters the current message, and returns without
    throwing. Only an explicit admin token replacement (which itself calls
    `getMe` to validate before committing) clears this state.
  - `RETRYABLE` (network error, timeout, 5xx): the handler rethrows,
    letting `WorkerRunner`'s existing generic sequence (audit entry →
    `RetryPolicy::should_retry()` → reschedule or terminal) run
    unmodified; **and** counts as one consecutive failure toward both the
    bot-scope and destination-scope circuit breakers (bot-scope checked
    first — if already open, the destination-scope breaker is never
    touched, avoiding compounding an already-known outage). Immediately
    before rethrowing on what the handler determines (via the shared
    `RetryPolicy::max_attempts()` value — no duplicated magic number) is
    the final permitted attempt, the handler performs the same
    dead-letter transition and Telegram-specific audit entry as the
    `TERMINAL` case, then still rethrows so `WorkerRunner`'s own generic
    terminal-failure audit entry is also recorded (deliberate redundancy:
    one generic entry, one Telegram-specific entry with delivery context —
    harmless, useful for diagnosis).
- **Rate limiting** (`Telegram\Reliability\RateLimiter`): token-bucket
  state per `(scope_type, scope_id)` in `universal_telegram_rate_limit_state`,
  refilled by elapsed-time delta at each check (correct even though Action
  Scheduler does not guarantee sub-second scheduling precision). Defaults
  (configurable, explicitly product decisions per A11, informed by
  Telegram's FAQ page — see §6):
  - Per destination: 1 message/second (matches the FAQ's "avoid sending
    more than one message per second" per-chat guidance), applied to every
    destination kind.
  - Per destination, additionally, when `kind` is `group` or `supergroup`:
    20 messages/minute (matches the FAQ's per-group guidance) — whichever
    of the two destination-level buckets is more restrictive at a given
    moment binds.
  - Per bot (aggregate across all its destinations): 20 messages/second
    default — deliberately below the FAQ's ~30/sec "bulk broadcast"
    example figure, leaving headroom, and explicitly not presented as a
    Telegram-documented hard ceiling.
  - When a bucket has no token available, the handler defers (A7) rather
    than failing.
- **Circuit breaker** (`Telegram\Reliability\CircuitBreaker`): two
  independent scopes, `bot` and `destination`, rows in
  `universal_telegram_circuit_breaker_state`, states `closed` / `open` /
  `half_open`. Defaults (configurable, product decisions):
  - Bot-scope: opens after 5 consecutive `RETRYABLE` failures within a
    10-minute observation window; first half-open cooldown 60 seconds;
    on a failed half-open probe, cooldown escalates by reusing
    `Queue\RetryPolicy::delay_seconds()` (intentional reuse of the
    existing generic exponential-backoff-with-jitter primitive — no
    duplicated math) keyed by the reopen count, capped at 900 seconds;
    exactly one trial send permitted while `half_open`; success → `closed`
    (`consecutive_failures` reset to 0); `TOKEN_INVALID` bypasses this
    entirely and opens indefinitely (see above).
  - Destination-scope: opens after 3 consecutive `RETRYABLE` failures
    within the same 10-minute window (lower threshold — narrower blast
    radius, should surface faster); identical cooldown/half-open mechanics,
    independent state row.
  - Check order in the send handler: bot-scope breaker first; if `open`
    and not yet due for a probe, defer (reschedule at the breaker's own
    `next_probe_at`, non-throwing) **without ever consulting the
    destination-scope breaker** — avoids compounding a known outage with
    a second, redundant deferral decision.
  - **Unbounded-deferral safety bound**: every deferral (rate-limit or
    circuit-open) also checks the message's total pending age against a
    configurable ceiling (default 24 hours). A message older than the
    ceiling is dead-lettered immediately regardless of remaining attempts
    or breaker state, preventing indefinite pileup during a very long
    outage.
- **Dead-letter representation**: not a separate table — a lifecycle state
  (`status = 'dead_letter'`) on `universal_telegram_outbound_messages`,
  with `dead_lettered_at` and `dead_letter_reason` (a fixed stable code,
  never raw Telegram error text) columns. Content ciphertext is retained
  (not purged) while dead-lettered, so an admin "Requeue" action can
  re-enqueue a fresh attempt (fresh `job_id`, `attempt` reset to 0,
  `status` reset to `pending`) without asking the admin to retype the
  message. Retained for `telegram_delivery_log_retention_days` (default
  90 days, configurable) before an automated cleanup job purges the row
  entirely.
- **Queue-health alert** (`Telegram\Reliability\QueueHealthAlert`,
  reusing `Queue\QueueHealth` rather than duplicating its counting logic):
  active when any of — dead-letter count > 0, any circuit breaker `open`,
  or any `pending`/`retry_scheduled` message older than a configurable
  staleness threshold (default 30 minutes) — is true. Surfaced two ways
  (A9): an extension of `DiagnosticsReport`/`DiagnosticsPage` (detailed
  view), and a capability-gated `admin_notices` banner shown site-wide in
  wp-admin (cheap: the underlying computation is cached in a 60-second
  transient so it does not run its queries on every admin page load),
  non-dismissible while the condition persists (a queue problem that
  silently disappears from view is worse than a mildly persistent banner).

---

## 6. External research (Telegram Bot API — official documentation)

All citations from `https://core.telegram.org/bots/api` (the canonical Bot
API reference) and `https://core.telegram.org/bots/faq` (the official FAQ),
fetched during this planning session:

- **`getMe`** (`https://core.telegram.org/bots/api#getme`): requires no
  parameters, returns basic information about the bot as a `User` object —
  used by this plan as the **synchronous, admin-triggered token-validity
  test** (never a queued send, never on the frontend request path).
- **`sendMessage`** (`https://core.telegram.org/bots/api#sendmessage`):
  required `chat_id` and `text`; relevant optional parameters confirmed
  present in the reference include `message_thread_id` ("Unique identifier
  for the target message thread (topic) of the forum") and `parse_mode`.
  Used by this plan exclusively through the queue (`SendMessageHandler`),
  never synchronously from an admin or frontend request, per the M01
  charter's explicit "All sends go through M00's queue abstraction, never
  synchronously" constraint.
- **`setWebhook`** (`https://core.telegram.org/bots/api#setwebhook`):
  confirmed parameters include `url` (required, HTTPS), `max_connections`
  (1–100, default 40), `allowed_updates`, `drop_pending_updates`, and
  `secret_token` — confirmed exact constraint: **"1-256 characters. Only
  characters `A-Z`, `a-z`, `0-9`, `_` and `-` are allowed."** This plan's
  48-hex-character generated secret satisfies this exactly. Delivery
  mechanism confirmed: **"the request will contain a header
  `X-Telegram-Bot-Api-Secret-Token` with the secret token as content"** —
  the exact header this plan verifies with `hash_equals()`.
- **`deleteWebhook`** (`https://core.telegram.org/bots/api#deletewebhook`):
  used by `Uninstaller` (best-effort, bounded-timeout, failures swallowed)
  to stop Telegram from continuing to POST to a deactivated site.
- **`Update` object / `update_id`** (`https://core.telegram.org/bots/api#update`):
  confirmed exact text — **"The update's unique identifier. Update
  identifiers start from a certain positive number and increase
  sequentially... This identifier becomes especially handy if you're using
  webhooks, since it allows you to ignore repeated updates or to restore
  the correct update sequence, should they get out of order,"** with the
  documented caveat that after at least a week with no updates, the next
  identifier is chosen randomly rather than sequentially (irrelevant to
  M01's per-bot `UNIQUE(bot_id, update_id)` dedup design, which does not
  depend on strict monotonicity, only on non-repetition).
- **Webhook retry behavior** (same page, "Marking Bot as Read for
  webhooks"-adjacent text): confirmed exact text — **"In case of an
  unsuccessful request (a request with response HTTP status code different
  from 2XY), we will repeat the request and give up after a reasonable
  amount of attempts"** — no documented exact timeout or attempt count;
  this plan's design (fast, bounded-cost 200 response) is intended to make
  this irrelevant in practice rather than to rely on a specific undocumented
  number.
- **Forum topics** (`https://core.telegram.org/bots/api#message`,
  `#sendmessage`): confirmed fields `is_forum` (chat), `is_topic_message`
  and `message_thread_id` (message) — **"Unique identifier of a message
  thread or forum topic to which the message belongs; for supergroups and
  private chats only."** Telegram's own field description therefore
  permits `message_thread_id` on private-chat messages as well as
  supergroup forum topics (a newer, narrower private-chat threading use,
  e.g. business-connection conversations) — but M01 explicitly scopes
  destination-level `message_thread_id` support to **supergroup forum
  topics only** (`kind = 'supergroup'`), the well-established,
  product-relevant forum-topics feature this plugin's destinations are
  designed around. Private-chat threading has no clear operator use case
  in this milestone and is deliberately left unsupported rather than
  added speculatively; `destinations.message_thread_id` is rejected by
  validation on every other destination kind, including `private` (see
  §8, WP3).
- **Rate/flood-control guidance** (`https://core.telegram.org/bots/faq`,
  "My bot is hitting limits, how do I avoid this?"): confirmed exact
  quotes — **"In a single chat, avoid sending more than one message per
  second,"** **"In a group, bots are not be able to send more than 20
  messages per minute,"** and **"For bulk notifications, bots are not able
  to broadcast more than about 30 messages per second, unless they enable
  paid broadcasts... We may allow short bursts that go over this limit,
  but eventually you'll begin receiving 429 errors."** These are the only
  numeric figures Telegram publishes; none of them is a hard, stable,
  contractual limit (the FAQ itself hedges with "about" and describes
  short-burst tolerance), so this plan treats them as **inputs to a
  conservative configurable default** (§5.3), not as constants to encode
  as fixed truths, per A11.
- **`ResponseParameters.retry_after`** (`https://core.telegram.org/bots/api#responseparameters`):
  confirmed field — `parameters.retry_after` is the optional integer
  number of seconds a client must wait before repeating a request that
  hit flood control (HTTP 429), returned inside the top-level response's
  optional `parameters` field of type `ResponseParameters` (itself
  referenced from `#making-requests`: "Some errors may also have an
  optional field `parameters` of the type `ResponseParameters`, which can
  help to automatically handle the error"). `TelegramFailureClassifier`
  (WP4) reads `parameters.retry_after` directly as the primary wait
  signal on a 429 response; the configurable fallback wait (§5.3) applies
  only when the field is absent, non-integer, or non-positive, never as a
  substitute for a present, valid value.
- **429 error / rate limiting general mechanism**
  (`https://core.telegram.org/bots/api#making-requests`): confirmed exact
  top-level response shape — `ok` (Boolean, always present), `description`
  (optional String), `error_code` (Integer, with the documented caveat that
  **"its contents are subject to change in the future"** — this plan
  therefore never branches production logic on a specific numeric
  `error_code` value beyond the well-known 401/403/429 HTTP-status-aligned
  cases, preferring the HTTP status code itself as the primary signal), and
  an optional `parameters` field "of the type `ResponseParameters`, which
  can help to automatically handle the error."

---

## 7. Security and privacy impact

- **Token/secret non-exposure**: bot tokens and webhook secrets are never
  rendered in any admin screen (A12), never included in any `JobEnvelope`
  payload (A4, enforced structurally by `JobEnvelope`'s own constructor —
  not just by convention), never included in any `AuditLogger::record()`
  context field classified below `SECRET` (every audit call site's
  classification map explicitly marks token/secret-adjacent fields
  `SECRET`, which `Redactor` strips entirely rather than masks — see per
  audit-action classification maps in §9), and never logged via PHP's
  `error_log()` fallback path in `WorkerRunner::handle_failure()` (which
  only ever receives the generic fixed failure code, never job payload
  content).
- **Message content classification**: message text is classified
  `SENSITIVE` at minimum (may contain business/customer information once
  M02+ builds on this transport). It is encrypted at rest (A4), never
  placed in a queue payload, never rendered in the dead-letter inspection
  UI (only metadata — `message_uuid`, bot, destination, failure code,
  timestamps, attempt count — is listed; body stays encrypted and
  unexposed even to an authorized administrator browsing the delivery
  log, matching the existing plugin-wide norm that `CredentialVault`
  decryption is always purpose-bound, never a display operation).
- **Inbound update content**: no message text from Telegram is ever
  persisted (A3) — only metadata needed for dedup/audit/connection-testing.
  This is itself a privacy control: nothing sensitive a visitor or
  Telegram-side user sends can leak through M01's diagnostics or audit
  surfaces, because it was never stored.
- **Webhook authenticity as a security boundary**: constant-time
  (`hash_equals()`) secret comparison prevents timing side-channels;
  identical generic 401 for every failure mode prevents a bot-enumeration
  or secret-guessing oracle; replay protection via `UNIQUE(bot_id,
  update_id)` prevents a captured request from being usefully replayed
  (replaying it is a guaranteed no-op, acknowledged 200 without
  reprocessing).
- **Fail-closed webhook verification**: if `CredentialVault::decrypt()`
  returns anything other than `AVAILABLE` for the bot's stored webhook
  secret (e.g. `INVALIDATED` after a WordPress salt rotation, or
  `UNAVAILABLE`), verification fails closed (401) — the webhook is never
  treated as authentic merely because the comparison could not be
  performed.
- **Authorization**: every new admin-facing action (create/edit/delete
  bot, replace token, rotate webhook secret, add/edit/delete destination,
  test connection, send test message, register webhook, requeue/discard a
  dead-lettered message) re-verifies both `current_user_can(CapabilityRegistrar::MANAGE)`
  and its own nonce inside its own request handler — never relying solely
  on menu-registration-time gating — following `SelfTest::handle_request()`'s
  exact existing pattern (ADR-0010).
- **Audit trail**: every credential change (token replace, webhook secret
  rotate), every bot/destination lifecycle change (create, enable,
  disable, delete), every dead-letter transition, every circuit-breaker
  state transition, and every webhook-verification rejection is recorded
  via `AuditLogger::record()` with an explicit, complete classification
  map (see §9 for the exact field-by-field maps) — extending, never
  bypassing, ADR-0009's fail-closed redaction discipline.
- **Degraded-schema behavior**: every new repository/service that touches
  the database (BotProfileRepository, DestinationRepository,
  OutboundMessageRepository, UpdateRepository, RateLimiter, CircuitBreaker)
  checks `SchemaHealth::is_available()` at its own point of use, exactly
  like M00's existing services — never via selective construction in the
  composition root (ADR-0007's explicitly corrected pattern). When schema
  is unavailable: outbound sends fail closed via `Dispatcher::enqueue()`'s
  existing `SCHEMA_UNAVAILABLE` result (no behavior change needed there —
  reused as-is); the webhook endpoint returns a generic 503 (not 401 — a
  distinct failure mode, not a security rejection) without attempting any
  insert; admin screens show the same fixed degraded-mode notice pattern
  `DiagnosticsPage` already uses, with only the stable `MigrationFailureCode`
  value displayed, never a raw DB error.
- **No raw SQL against Action Scheduler's own tables**: M01 introduces no
  code that touches Action Scheduler's schema directly; all interaction is
  through its public functions (`as_enqueue_async_action`,
  `as_schedule_single_action`, `as_unschedule_all_actions`, `ActionScheduler::store()`),
  exactly as M00 already does.
- **Uninstall**: `remove_data_on_uninstall = false` (default) preserves
  all Telegram tables and encrypted credentials, matching the existing
  audit-log precedent exactly. `= true` additionally drops all 6 new
  tables. Independent of that setting, uninstall makes a best-effort,
  bounded-timeout (5s) `deleteWebhook` call per configured bot with a
  decryptable token, swallowing any failure, so a deleted plugin does not
  leave Telegram indefinitely retrying webhook delivery to a dead
  endpoint — this is a one-time, admin-triggered action (the WP Plugins
  screen's "Delete" action), not a hot request path, so a bounded
  synchronous external call here does not violate the "never block an
  ordinary request" constraint (that constraint governs frontend/regular
  request handling, which uninstall is not).

---

## 8. Schema, migration, configuration, API, and uninstall impact

All six new tables are added as new numbered steps in the existing
`src/Persistence/Migrator.php`, bumping `target_version()` from `1` to
`7`. Each step follows the exact existing pattern: `CREATE TABLE IF NOT
EXISTS` with `$wpdb->get_charset_collate()`, a paired `verify_step_N(): bool`
querying `INFORMATION_SCHEMA.TABLES`/`.COLUMNS`, no `dbDelta()`, no engine
or DDL feature not already used by `step_1`. No foreign-key constraints
are declared (matching the codebase's existing convention of no FKs;
referential integrity is enforced at the repository layer, not the schema
layer).

- **Step 2 — `{prefix}universal_telegram_bots`**
  - `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` — PRIMARY KEY
  - `bot_uuid CHAR(36) NOT NULL` — UNIQUE KEY; webhook-route identifier;
    classification `INTERNAL`
  - `name VARCHAR(191) NOT NULL` — admin label; `PUBLIC`
  - `token_ciphertext LONGTEXT NOT NULL` — `CredentialVault`-encrypted;
    `SECRET`
  - `webhook_secret_ciphertext LONGTEXT NOT NULL` — `CredentialVault`-encrypted;
    generated and populated at bot-creation time, so **every bot row has
    an active secret from the moment it exists** — there is no bot state
    with no active secret; this is the one and only secret sent on
    initial webhook registration; `SECRET`
  - `webhook_secret_pending_ciphertext LONGTEXT NULL` — `CredentialVault`-encrypted;
    present **only** while a **rotation** is in progress (a rotation
    replacing an already-existing active secret with a new one) — never
    created for initial registration, which has no prior secret to
    rotate away from and simply registers the existing `active` secret
    directly; `NULL` whenever no rotation is in progress; `SECRET`
  - `webhook_secret_pending_since DATETIME NULL` — when the
    currently-in-progress **rotation's** pending secret was generated;
    the staleness basis for an uncertain **rotation** specifically (§5.2,
    ADR-0013) — never `created_at`/`updated_at`, and never used for an
    uncertain *initial registration*, which has no pending secret and
    uses `webhook_last_attempt_at` instead. This column never drives an
    automatic discard, replacement, or promotion of the pending secret;
    an unresolved pending secret remains valid and accepted indefinitely
    until resolved through one of the explicit resolution paths (retry,
    rollback, or traffic confirmation)
  - `webhook_registration_state VARCHAR(16) NOT NULL DEFAULT 'unregistered'` —
    `unregistered|registered|uncertain`; reflects the outcome of the most
    recent `setWebhook` call — `register()` (which sends the `active`
    secret), or `rotate()`/`retry_pending()` (which send the `pending`
    secret), or `rollback()` (which sends the `active` secret again) —
    made against this bot, **not** a claim that every one of those calls
    uses the same secret; `INTERNAL`
  - `webhook_last_attempt_at DATETIME NULL` — set on every `register()`,
    `rotate()`, `retry_pending()`, and `rollback()` `setWebhook` attempt,
    regardless of outcome (clean success, clean rejection, or uncertain);
    the sole timestamp basis for the stale-alert evaluation of an
    uncertain **initial registration**, which has no pending secret and
    no `webhook_registered_at` to measure staleness from (§5.2, ADR-0013);
    `INTERNAL`
  - `telegram_bot_id BIGINT NULL` — from `getMe`; `INTERNAL`
  - `telegram_username VARCHAR(191) NULL` — from `getMe`; `INTERNAL`
  - `status VARCHAR(16) NOT NULL DEFAULT 'unconfigured'` — `unconfigured|active|disabled|invalid`; `INTERNAL`
  - `webhook_registered_at DATETIME NULL`
  - `created_at DATETIME NOT NULL`
  - `updated_at DATETIME NOT NULL`
  - Indexes: `UNIQUE KEY bot_uuid (bot_uuid)`, `KEY status (status)`
- **Step 3 — `{prefix}universal_telegram_destinations`**
  - `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` — PRIMARY KEY
  - `bot_id BIGINT UNSIGNED NOT NULL` — `INTERNAL`
  - `kind VARCHAR(16) NOT NULL` — `private|group|supergroup|channel`; `INTERNAL`
  - `chat_id VARCHAR(64) NOT NULL` — Telegram chat identifier; `INTERNAL`
  - `message_thread_id BIGINT NULL` — forum topic id; `INTERNAL`; valid
    and settable only when `kind = 'supergroup'` — rejected by
    repository-layer validation on every other kind (§6 scope decision);
    not a database constraint, consistent with this codebase's existing
    no-FK, validate-at-the-repository-layer convention
  - `label VARCHAR(191) NOT NULL` — admin-facing name; `PUBLIC`
  - `enabled TINYINT(1) NOT NULL DEFAULT 1`
  - `created_at DATETIME NOT NULL`
  - Indexes: `KEY bot_id (bot_id)`, `UNIQUE KEY bot_chat_thread (bot_id, chat_id, message_thread_id)`
- **Step 4 — `{prefix}universal_telegram_outbound_messages`**
  - `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` — PRIMARY KEY
  - `message_uuid CHAR(36) NOT NULL` — UNIQUE; the only identifier ever
    placed in a `JobEnvelope`; `INTERNAL`
  - `bot_id BIGINT UNSIGNED NOT NULL` — `INTERNAL`
  - `destination_id BIGINT UNSIGNED NOT NULL` — `INTERNAL`
  - `body_ciphertext LONGTEXT NULL` — `CredentialVault`-encrypted, context
    bound to `message_uuid`; `SECRET` while present, set `NULL` on
    retention purge; nullable so a purged row's metadata can be retained
    longer than its content
  - `parse_mode VARCHAR(16) NULL`
  - `status VARCHAR(16) NOT NULL DEFAULT 'pending'` —
    `pending|sending|sent|retry_scheduled|dead_letter|purged`; `INTERNAL`
  - `attempt_count INT UNSIGNED NOT NULL DEFAULT 0`
  - `last_failure_code VARCHAR(64) NULL` — fixed stable code only, never
    raw API text; `INTERNAL`
  - `possible_duplicate_delivery TINYINT(1) NOT NULL DEFAULT 0` — set
    once, never cleared, whenever a send attempt fails at the
    network-transport level (no HTTP response received at all — see
    ADR-0014's delivery-guarantee decision, §5.3); `INTERNAL`
  - `dead_lettered_at DATETIME NULL`
  - `telegram_message_id BIGINT NULL` — returned on success; `INTERNAL`
  - `created_at DATETIME NOT NULL`
  - `updated_at DATETIME NOT NULL`
  - `sent_at DATETIME NULL`
  - Indexes: `UNIQUE KEY message_uuid (message_uuid)`, `KEY status (status)`,
    `KEY bot_destination (bot_id, destination_id)`, `KEY created_at (created_at)`
    (staleness/retention queries)
- **Step 5 — `{prefix}universal_telegram_inbound_updates`**
  - `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` — PRIMARY KEY
  - `bot_id BIGINT UNSIGNED NOT NULL` — `INTERNAL`
  - `update_id BIGINT NOT NULL` — Telegram's own identifier; `INTERNAL`
  - `update_type VARCHAR(32) NOT NULL` — `message|edited_message|channel_post|edited_channel_post|unsupported`; `INTERNAL`
  - `chat_id VARCHAR(64) NULL` — metadata only, no text; `INTERNAL`
  - `message_thread_id BIGINT NULL` — `INTERNAL`
  - `received_at DATETIME NOT NULL`
  - Indexes: `UNIQUE KEY bot_update (bot_id, update_id)` (the dedup/replay
    mechanism itself), `KEY received_at (received_at)`
- **Step 6 — `{prefix}universal_telegram_circuit_breaker_state`**
  - `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` — PRIMARY KEY
  - `scope_type VARCHAR(16) NOT NULL` — `bot|destination`
  - `scope_id BIGINT UNSIGNED NOT NULL`
  - `state VARCHAR(16) NOT NULL DEFAULT 'closed'` — `closed|open|half_open`
  - `consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0`
  - `opened_at DATETIME NULL`
  - `next_probe_at DATETIME NULL`
  - `updated_at DATETIME NOT NULL`
  - Indexes: `UNIQUE KEY scope (scope_type, scope_id)`
  - All fields `INTERNAL` — operational state, no PII, no secrets
- **Step 7 — `{prefix}universal_telegram_rate_limit_state`**
  - `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` — PRIMARY KEY
  - `scope_type VARCHAR(16) NOT NULL` — `bot|destination`
  - `scope_id BIGINT UNSIGNED NOT NULL`
  - `tokens_available DECIMAL(6,2) NOT NULL`
  - `last_refill_at DATETIME NOT NULL`
  - Indexes: `UNIQUE KEY scope (scope_type, scope_id)`
  - All fields `INTERNAL`

**Degraded mode**: if migration to version 7 fails partway (e.g. step 5
succeeds, step 6 fails), `SchemaHealth::mark_unavailable()` fires exactly
as it does today for the single-step M00 case — no Telegram-specific
degraded-mode code path is needed; the same request-scoped,
recomputed-fresh-every-request availability check already covers this
milestone's larger step count without modification.

**`Core\Configuration\Settings` additions** (single existing option
`universal_telegram_settings`, extending `defaults()`/`sanitize()`, no new
`wp_options` row):
- `telegram_message_retention_days` (int, default `30`) — how long
  `body_ciphertext` is retained after a message reaches `sent` before a
  cleanup job nulls it out.
- `telegram_delivery_log_retention_days` (int, default `90`) — how long a
  message's metadata row (any terminal status) is retained before the
  cleanup job deletes the row entirely.
- `telegram_max_pending_seconds` (int, default `86400` / 24h) — the
  unbounded-deferral safety ceiling (§5.3).
- `telegram_webhook_max_body_bytes` (int, default `1048576` / 1 MiB).
- `telegram_stale_pending_alert_seconds` (int, default `1800` / 30 min) —
  the queue-health-alert staleness threshold.
- `telegram_rate_limit_fallback_wait_seconds` (int, default `30`) — used
  only when a 429 response's `retry_after` is absent, non-integer, or
  non-positive.
- `telegram_webhook_rotation_max_pending_hours` (int, default `24`) — the
  **staleness threshold**, not a discard timer, applied to any bot whose
  `webhook_registration_state = 'uncertain'`, measured from the correct
  timestamp for the case at hand: `bots.webhook_secret_pending_since` for
  an uncertain **rotation** (a `pending` secret exists), or
  `bots.webhook_last_attempt_at` for an uncertain **initial registration**
  (no `pending` secret and no `webhook_registered_at` yet) — **never**
  `created_at` or `updated_at` as a proxy for either case. Once a bot's
  applicable timestamp exceeds this age, it is surfaced as a
  stale-rotation administrator diagnostic alert (§5.2, ADR-0013, WP10).
  Crossing this threshold never discards, replaces, or promotes a pending
  secret, and never changes which secrets `WebhookSecretVerifier` accepts
  — both the active and any pending secret remain valid indefinitely
  until an administrator resolves the state through an explicit action
  (retry, rollback) or Telegram's own traffic organically confirms it.

All numeric defaults above (rate-limit thresholds, circuit-breaker
thresholds/cooldowns, retention periods, staleness thresholds, the
fallback wait, and the rotation-pending ceiling) are **frozen M01
defaults** — this plugin's own product decisions, informed by but not
asserted as equal to Telegram's own published guidance (§6), each backed
by the rationale given at its point of definition in §5.3/§8/ADR-0014.
They are not open questions; they are ordinary `Settings`-configurable
values an administrator may adjust post-deployment, exactly like every
other `Settings` field in this plugin.

**Configuration API impact**: none of these are exposed as a public
PHP API, hook, or REST route beyond the webhook receiver itself — matching
M00's precedent that nothing is a public extension point until a later
milestone has a genuine registrant needing one.

**New public-facing endpoint**: exactly one — the webhook receiver, REST
route `universal-telegram/v1/webhook/{bot_uuid}`, `POST` only, unauthenticated
at the WP-REST layer, authenticated inside the callback via the secret
header (§5.2). No other new route (admin screens use existing
`admin-post.php` + nonces, matching `SelfTest`'s pattern).

**Uninstall impact** (`src/Core/Lifecycle/Uninstaller.php`, modified): the
existing unconditional `as_unschedule_all_actions(null, [], WorkerRunner::GROUP)`
call already cancels any pending Telegram jobs (same group) with no code
change required there. Added, unconditionally (independent of
`remove_data_on_uninstall`): a best-effort, bounded-timeout `deleteWebhook`
call per bot with a currently-decryptable token (failures swallowed).
Added, only when `remove_data_on_uninstall === true`: `DROP TABLE IF
EXISTS` for all 6 new tables, using the same direct-SQL pattern (never
`dbDelta`) as the existing `drop_audit_table()` method, plus the schema
version option deletion (already generic, no change needed —
`universal_telegram_db_version` is deleted regardless of which milestone
last bumped it).

---

## 9. Test, CI, package, and evidence strategy

**No CI job or matrix change is required** — `phpunit.xml.dist` and
`phpunit-integration.xml.dist` auto-discover `*Test.php` under
`tests/unit`/`tests/integration` by directory suffix; every new test file
listed in §11 is picked up automatically by the existing `unit`,
`integration-wp-only-floor`, `integration-wp-only-current`, and
`integration-wc-present-current` jobs with no `.github/workflows/ci.yml`
edit. `phpcs.xml.dist`'s `<file>src</file>`/`<file>tests</file>` entries
already cover new files under those directories. `phpstan.neon.dist`'s
`paths: [src]` already covers new `src/Telegram/**` and
`src/Administration/Telegram/**` files.

**Mock/fake HTTP transport seam**: WordPress's own `pre_http_request`
filter (used identically in unit and integration tests) intercepts every
`wp_remote_post`/`wp_remote_get` call `Telegram\Client\TelegramApiClient`
makes, returning a scripted fake response array (status code, JSON body,
optionally a `Retry-After`/`parameters.retry_after` value) — **no live
bot token is ever required, committed, or reachable from CI.** This is
the single seam every reliability/classification test in this plan is
built on.

**Concrete tests, by requirement**:
- **Bot-token encryption and rotation**: `tests/unit/Telegram/Configuration/BotProfileTest.php`
  asserts a constructed/persisted `BotProfile`'s stored ciphertext is
  never equal to the plaintext token and cannot be read back except
  through the one purpose-bound decrypt method used by
  `TelegramApiClient`/`WebhookSecretVerifier`.
  `tests/integration/Telegram/Configuration/BotProfileRepositoryTest.php`
  (WP2) exercises the repository's own unconditional ciphertext replace
  (no remote validation at this layer) against a real DB.
  `tests/integration/Administration/Telegram/BotManagementControllerTest.php`
  (WP10) exercises the admin-facing, validate-before-commit token-replace
  flow end to end: an invalid new token is rejected via a faked `getMe`
  failure with the old token left intact; a valid new token is committed
  only after a faked `getMe` success.
- **Outbound queueing and durable-message hydration**:
  `tests/integration/Telegram/Outbound/SendMessageHandlerTest.php` —
  enqueue via `MessageDispatcher`, assert the `JobEnvelope`'s
  `to_action_args()` payload contains only `message_uuid`/`bot_id`/`destination_id`
  (no body text present anywhere in the array — asserted by exhaustive key
  check, not just absence of a specific key name), then assert the handler
  successfully re-hydrates and sends using a faked 200 `sendMessage`
  response.
- **Dispatch isolation**: `tests/unit/Queue/JobEnvelopeTest.php`-style
  unit test (new file `tests/unit/Telegram/Outbound/OutboundPayloadClassificationTest.php`)
  proving that attempting to construct a `telegram_send_message`
  `JobEnvelope` with a `body`/`token` field present throws
  `PayloadRejectedException` — a structural proof the fail-closed
  contract holds for this milestone's own job type, not just M00's.
- **Temporary Telegram failure recovery**: integration test simulating a
  faked 500 response, then a faked 200 on the next attempt, asserting the
  message ends `sent` and the generic `RetryPolicy` schedule was used in
  between (mirrors `SchemaDegradedExecutionTest`'s style of driving
  `ActionScheduler_QueueRunner::instance()->run()` directly).
- **Retry-after / rate-limit handling**:
  `tests/unit/Telegram/Client/TelegramFailureClassifierTest.php`
  (classification correctness) plus
  `tests/integration/Telegram/Reliability/RateLimiterDeferralTest.php`
  (faked 429, assert a fresh Action Scheduler action is scheduled at the
  expected timestamp and the original attempt did **not** increment
  `RetryPolicy`'s counted attempts).
- **Circuit-breaker transition and recovery**:
  `tests/integration/Telegram/Reliability/CircuitBreakerTest.php` — drive
  5 consecutive faked 500s, assert bot-scope `open`; advance the injected
  clock past cooldown, assert exactly one `half_open` probe is attempted;
  faked success closes it; faked 401 opens indefinitely with no scheduled
  probe.
- **Dead-letter retention and inspection**: integration test asserting a
  `TERMINAL`-classified faked 400 response transitions status to
  `dead_letter` on the very first attempt (no wasted retries), that the
  admin "Requeue" `admin-post` handler resets it to a fresh `pending`
  attempt, and that the retention cleanup job purges rows past
  `telegram_delivery_log_retention_days`.
- **Queue-health alert visibility**: `tests/integration/Administration/Diagnostics/DiagnosticsPageTest.php`
  (modified) asserts the alert banner is absent when healthy and present
  (with the fixed alert text, not raw internal detail) once a synthetic
  dead-letter/open-breaker/stale-pending-message/**stale-unresolved-registration**
  condition is seeded (the last of these added by WP10's extension of
  `QueueHealthAlert`).
- **Multiple destinations and forum-topic routing**: integration test
  sending to two destinations of a bot (one `private`, one `supergroup`
  with `message_thread_id` set) and asserting the faked outbound
  `sendMessage` request body's `chat_id`/`message_thread_id` match each
  destination exactly.
- **Webhook secret rejection**: `tests/integration/Telegram/Inbound/WebhookControllerTest.php` —
  missing header, wrong header value, and an unknown `bot_uuid` all
  produce an identical generic 401.
- **Webhook secret rotation/registration split-brain resistance**
  (WP7, WP10 — full detail in WP10's own test list, §10): clean initial
  registration confirms immediately using the bot's one existing active
  secret with no pending secret ever created; an uncertain rotation
  leaves both `active` and `pending` accepted indefinitely, with no
  automatic change even once the configurable staleness threshold is
  crossed (only a diagnostic alert, never a state change); `retry_pending()`
  is proven to resend the byte-identical pending secret, never a newly
  generated one; `rollback()` discards `pending` only on a confirmed
  clean success and leaves it fully intact on both a definite failure and
  an uncertain outcome; and no recurring/automated task in this plan is
  shown to hold any write path to the pending-secret fields at all — only
  the four explicit, admin-invoked `WebhookRegistrationCoordinator`
  operations and WP7's traffic-based confirmation can ever change them.
- **Replayed update rejection / duplicate `update_id`**: same test class —
  posting the same `(bot_id, update_id)` twice returns 200 both times but
  the table contains exactly one row.
- **Malformed, oversized, and unsupported webhook payloads**: same test
  class — invalid JSON → 400; body over `telegram_webhook_max_body_bytes`
  → 413 (rejected before JSON parsing is attempted); an update type
  outside the supported set → 200 with a row recorded as `unsupported`.
- **Token/secret non-exposure**: `tests/integration/Administration/Telegram/BotManagementPageTest.php`
  renders the bot list/edit screens with a known synthetic token value
  seeded and asserts the plaintext substring is absent from the rendered
  HTML, from every `AuditLogRepository::recent()` entry's `context` JSON,
  from the built distributable ZIP's contents (no fixture files containing
  a real-looking token), and — via the same pattern `tests/package/run.sh`
  already uses for the audit table — from the packaged-plugin acceptance
  run's `wp eval` assertions.
- **WordPress-only and WooCommerce-present compatibility**: no Telegram
  code path branches on `WooCommerceSupport::is_active()` — the existing
  `integration-wp-only-*` and `integration-wc-present-current` CI jobs
  already exercise the full Telegram test suite in both configurations
  with no test-file duplication needed.
- **Clean install, upgrade, uninstall, packaged-plugin acceptance**:
  `tests/package/run.sh` (modified) adds, after the existing audit-table
  assertion, an equivalent `SHOW TABLES LIKE '...universal_telegram_bots'`
  check post-activation, and extends the existing "uninstall with default
  retention keeps data" / "uninstall with retention enabled removes data"
  assertions to also check the new bots table's presence/absence,
  mirroring the exact existing `audit_table_exists()` helper pattern.

**Traceability**: every M01 charter acceptance-criterion row is mapped to
at least one test above in §12.

**Formal acceptance (ADR-0011)**: M01 falls within the M00–M09 exemption —
no independent Vlad acceptance gate applies. Required evidence is this
frozen plan, code review, the automated matrix above (unit, integration ×3
configurations, phpcs, phpstan, package acceptance ×3 configurations), and
green CI, exactly matching M00's closure record's evidentiary shape. An
**optional**, non-gating later sandbox-bot smoke procedure (creating one
real Telegram bot via BotFather, configuring it against a real HTTPS dev
endpoint, sending/receiving one real message, and confirming no token
appears anywhere in the network response, DB dump, or logs) is recommended
as a one-time manual sanity check before M02 begins building on this
transport, but is explicitly **not** a closure requirement and must not be
presented as one in the closure record, per ADR-0011.

---

## 10. Implementation work packages

Preceding all work packages: a **freeze commit** (docs-only, code-free)
containing this plan at `docs/plans/m01-telegram-connectivity-plan-v1.md`
and ADR-0012, ADR-0013, ADR-0014 at `docs/adr/0012-*.md`,
`docs/adr/0013-*.md`, `docs/adr/0014-*.md`, per `docs/governance.md`'s
Freeze model — not itself a work package, a prerequisite to WP1.

### WP1 — Migration: six new tables

- **Objective**: extend the existing migration framework with the full
  M01 schema, verified and reversible-on-failure exactly like the M00
  step.
- **Files modified**: `src/Persistence/Migrator.php` (add `step_2()`
  through `step_7()`, `verify_step_2()` through `verify_step_7()`, bump
  `target_version()` to `7`).
- **Files added**: none.
- **Files modified (tests)**: `tests/integration/Persistence/MigratorTest.php`
  (add cases for steps 2–7: fresh-install creates all tables; postcondition
  verification catches a simulated partial failure per step; re-running
  `maybe_migrate()` after a partial failure is safe/idempotent).
- **Validation commands**: `bin/docker/composer.sh install --no-interaction`;
  `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none (existing jobs cover this automatically).
- **Acceptance evidence**: all six tables present after a fresh
  `maybe_migrate()` run; `universal_telegram_db_version` option reads `7`;
  phpcs/phpstan clean; integration suite green on both WP configurations.
- **Planned commit message**: `feat(persistence): add six-table Telegram schema (bots, destinations, outbound messages, inbound updates, circuit breaker, rate limit state) (WP1)`

### WP2 — Bot profile domain and repository

- **Objective**: bot profile CRUD with `CredentialVault`-backed token and
  webhook-secret storage; first real files under `src/Telegram/`.
- **Files added**: `src/Telegram/Configuration/BotProfile.php`,
  `src/Telegram/Configuration/BotStatus.php`,
  `src/Telegram/Configuration/BotProfileRepository.php`.
- **Files modified**: `tests/unit/Core/StructuralBoundariesTest.php`
  (permit `Telegram` directory; continue asserting absence of `Events`,
  `Automations`, `Conversations`, `ChatWidget`, `AI`).
- **Files added (tests)**: `tests/unit/Telegram/Configuration/BotProfileTest.php`,
  `tests/integration/Telegram/Configuration/BotProfileRepositoryTest.php`.
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-unit.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: `StructuralBoundariesTest` green with
  `Telegram` now permitted; repository round-trips a bot profile with
  ciphertext verified non-plaintext; a repository-level token replace
  overwrites the stored ciphertext unconditionally — this repository
  performs no remote validation, since `TelegramApiClient` does not exist
  until WP4; validate-before-commit behavior against a real `getMe` call
  is admin-facing and is added in WP10, the first work package where both
  the admin action and the API client are available. `BotProfileRepository`
  also exposes pure-data read/write/promote operations on the pending
  webhook-secret fields added in WP1 (no HTTP involved — generating,
  storing, promoting, or discarding a pending secret is always a database
  write, never itself a network call), used by WP7's verifier and WP10's
  registration/rotation coordinator, plus a
  `count_stale_unresolved_registrations(int $threshold_hours): int` read
  method consumed by WP10's stale-rotation diagnostic alert.
- **Planned commit message**: `feat(telegram): add bot profile domain and credential-vault-backed repository (WP2)`

### WP3 — Destinations

- **Objective**: destination CRUD (private/group/supergroup/channel,
  optional `message_thread_id`), owned by a bot.
- **Files added**: `src/Telegram/Configuration/Destination.php`,
  `src/Telegram/Configuration/DestinationKind.php`,
  `src/Telegram/Configuration/DestinationRepository.php`.
- **Files added (tests)**: `tests/unit/Telegram/Configuration/DestinationTest.php`,
  `tests/integration/Telegram/Configuration/DestinationRepositoryTest.php`.
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-unit.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: validation rejects `message_thread_id` on any
  destination kind other than `supergroup` (`private`, `group`, and
  `channel` are all rejected — M01 scopes forum-topic support to
  supergroup destinations only; see §6, §8); unique `(bot_id, chat_id,
  message_thread_id)` constraint enforced.
- **Planned commit message**: `feat(telegram): add destination domain (private, group, supergroup, channel, forum topic) (WP3)`

### WP4 — Telegram API client and failure classification

- **Objective**: a thin, testable HTTP wrapper for `getMe`, `sendMessage`,
  `setWebhook`, `deleteWebhook`, plus the classifier that turns a raw
  response into `RETRYABLE` / `TERMINAL` / `RATE_LIMITED` / `TOKEN_INVALID`.
- **Files added**: `src/Telegram/Client/TelegramApiClient.php`,
  `src/Telegram/Client/TelegramApiResult.php`,
  `src/Telegram/Client/TelegramApiException.php`,
  `src/Telegram/Client/TelegramFailureClassifier.php`,
  `src/Telegram/Client/FailureClassification.php`.
- **Files added (tests)**: `tests/unit/Telegram/Client/TelegramFailureClassifierTest.php`,
  `tests/unit/Telegram/Client/TelegramApiClientTest.php` (using
  `pre_http_request` to fake every response shape: 200 success, 400 chat
  not found, 401 unauthorized, 403 forbidden, 429 with a valid
  `parameters.retry_after` and 429 with an absent/invalid value
  exercising the fallback wait, 500, and a raw network-transport-level
  `WP_Error` — the last of these is asserted to be the *only* shape that
  sets the ambiguous-outcome signal consumed by WP6/WP8's
  `possible_duplicate_delivery` handling, since it is the only case where
  no HTTP response was received at all).
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-unit.sh`.
- **CI/job changes**: none.
- **Acceptance evidence**: classifier maps every one of the seeded
  response shapes to the correct classification; client never throws on a
  well-formed error response (only on a truly malformed one), matching
  `CredentialVault::decrypt()`'s "never throw on ordinary failure" style
  used elsewhere in the codebase.
- **Planned commit message**: `feat(telegram): add Telegram Bot API client and failure classifier (WP4)`

### WP5 — Rate limiter and circuit breaker primitives

- **Objective**: standalone, independently testable reliability primitives,
  not yet wired to the send path.
- **Files added**: `src/Telegram/Reliability/RateLimiter.php`,
  `src/Telegram/Reliability/CircuitBreaker.php`,
  `src/Telegram/Reliability/CircuitBreakerState.php`,
  `src/Telegram/Reliability/CircuitOpenException.php`.
- **Files added (tests)**: `tests/unit/Telegram/Reliability/RateLimiterTest.php`
  (deterministic clock injection, mirroring `RetryPolicy`'s test seam
  style), `tests/integration/Telegram/Reliability/CircuitBreakerTest.php`.
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-unit.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: token bucket refills correctly by elapsed time;
  breaker opens/half-opens/closes per §5.3's exact thresholds; cooldown
  escalation demonstrably reuses `Queue\RetryPolicy::delay_seconds()`
  (asserted by matching the exact expected sequence 60, then
  `RetryPolicy`-computed values).
- **Planned commit message**: `feat(telegram): add per-bot/per-destination rate limiter and circuit breaker (WP5)`

### WP6 — Outbound message store, send handler, queue wiring

- **Objective**: the core send path — durable pre-dispatch storage, opaque
  `JobEnvelope`, handler registration, composition-root wiring.
- **Files added**: `src/Telegram/Outbound/OutboundMessage.php`,
  `src/Telegram/Outbound/OutboundMessageStatus.php`,
  `src/Telegram/Outbound/OutboundMessageRepository.php`,
  `src/Telegram/Outbound/MessageDispatcher.php`,
  `src/Telegram/Outbound/SendMessageHandler.php`.
- **Files modified**: `src/Core/Plugin.php` (construct and wire
  `BotProfileRepository`, `DestinationRepository`, `TelegramApiClient`,
  `RateLimiter`, `CircuitBreaker`, `OutboundMessageRepository`,
  `MessageDispatcher`, `SendMessageHandler`; register
  `$handler_registry->register('telegram_send_message', [...])`, following
  the exact `SelfTest` wiring sequence).
- **Files added (tests)**: `tests/unit/Telegram/Outbound/OutboundPayloadClassificationTest.php`,
  `tests/integration/Telegram/Outbound/SendMessageHandlerTest.php`,
  `tests/integration/Telegram/Outbound/MessageDispatcherTest.php`.
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-unit.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`;
  `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`.
- **CI/job changes**: none.
- **Acceptance evidence**: end-to-end faked-success send reaches `sent`
  status with `telegram_message_id` recorded; `JobEnvelope` payload proven
  content-free (WP6's own test plus WP4/WP1's contribute to the same
  proof); `Dispatcher::enqueue()` used exactly as-is, no modification.
- **Planned commit message**: `feat(telegram): add durable outbound message store and queue-integrated send handler (WP6)`

### WP7 — Inbound webhook route

- **Objective**: the REST webhook receiver — authenticity (including
  bounded active/pending dual-secret acceptance and traffic-based
  confirmation, per ADR-0013's rotation/registration protocol), replay
  protection, metadata-only receipt, fast acknowledgment.
- **Files added**: `src/Telegram/Inbound/WebhookController.php`,
  `src/Telegram/Inbound/WebhookSecretVerifier.php`,
  `src/Telegram/Inbound/UpdateRepository.php`,
  `src/Telegram/Inbound/UpdateType.php`.
- **Files modified**: `src/Core/Plugin.php` (construct/wire
  `WebhookSecretVerifier`, `UpdateRepository`, `WebhookController`;
  `add_action('rest_api_init', [$webhook_controller, 'register_routes'])`).
- **`WebhookSecretVerifier` behavior**: matches the incoming header
  against the bot's `active` secret first; if that fails and a `pending`
  secret is currently set (rotation in progress — **never time-bounded,
  never expired by this verifier or by any background task**), matches
  against `pending`. Two distinct confirmations follow, both via
  `BotProfileRepository`'s pure-data methods from WP2, neither requiring
  `TelegramApiClient` (WP4) or the admin controller (WP10):
  - A **`pending` match** authenticates the request **and** promotes
    `pending` to `active` (copies ciphertext, clears the pending fields),
    sets `webhook_registration_state = 'registered'`, sets
    `webhook_registered_at`, sets bot `status = 'active'` if it was
    `unconfigured`, and records
    `telegram_webhook_secret_rotation_confirmed_via_traffic`.
  - An **`active` match while `webhook_registration_state = 'uncertain'`
    and no `pending` secret exists** (the initial-registration case: the
    one and only secret matched, which is direct proof Telegram now has
    it, independent of whether the original `setWebhook` response was
    ever received) sets `webhook_registration_state = 'registered'`,
    sets `webhook_registered_at`, and records
    `telegram_webhook_registration_confirmed_via_traffic` — no ciphertext
    changes, since `active` was already correct.
  - An **`active` match while a `pending` secret still exists** (a
    rotation is in progress but Telegram is still using the old secret)
    changes no state at all — this is not evidence of anything; Telegram
    may simply not have applied the rotation yet, or may never receive
    it, and the pending secret remains available for retry/rollback
    exactly as before.
- **Files added (tests)**: `tests/integration/Telegram/Inbound/WebhookControllerTest.php`
  (secret rejection, replay/duplicate `update_id`, malformed JSON,
  oversized body, unsupported update type, forum-topic metadata capture),
  `tests/integration/Telegram/Inbound/WebhookSecretVerifierRotationTest.php`
  (dual acceptance while `pending` is set, with no time limit — a
  `pending` secret generated arbitrarily long ago is still accepted and
  still promotes on match; promotion and the correct audit action on a
  `pending`-matched request; the initial-registration traffic-confirmation
  case, with no `pending` secret involved; no state change on an
  `active`-matched request while `pending` still exists).
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: every rejection path in §9's webhook test list
  passes; a valid, correctly-signed, novel `update_id` request is
  acknowledged 200 and recorded exactly once; a replay of the same request
  is acknowledged 200 with no second row; a request authenticated via
  `pending` promotes it to `active` exactly once and is idempotent on
  repetition.
- **Planned commit message**: `feat(telegram): add authenticated, replay-protected inbound webhook route (WP7)`

### WP8 — Reliability wired into the send path; dead-letter lifecycle

- **Objective**: connect WP4's classifier and WP5's primitives to WP6's
  handler exactly per §5.3's decision tree; implement the dead-letter
  state transition and queue-health alert computation.
- **Files modified**: `src/Telegram/Outbound/SendMessageHandler.php`
  (integrate classifier, rate limiter, circuit breaker, dead-letter
  transition, non-throwing terminal/rate-limit/circuit-open paths,
  terminal-attempt detection via `RetryPolicy::max_attempts()`; set
  `outbound_messages.possible_duplicate_delivery = 1`, once, whenever a
  send attempt fails with a network-transport-level error — no HTTP
  response received at all — per ADR-0014's at-least-once delivery
  guarantee).
- **Files added**: `src/Telegram/Reliability/QueueHealthAlert.php`.
- **Files added (tests)**: `tests/integration/Telegram/Reliability/RateLimiterDeferralTest.php`,
  `tests/integration/Telegram/Outbound/DeadLetterLifecycleTest.php`,
  `tests/integration/Telegram/Reliability/QueueHealthAlertTest.php`,
  `tests/integration/Telegram/Outbound/DuplicateDeliverySignalTest.php`
  (a faked network-transport `WP_Error` on attempt 1 followed by a faked
  200 success on attempt 2 leaves the message `sent` **and**
  `possible_duplicate_delivery = 1`; a faked clean HTTP 500 followed by a
  faked 200 success leaves `possible_duplicate_delivery = 0`, since a
  definite HTTP error response is not evidence of an ambiguous outcome).
- **Files modified (tests)**: extend
  `tests/integration/Telegram/Reliability/CircuitBreakerTest.php` (from
  WP5) with the full send-path-integrated open/half-open/close scenarios
  described in §9.
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-unit.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`;
  `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`.
- **CI/job changes**: none.
- **Acceptance evidence**: all §9 reliability-mechanism tests green;
  `RetryPolicy`'s counted-attempt total is unaffected by any rate-limit or
  circuit-open deferral in the test scenarios (explicitly asserted, not
  just implied).
- **Planned commit message**: `feat(telegram): wire rate limiting, circuit breaking, and dead-letter handling into the send path (WP8)`

### WP9 — Retention cleanup job

- **Objective**: recurring purge of expired message bodies and expired
  delivery-log/inbound-update rows, per the new `Settings` retention
  fields.
- **Files added**: `src/Telegram/Outbound/RetentionCleanupHandler.php`.
- **Files modified**: `src/Core/Configuration/Settings.php` (add the five
  new fields from §8 to `defaults()`/`sanitize()`); `src/Core/Plugin.php`
  (register the handler; idempotently schedule a recurring action via
  `as_has_scheduled_action()` + `as_schedule_recurring_action()`, following
  Action Scheduler's own public API exactly as the rest of the plugin does).
- **Files added (tests)**: `tests/unit/Core/Configuration/SettingsTest.php`
  (modified — new field defaults/sanitization), `tests/integration/Telegram/Outbound/RetentionCleanupHandlerTest.php`.
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-unit.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: a message older than
  `telegram_message_retention_days` has `body_ciphertext` nulled but its
  metadata row intact; a row older than
  `telegram_delivery_log_retention_days` is deleted entirely; the
  recurring action is scheduled exactly once across repeated `init()`
  calls (idempotency check).
- **Planned commit message**: `feat(telegram): add retention-based cleanup of message content and delivery-log rows (WP9)`

### WP10 — Bot/destination admin management screen and webhook registration coordinator

- **Objective**: the admin surface satisfying "how an administrator
  configures, replaces, and tests credentials" and "connection testing" —
  create/edit/delete bots and destinations, replace token
  (validate-before-commit via `TelegramApiClient::get_me()`, now
  available from WP4), register a bot's webhook, rotate a bot's webhook
  secret through the failure-safe protocol (ADR-0013) with explicit
  retry/rollback resolution actions, run a synchronous `getMe` test,
  trigger a queued test-message send, view last-inbound-update-received
  and current `webhook_registration_state` per bot.
- **Files added**: `src/Administration/Telegram/BotManagementPage.php`,
  `src/Administration/Telegram/BotManagementController.php`,
  `src/Telegram/Configuration/WebhookRegistrationCoordinator.php`.
- **Files modified**: `src/Core/Plugin.php` (construct/wire, register
  `admin_menu` + `admin_post_*` actions, following `DiagnosticsPage`'s +
  `SelfTest`'s exact existing registration pattern); `src/Telegram/Reliability/QueueHealthAlert.php`
  (added in WP8 — extended here with a **stale-rotation diagnostic**
  condition, evaluated per bot with `webhook_registration_state = 'uncertain'`:
  if `webhook_secret_pending_ciphertext IS NOT NULL` (an uncertain
  **rotation**), staleness is measured from `webhook_secret_pending_since`;
  otherwise (an uncertain **initial registration** — no pending secret
  and, by definition at this state, no `webhook_registered_at` yet),
  staleness is measured from `webhook_last_attempt_at`.
  `created_at`/`updated_at` are never used as a proxy for either case. A
  bot whose applicable timestamp exceeds
  `telegram_webhook_rotation_max_pending_hours` contributes to the alert
  being active. This condition **only ever reads** state via
  `BotProfileRepository::count_stale_unresolved_registrations()` (WP2);
  it never writes to, discards, promotes, or replaces any secret or any
  other field).
- **`WebhookRegistrationCoordinator` behavior** — four distinct,
  separately invokable operations, never conflated. **Every one of the
  four sets `bots.webhook_last_attempt_at = now` unconditionally,
  immediately before evaluating its `setWebhook` call's outcome** —
  regardless of whether that outcome is a clean success, a clean
  rejection, or uncertain, and regardless of whether the operation sends
  the `active` or the `pending` secret:
  1. **`register(bot)`** — initial registration only, valid when
     `webhook_secret_pending_ciphertext IS NULL`. Sends the bot's
     existing `active` secret (decrypted via `CredentialVault`, the same
     secret generated at bot creation — **no new secret is ever
     generated by this operation**) via `TelegramApiClient::set_webhook()`.
     - **Clean success**: `webhook_registration_state = 'registered'`,
       `webhook_registered_at = now`, bot `status = 'active'`; record
       `telegram_webhook_registration_confirmed_immediate`.
     - **Clean, definite failure**: state stays/becomes `'unregistered'`;
       record `telegram_webhook_registration_rejected`. No split-brain is
       possible here — WordPress's accepted secret was never changed, so
       it is still exactly the one secret this call just sent, whether or
       not Telegram actually applied it.
     - **Uncertain outcome**: `webhook_registration_state = 'uncertain'`;
       record `telegram_webhook_registration_uncertain`. Still no
       split-brain risk (only one secret exists and it was never
       changed); the bot simply awaits either a retry or organic
       traffic-based confirmation (WP7).
  2. **`rotate(bot)`** — starts a **new** rotation, valid only when
     `webhook_secret_pending_ciphertext IS NULL` (no rotation already in
     progress — see `retry_pending()` below for that case). Generates a
     genuinely new secret, writes it to
     `webhook_secret_pending_ciphertext`/`webhook_secret_pending_since`
     via `BotProfileRepository` (WP2) — `active` untouched — records
     `telegram_webhook_secret_rotation_initiated`, then calls
     `TelegramApiClient::set_webhook()` with the new pending secret.
     - **Clean success**: promote `pending` to `active` immediately,
       clear the pending fields, `webhook_registration_state = 'registered'`,
       `webhook_registered_at = now`; record
       `telegram_webhook_secret_rotation_confirmed_immediate`.
     - **Clean, definite failure**: discard the pending fields
       immediately, `active` untouched, no dual-acceptance window is ever
       opened; record `telegram_webhook_secret_rotation_rejected`.
     - **Uncertain outcome**: leave both `active` and `pending` exactly
       as written — dual acceptance continues via WP7's verifier
       indefinitely; `webhook_registration_state = 'uncertain'`; record
       `telegram_webhook_secret_rotation_uncertain`.
  3. **`retry_pending(bot)`** — valid only when
     `webhook_secret_pending_ciphertext IS NOT NULL` (a rotation is
     already in progress and unresolved). Decrypts and resends the
     **existing, unmodified** pending secret — **never generates or
     substitutes a different one** — via `TelegramApiClient::set_webhook()`,
     records `telegram_webhook_secret_rotation_retry_attempted` before
     the call, then applies the identical three-outcome handling as
     `rotate()`'s success/failure/uncertain branches above (a clean
     success promotes *this* pending secret; a clean failure discards
     *this* pending secret; an uncertain outcome changes nothing).
  4. **`rollback(bot)`** — valid only when
     `webhook_secret_pending_ciphertext IS NOT NULL`. Calls
     `TelegramApiClient::set_webhook()` using the **existing `active`**
     secret (re-affirming it, not generating anything new). **Only a
     clean success** discards the pending fields (since Telegram has now
     confirmed it is using `active`), restoring
     `webhook_registration_state = 'registered'` and recording
     `telegram_webhook_secret_rotation_rollback_confirmed`. A **clean,
     definite failure** or an **uncertain outcome** both leave the
     pending fields completely untouched — `webhook_registration_state`
     stays `'uncertain'`, dual acceptance continues — recording
     `telegram_webhook_secret_rotation_rollback_failed` or
     `telegram_webhook_secret_rotation_rollback_uncertain` respectively.
     **No path through `rollback()` ever discards `pending` on anything
     less than a confirmed clean success.**

  Across all four operations: **no operation, and no background task
  anywhere in this plan, ever discards, replaces, or generates a
  different pending secret merely because time has passed or because an
  outcome was unknown.** The only ways a pending secret is ever cleared
  are (a) a clean `rotate()`/`retry_pending()` success promoting it, (b)
  a clean `rotate()`/`retry_pending()` failure discarding it (it was
  never accepted by anything, so nothing is lost), (c) a clean
  `rollback()` success discarding it, or (d) WP7's traffic-based
  confirmation promoting it. The admin screen (`BotManagementPage`)
  exposes exactly the operation valid for a bot's current state — never
  offering `rotate()` while a `pending` secret already exists (only
  `retry_pending()` and `rollback()` are offered then), and always
  labels an `'uncertain'` state distinctly from `'registered'`, never as
  implicitly healthy.
- **Files added (tests)**: `tests/integration/Administration/Telegram/BotManagementPageTest.php`,
  `tests/integration/Administration/Telegram/BotManagementControllerTest.php`
  (capability/nonce re-verification on every action; token-replace is
  rejected via a faked `getMe` failure with the old token left intact,
  and committed only after a faked `getMe` success),
  `tests/integration/Telegram/Configuration/WebhookRegistrationCoordinatorTest.php`
  — the six scenarios required by this milestone's Master Architect
  review, each using the `pre_http_request` fake seam from WP4:
  1. Clean initial registration: `register()` sends the bot's existing
     `active` secret and, on a faked clean success, immediately confirms
     (`webhook_registration_state = 'registered'`) with no `pending`
     secret ever created.
  2. Uncertain rotation followed by the stale threshold elapsing: after a
     faked network-transport failure on `rotate()`, both `active` and
     `pending` remain valid against `WebhookSecretVerifier` (cross-checked
     against WP7's verifier test), `webhook_registration_state` stays
     `'uncertain'` indefinitely with no automatic change, and once
     `webhook_secret_pending_since` (not `webhook_last_attempt_at`,
     `created_at`, or `updated_at`) exceeds
     `telegram_webhook_rotation_max_pending_hours` the `QueueHealthAlert`
     condition added to this WP becomes active — while the pending secret
     itself remains completely untouched. A companion assertion covers an
     uncertain **initial registration** (`register()` faked as
     network-transport-uncertain, no `pending` secret ever created):
     staleness is measured from `webhook_last_attempt_at` — set by
     `register()`'s unconditional pre-outcome write — and the alert
     activates on that basis alone once it exceeds the same threshold;
     `created_at`/`updated_at` are asserted to have no bearing on either
     case.
  3. Retry resends the identical pending secret: after an uncertain
     `rotate()`, `retry_pending()` is asserted (via the faked HTTP
     request capture) to send the exact same secret value generated in
     step 1, never a newly generated one.
  4. Explicit rollback only clears `pending` after a confirmed Telegram
     success: `rollback()` with a faked clean success discards `pending`
     and restores `'registered'`; `rollback()` with a faked failure or a
     faked uncertain (network-transport) outcome leaves `pending`
     completely intact in both cases.
  5. A failed rollback preserves both secrets: the faked-failure and
     faked-uncertain `rollback()` scenarios from (4) are each followed by
     a `WebhookSecretVerifier` check confirming **both** the `active` and
     the still-present `pending` secret continue to authenticate
     successfully.
  6. No automatic task can create a state where Telegram may use a
     secret WordPress rejects: an exhaustive review-style test asserts
     that `RetentionCleanupHandler`'s recurring pass (WP9, extended by
     this WP's `QueueHealthAlert` addition) never calls any
     `BotProfileRepository` write method on the pending-secret fields —
     only a read for the alert condition — proving no automated code
     path can discard or replace a pending secret.
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: unauthorized user denied by WordPress itself at
  the menu-registration capability gate; a forged direct POST without a
  valid nonce is rejected by the in-handler re-check; token/secret absent
  from every rendered screen (token-exposure test from §9); an uncertain
  `set_webhook` outcome is never displayed or recorded as `'registered'`;
  all six scenarios in the test list above pass; **an uncertain webhook
  rotation can no longer become a permanent authentication split-brain
  through automatic expiry or replacement of the pending secret** — this
  is verified directly by scenario 2 (indefinite dual acceptance, no
  automatic change) and scenario 6 (no automated write path exists at
  all).
- **Planned commit message**: `feat(administration): add Telegram bot and destination management screen with a failure-safe webhook registration/rotation coordinator, explicit retry and rollback, and no automatic pending-secret expiry (WP10)`

### WP11 — Diagnostics integration

- **Objective**: extend the existing diagnostics surface with Telegram
  health (bot/destination counts, dead-letter count, open-breaker count,
  recent inbound/outbound activity) and the queue-health alert banner —
  no new Diagnostics classes, only extensions of the existing two.
- **Files modified**: `src/Administration/Diagnostics/DiagnosticsReport.php`
  (add Telegram fields to `generate()`), `src/Administration/Diagnostics/DiagnosticsPage.php`
  (render the new section and the alert banner; register the
  `admin_notices` callback).
- **Files modified (tests)**: `tests/integration/Administration/Diagnostics/DiagnosticsPageTest.php`
  (extend with the new assertions from §9's queue-health-alert-visibility
  scenario).
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: banner present only when a real alert condition
  is seeded; absent otherwise; degraded-schema notice pattern unchanged
  and still fires correctly when schema is unavailable during this
  larger (7-step) migration.
- **Planned commit message**: `feat(administration): surface Telegram health and queue-health alerts on the diagnostics page (WP11)`

### WP12 — Uninstall integration

- **Objective**: extend uninstall to cover the six new tables and
  best-effort webhook deregistration, per §8's exact design.
- **Files modified**: `src/Core/Lifecycle/Uninstaller.php` (add
  best-effort per-bot `deleteWebhook` calls, unconditional; add
  conditional `DROP TABLE IF EXISTS` for the six new tables, gated on
  `remove_data_on_uninstall`).
- **Files modified (tests)**: `tests/integration/Core/Lifecycle/UninstallTest.php`
  (extend with the six new tables in both the default-retention and
  opt-in-retention scenarios, mirroring the existing audit-table
  assertions exactly; assert `deleteWebhook` is attempted regardless of
  retention setting, using the same `pre_http_request` fake seam as WP4).
- **Validation commands**: `bin/docker/phpcs.sh`; `bin/docker/phpstan.sh`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`.
- **CI/job changes**: none.
- **Acceptance evidence**: default-retention uninstall keeps all six
  tables; opt-in-retention uninstall drops all six; `deleteWebhook` is
  attempted in both cases and a failure never aborts the rest of
  uninstall.
- **Planned commit message**: `feat(lifecycle): extend uninstall with Telegram table cleanup and best-effort webhook deregistration (WP12)`

### WP13 — Documentation, versioning, package acceptance extension

- **Objective**: bring `ARCHITECTURE.md`, `CHANGELOG.md`, version
  constants, and the packaged-plugin acceptance script in line with the
  now-implemented `Telegram` boundary.
- **Files modified**: `docs/ARCHITECTURE.md` (Telegram boundary row →
  "Implemented", subdomain list); `CHANGELOG.md` (M01 entry);
  `universal-telegram.php` (`UNIVERSAL_TELEGRAM_VERSION` → `0.1.0`,
  per A10); `readme.txt` (stable-tag
  mirrors; adds an operator-facing "Delivery guarantees" description
  stating Telegram message delivery is at-least-once, not exactly-once,
  per ADR-0014, and that a `possible_duplicate_delivery` indicator in the
  delivery log flags a specific send whose outcome was ambiguous);
  `tests/package/run.sh` (add the bots-table existence check
  and default/opt-in retention checks for the new tables, mirroring the
  existing `audit_table_exists()` pattern exactly, plus a token-non-exposure
  `wp eval` smoke assertion on the bot management page render).
- **Files added**: none.
- **Validation commands**: `bin/docker/composer.sh run-script
  check-doc-links`; `bin/docker/build-zip.sh`;
  `bin/docker/test-package.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3`;
  `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3 --woocommerce=11.0.1`.
- **CI/job changes**: none (existing `build` and `package-acceptance`
  matrix jobs already run these scripts).
- **Acceptance evidence**: `check-doc-links` clean; all three
  package-acceptance configurations PASS with the new Telegram-specific
  assertions included.
- **Planned commit message**: `docs(m01): update architecture reference, changelog, and version; extend package acceptance testing (WP13)`

### WP14 — Requirements traceability finalization

- **Objective**: confirm every §12 traceability row is backed by a passing
  test from the completed WP1–WP13, as the last step before closure
  preparation (not before — traceability must reflect what was actually
  built, not what was planned).
- **Files modified**: none (this plan's own §12 table is the
  traceability instance; no separate file is created, matching M00's
  precedent of citing the plan's own section rather than a standalone
  traceability file).
- **Validation commands**: the full matrix from WP1–WP13 run once more,
  end to end, on the final commit before closure:
  `bin/docker/composer.sh install --no-interaction`; `bin/docker/phpcs.sh`;
  `bin/docker/phpstan.sh`; `bin/docker/test-unit.sh --php-version=8.1`;
  `bin/docker/test-unit.sh --php-version=8.3`;
  `bin/docker/test-unit.sh --php-version=8.4`;
  `bin/docker/test-integration-wp-only.sh --wp-version=6.9 --php-version=8.1`;
  `bin/docker/test-integration-wp-only.sh --wp-version=7.1 --php-version=8.3`;
  `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`;
  `bin/docker/build-zip.sh`; the three `bin/docker/test-package.sh` invocations
  from WP13.
- **CI/job changes**: none.
- **Acceptance evidence**: every command green on the same commit; this
  becomes the closure record's "Automated test results summary."
  merely notes the recorded facts.
- **Planned commit message**: none — this WP is a verification pass, not
  a code change; if any gap surfaces, it produces a new commit under the
  relevant WP's original scope, not a new WP.

---

## 11. Versioning impact

- `UNIVERSAL_TELEGRAM_VERSION`: `0.0.1` → `0.1.0` — resolved and decided
  by this plan (A10): a minor bump, since M01 is the first genuine
  functional capability beyond foundation scaffolding, not a fix.
- `universal_telegram_db_version`: `1` → `7` (six new migration steps).
- No Git tag is created by this plan (no tags exist yet in the repository;
  `docs/ARCHITECTURE.md` reserves the `vX.Y.Z` tag format for a future
  release, not per-milestone).
- No public PHP API, hook, or class contract existed before M01 to be
  broken; the one new public surface (the webhook REST route) is
  introduced, not changed, so no breaking-change consideration applies.

---

## 12. Requirements traceability

Charter requirement → Plan work package → Automated test(s):

- "Send and receive test messages" → WP6, WP7, WP10 →
  `SendMessageHandlerTest`, `WebhookControllerTest`,
  `BotManagementControllerTest` (test-message-send action)
- "Webhook authenticity is validated" → WP7, WP10 → `WebhookControllerTest`
  (secret rejection scenarios), `WebhookSecretVerifierRotationTest`,
  `WebhookRegistrationCoordinatorTest` (registration/rotation split-brain
  resistance, no automatic expiry)
- "The plugin recovers from temporary Telegram failures" → WP8 →
  temporary-failure-recovery integration test (§9)
- "No token reaches browser output" → WP2, WP10, WP13 →
  `BotProfileTest`, `BotManagementPageTest` (token-exposure assertion),
  `tests/package/run.sh` token-non-exposure smoke check
- "The circuit breaker activates under sustained simulated failure" →
  WP5, WP8 → `CircuitBreakerTest` (open transition)
- "Dead-lettered messages are retained and inspectable" → WP8, WP10 →
  `DeadLetterLifecycleTest`, `BotManagementControllerTest` (requeue action)
- "A queue-health alert fires on a stalled queue" → WP8, WP11 →
  `QueueHealthAlertTest`, `DiagnosticsPageTest` (banner visibility)
- "A fresh installation requires only WordPress, the plugin, and Telegram
  bot credentials" → WP1–WP13 → full `tests/package/run.sh` matrix (WP13)
- "No separately deployed application is needed for bidirectional
  Telegram communication" → WP6, WP7 → `SendMessageHandlerTest`,
  `WebhookControllerTest` (both run entirely inside the WordPress
  integration-test bootstrap, no external process)

---

## 13. Complete Definition of Done

- All six migration steps (WP1) present, verified, and reachable from a
  fresh install with no manual intervention.
- Multiple bot profiles, each with independently encrypted token and
  webhook secret, each addressable via its own opaque webhook route
  (WP2, WP7).
- Multiple destinations per bot across all four kinds (private, group,
  supergroup, channel), including forum-topic (`message_thread_id`)
  destinations (WP3).
- Outbound sends exclusively queued (never synchronous), with message
  content durably stored and encrypted outside the queue payload (WP6).
- Inbound webhook authenticated via constant-time secret comparison,
  replay-protected via `update_id` uniqueness, fast-acknowledging,
  metadata-only, with no conversation store implemented (WP7).
- Webhook secret registration and rotation are failure-safe against an
  uncertain remote outcome: a bot's active secret is never desynchronized
  from what was sent to Telegram; rotation's active/pending dual-secret
  acceptance persists until explicitly resolved (retry, rollback, or
  observed traffic — never an assumed outcome, and never an automatic
  timer); an unresolved registration or rotation past a configurable age
  (measured from `webhook_last_attempt_at` or `webhook_secret_pending_since`
  respectively — never `created_at`/`updated_at`) surfaces only as a
  stale-rotation diagnostic alert, never as an automatic discard,
  replacement, or promotion of any secret (WP1, WP2, WP7, WP10).
- Outbound delivery is explicitly documented, in both ADR-0014 and
  operator-facing docs, as at-least-once — a network-transport-level
  ambiguous send failure sets a stable, permanent
  `possible_duplicate_delivery` diagnostic flag rather than an unearned
  exactly-once claim (WP8, WP13).
- Rate limiting, circuit breaking (bot- and destination-scoped), and
  dead-letter handling fully wired into the send path, with rate-limit
  and circuit-open deferrals never consuming the generic retry budget
  (WP5, WP8).
- Queue-health alerting surfaced as a local WordPress admin notice and
  diagnostics-page section, never as a Telegram message (WP8, WP11).
- Admin can create/edit/delete bots and destinations, replace a token,
  rotate a webhook secret, run a connection test, send a test message,
  and requeue or inspect a dead-lettered message — all capability- and
  nonce-gated, all free of token/secret/plaintext-message exposure
  (WP10).
- Uninstall cleanly removes all Telegram state when
  `remove_data_on_uninstall` is `true`, and best-effort deregisters every
  bot's webhook from Telegram regardless of that setting (WP12).
- `docs/ARCHITECTURE.md`, `CHANGELOG.md`, version constants, and
  `tests/package/run.sh` all reflect the completed milestone (WP13).
- Every item in §12's traceability table is backed by a green test on the
  final commit (WP14).
- `bin/docker/phpcs.sh`, `bin/docker/phpstan.sh` clean; full unit +
  integration (×3 configurations) + package-acceptance (×3
  configurations) matrix green.
- Closure record drafted per `docs/closure/milestone-closure-template.md`,
  citing this plan's freeze-commit SHA, "Not applicable for M01 per
  ADR-0011" for the Vlad-acceptance field, and listing ADR-0012,
  ADR-0013, ADR-0014 under "ADRs introduced."

---

## 14. Full text of every proposed ADR

### ADR-0012 — Telegram Bot Cardinality, Webhook Routing, and Outbound Delivery Architecture

**Status**: Proposed

**Context**: M01's charter requires "bot configuration" and "multiple
Telegram destinations" without stating explicitly whether a single
WordPress installation may configure more than one Telegram bot;
master-plan.md's product vision separately names "multiple bots" as an
eventual capability. During drafting of this plan, the Product Owner
resolved this ambiguity explicitly: M01 must support multiple, generic,
interchangeable bot profiles per installation, with no functional role
assigned to any bot by M01 itself. Separately, M00's `Queue\JobEnvelope`
enforces a fail-closed payload-classification policy (docs/adr/0006) under
which any `SENSITIVE`- or `SECRET`-classified field, or any unclassified
field, causes construction to fail immediately — meaning a decrypted bot
token or raw outbound message text can never be placed in a queued job's
payload, since both are at minimum `SENSITIVE`.

**Decision**:
- A WordPress installation may configure any number of independent
  Telegram bot profiles. Each bot profile owns, independently: its own
  encrypted token, its own encrypted webhook secret, its own webhook route
  identity, its own set of destinations, its own outbound delivery log,
  its own dead-letter records, its own rate-limiter state, and its own
  circuit-breaker state. No cross-bot sharing of any of the above exists.
- Each bot profile is assigned a random, opaque, non-secret UUID
  (`bot_uuid`) at creation time, generated once and never regenerated for
  the lifetime of the profile. This UUID identifies the bot in its
  webhook URL path (a WordPress REST API route,
  `universal-telegram/v1/webhook/{bot_uuid}`). The UUID is not a secret —
  it selects which bot profile a request is *for*; Telegram's own
  `secret_token`/`X-Telegram-Bot-Api-Secret-Token` header, verified via
  `CredentialVault` and `hash_equals()`, is the sole authentication
  mechanism (see ADR-0013 for full webhook-authenticity design). The bot
  token itself never appears in any URL.
- Outbound message content is written to a dedicated, `CredentialVault`-encrypted
  table (`universal_telegram_outbound_messages`) **before** any queue job
  is constructed. The `Queue\JobEnvelope` built for a send contains only
  an opaque `message_uuid` and `INTERNAL`-classified `bot_id`/`destination_id`
  metadata — never the message text, never the token. The registered
  handler re-reads and decrypts the message row at execution time, using
  the message's own `CredentialVault` context binding
  (`telegram.outbound_message:{message_uuid}`) so a ciphertext cannot be
  substituted between rows even if two rows' raw bytes were somehow
  swapped.
- Reliability state (rate limiting, circuit breaking) is keyed by
  `(scope_type, scope_id)` where `scope_type` is `bot` or `destination`.
  A failure affecting one bot's token, or one specific destination, never
  throttles or trips the breaker for an unrelated bot or destination. Full
  reliability mechanics are the subject of a separate ADR (ADR-0014); this
  ADR establishes only the per-bot/per-destination isolation principle and
  the schema keys (`bot_id`, `destination_id`) that make it enforceable.

**Alternatives**:
- *Single bot per installation.* Rejected by explicit Product Owner
  decision during this milestone's planning; would also foreclose the
  master-plan's stated long-term multi-bot vision without a later,
  more disruptive superseding ADR and schema migration.
- *Encode bot identity in the secret token rather than the URL path
  (i.e., look up the bot by trying to match the incoming secret against
  every bot's decrypted secret).* Rejected: forces a linear scan and
  repeated decryption across every configured bot for every inbound
  request, scales poorly, and conflates the concerns of "which bot is
  this for" (fine to be public) and "prove you are Telegram" (must stay
  secret) into a single value.
- *Use `admin-ajax.php` instead of a WordPress REST API route for the
  webhook.* Rejected: the REST API is WordPress's current, documented
  mechanism for a structured public JSON endpoint with clean request/response
  typing (`WP_REST_Request`/`WP_REST_Response`), and is the pattern this
  plan's other read/write concerns (admin screens) do not need, keeping
  the one genuinely public endpoint architecturally distinct from the
  capability-gated `admin-post.php` actions.
- *Place a reference to the message content directly in the `JobEnvelope`
  payload after classifying it `INTERNAL` instead of `SENSITIVE`.*
  Rejected outright: message text is not internal operational metadata,
  it is user- or business-facing content that may contain personal or
  commercially sensitive information; misclassifying it to route around
  `JobEnvelope`'s fail-closed check would defeat the purpose of that
  check entirely.

**Consequences**: every later milestone that sends a Telegram message
(M02's rule-engine "Telegram action", M08's administrative bot, M11's
digests) constructs a durable `OutboundMessage` row and dispatches only an
opaque reference, following this same pattern, rather than inventing a
parallel content-carrying dispatch path. Any future milestone wanting to
narrow multi-bot support back to a single implicit "default" bot for
convenience (e.g., a simplified setup wizard) may do so as a UI
convenience without a schema change, since the schema already supports
zero-or-more bots uniformly. A genuinely different bot-identity model
(e.g., allowing a single bot to be shared read-only across sites) would
require a new superseding ADR.

**Security and privacy impact**: this ADR is the architectural foundation
that keeps bot tokens and message content out of Action Scheduler's own
(unencrypted, longer-retained) storage entirely, extending — not
weakening — ADR-0006's and ADR-0008's existing security boundaries to a
milestone that, for the first time, has a real secret and real content to
protect.

**Affected Documents/Milestones**: M02 (Telegram send action must
construct an `OutboundMessage` + opaque envelope, not its own dispatch
path); M08 (administrative bot commands are a subdomain of this same
`Telegram` boundary and must reuse this same bot-profile/destination
model); M11 (digest delivery is another Telegram send, same pattern).

**Compatibility/Migration Impact**: none — no Telegram schema or code
exists before this milestone.

---

### ADR-0013 — Telegram Webhook Authenticity, Replay Protection, and Inbound Handling

**Status**: Proposed

**Context**: M01's charter requires the inbound webhook be
"authenticity-verified" and that "no separately deployed application" be
required for bidirectional communication — meaning WordPress itself must
receive, authenticate, and safely acknowledge Telegram's webhook POSTs.
Telegram does not sign webhook bodies conventionally; it instead offers an
optional `secret_token` parameter on `setWebhook`, echoed back on every
subsequent webhook delivery as the `X-Telegram-Bot-Api-Secret-Token`
header. Telegram separately documents `update_id` as sequential and
explicitly recommends it as the mechanism for ignoring repeated updates.
M01's charter also explicitly excludes conversation/chat functionality
(M05 onward) and instructs that M01 "must not silently implement a
conversation store."

**Decision**:
- Every bot profile is issued its own webhook secret at creation:
  48 hexadecimal characters (`bin2hex(random_bytes(24))`), satisfying
  Telegram's documented `secret_token` constraint of "1-256 characters.
  Only characters `A-Z`, `a-z`, `0-9`, `_` and `-` are allowed." The
  secret is encrypted at rest via the existing `CredentialVault`, context
  `telegram.webhook_secret:{bot_uuid}`, and is never displayed in any
  admin screen after creation (only replaceable via an explicit "rotate"
  action, per ADR-0012's admin-surface design).
- The webhook route, `universal-telegram/v1/webhook/{bot_uuid}` (WordPress
  REST API, registered on `rest_api_init`), verifies every incoming
  request by: (1) resolving `bot_uuid` to a bot profile — if none is
  found, fail; (2) reading the `X-Telegram-Bot-Api-Secret-Token` header —
  if absent, fail; (3) decrypting the bot's stored webhook secret via
  `CredentialVault::decrypt()` — if the result is not `AVAILABLE`, fail;
  (4) comparing the header value to the decrypted secret with
  `hash_equals()` — if unequal, fail. **Every failure mode in this list
  produces the identical generic 401 response, with no field or message
  distinguishing which of the four checks failed** — this is deliberate,
  preventing an attacker from using differential responses to enumerate
  valid `bot_uuid`s or narrow down a secret-guessing attempt.
- Replay/duplicate protection uses Telegram's own `update_id`: each
  accepted request is inserted into `universal_telegram_inbound_updates`
  under a `UNIQUE(bot_id, update_id)` database constraint. A duplicate
  insert (the same update delivered twice, whether by a genuine Telegram
  retry after a slow/failed prior acknowledgment, or by a replay attempt
  using a captured request) is detected via the resulting constraint
  violation and answered with the same 200 acknowledgment as a first-time
  receipt, without any reprocessing — Telegram must never be told to keep
  retrying something already durably recorded, and a replayed request
  must never have any observable side effect beyond what the original
  request already had.
- Input handling: the request body is size-capped (default 1 MiB,
  configurable) and rejected with 413 **before** JSON-decoding is
  attempted if it exceeds the cap; a body that fails to parse as JSON is
  rejected with a fixed generic 400 message (the raw body is never echoed
  in the response and never written to any log); a body that parses but
  is missing an integer `update_id` is rejected 400; an update whose type
  is outside the small M01-supported set (`message`, `edited_message`,
  `channel_post`, `edited_channel_post`) is still deduplicated and stored
  with `update_type = 'unsupported'`, then acknowledged 200 (Telegram must
  never be made to retry-storm a type this milestone does not yet act on).
- The entire handler performs only bounded, synchronous, low-cost work —
  one header check, one size check, one JSON decode, one uniquely-keyed
  insert — before returning. No Telegram API call, no queue dispatch, and
  no heavy processing occurs inside the webhook request, satisfying both
  Telegram's documented behavior of retrying non-2xx responses and the
  charter's constraint against placing sensitive update content in Action
  Scheduler arguments (trivially satisfied: inbound updates are never
  routed through the queue at all in M01).
- What is recorded from an inbound update is **metadata only**: chat ID,
  message-thread ID (if present), update type, and receipt timestamp.
  **No message text is ever persisted, logged, or otherwise retained by
  M01.** This is the explicit, narrow inbound behavior the charter
  requires in place of a conversation store: sufficient for
  deduplication, audit evidence, and a connection-test signal ("last
  inbound update received at TIMESTAMP" per bot, surfaced in the bot
  management screen), and nothing more.
- **Webhook secret registration and rotation are failure-safe against an
  uncertain remote outcome, with no automatic expiry of a pending
  secret.** Every bot row is created with exactly one active webhook
  secret (`bots.webhook_secret_ciphertext`) — **there is no bot state
  with no active secret**, so the schema itself rules out a "which
  secret" ambiguity for a bot that has never been registered. Every one
  of the four operations below (`register`, `rotate`, `retry_pending`,
  `rollback`) unconditionally sets `bots.webhook_last_attempt_at = now`
  immediately before evaluating its `setWebhook` call's outcome,
  regardless of which secret it sent or what that outcome turns out to
  be. Two genuinely different operations exist, and this ADR deliberately
  does not conflate them:
  - **Initial registration** (`register()`) sends the bot's one existing
    **active** secret to Telegram's `setWebhook` — it never generates or
    stores a second secret. Because WordPress's accepted secret is never
    changed by this operation, **no split-brain is possible regardless of
    the response outcome**: a clean success confirms it
    (`bots.webhook_registration_state = 'registered'`); a clean, definite
    failure simply leaves the bot unregistered
    (`'unregistered'`, unchanged from its default) with nothing to
    reconcile; an uncertain outcome (network-transport failure, timeout,
    or any response `Telegram\Client\TelegramFailureClassifier` cannot
    confidently classify as one of the first two) sets
    `'uncertain'` for diagnostic visibility only — `WebhookSecretVerifier`
    (WP7) was already, and remains, correctly configured to accept the
    one secret that was sent, whether or not Telegram ever actually
    applied it. An uncertain registration has no `pending` secret and, by
    definition of not yet being `'registered'`, no `webhook_registered_at`
    either — its staleness for the diagnostic alert below is therefore
    measured from `webhook_last_attempt_at`, the only timestamp available
    for this case.
  - **Rotation** (`rotate()`, `retry_pending()`, and `rollback()`, all of
    which send the **pending** secret except `rollback()`, which
    re-sends **active**) genuinely introduces a second secret, replacing an
    already-working `active` one, and is where a real split-brain risk
    exists: if WordPress assumes an uncertain rotation succeeded and
    discards the old secret, but Telegram never actually applied the new
    one, inbound traffic is permanently rejected; if WordPress assumes it
    failed and discards the new one, but Telegram did apply it, the
    identical permanent rejection results from the opposite cause. This
    is prevented by a **bounded-scope, indefinitely-persistent**
    active/pending dual-secret model:
    - Starting a rotation writes a genuinely new secret to a
      **`pending`** slot (`bots.webhook_secret_pending_ciphertext`,
      `bots.webhook_secret_pending_since`), encrypted via
      `CredentialVault`, distinct from `active`, which is never modified
      at this point.
    - `WebhookSecretVerifier` (WP7) accepts a request matching **either**
      the `active` secret **or**, whenever one is currently set, the
      `pending` secret. **This acceptance has no time limit and is never
      revoked by the passage of time** — only by one of the explicit
      resolution paths below, or by traffic-based confirmation.
    - The `setWebhook` call's outcome is handled as exactly **three**
      distinct cases, never collapsed to two: a **clean success**
      promotes `pending` to `active` and clears the pending slot
      immediately; a **clean, definite failure** discards the pending
      slot immediately, `active` untouched, no window is ever opened; an
      **uncertain outcome** leaves both slots exactly as they were, sets
      `webhook_registration_state = 'uncertain'` (displayed as such,
      never as `'registered'` or implicitly healthy), and **changes
      nothing else** — no timer, no background task, and no code path
      anywhere in this milestone is permitted to later discard, replace,
      or promote that pending secret on the basis of elapsed time or an
      unresolved outcome. The only four ways a pending secret is ever
      cleared are: (a) a **retry** — resending the byte-identical
      pending secret (never a freshly generated one) to `setWebhook`,
      whose own clean success promotes it exactly as an initial
      rotation's would; (b) an explicit **rollback** — calling
      `setWebhook` with the existing `active` secret, and **only a
      confirmed clean success** from that call discards the pending
      secret (a rollback that fails cleanly, or whose outcome is itself
      uncertain, leaves both secrets completely untouched — a failed
      resolution attempt must never destroy the state it was trying to
      resolve); (c) **traffic-based confirmation** (below); or (d) a
      later retry/rollback attempt succeeding.
    - **Confirmation via observed traffic**: if `WebhookSecretVerifier`
      ever authenticates a request specifically against the `pending`
      secret — direct proof Telegram is actually using it, independent
      of whether any `setWebhook` response was ever received by
      WordPress at all — it promotes `pending` to `active` at that
      moment, sets `webhook_registration_state = 'registered'`, and
      records `telegram_webhook_secret_rotation_confirmed_via_traffic`.
      The same principle, applied to the no-pending-secret case, also
      confirms an initial registration stuck at `'uncertain'`: an
      `active`-secret match while no `pending` secret exists and the
      state is not already `'registered'` promotes the state to
      `'registered'` directly (no ciphertext change is needed, since
      `active` was already correct), recording
      `telegram_webhook_registration_confirmed_via_traffic`.
    - **No automatic expiry, for either operation.** Any bot with
      `webhook_registration_state = 'uncertain'` — whether from an
      unresolved rotation (a `pending` secret is set; staleness measured
      from `webhook_secret_pending_since`) or an unresolved initial
      registration (no `pending` secret; staleness measured from
      `webhook_last_attempt_at`, as established above) — past the
      configurable `telegram_webhook_rotation_max_pending_hours` (default
      24) is surfaced **only** as a stale-rotation administrator
      diagnostic alert (an extension of
      `Telegram\Reliability\QueueHealthAlert`, WP10) — a read-only
      condition. `created_at` and `updated_at` are never used as a proxy
      for either case; the correct case-specific timestamp is always
      used. **No code in this milestone writes to, discards, replaces, or
      promotes a pending secret — or an active one — on the basis of this
      threshold; it exists solely to make an unresolved registration or
      rotation visible to an administrator, who then resolves it through
      a retry or a rollback.**
  - **Audit actions** introduced by this protocol —
    `telegram_webhook_registration_confirmed_immediate`,
    `telegram_webhook_registration_rejected`,
    `telegram_webhook_registration_uncertain`,
    `telegram_webhook_registration_confirmed_via_traffic`,
    `telegram_webhook_secret_rotation_initiated`,
    `telegram_webhook_secret_rotation_confirmed_immediate`,
    `telegram_webhook_secret_rotation_confirmed_via_traffic`,
    `telegram_webhook_secret_rotation_rejected`,
    `telegram_webhook_secret_rotation_uncertain`,
    `telegram_webhook_secret_rotation_retry_attempted`,
    `telegram_webhook_secret_rotation_rollback_confirmed`,
    `telegram_webhook_secret_rotation_rollback_failed`,
    `telegram_webhook_secret_rotation_rollback_uncertain` — each carries
    an explicit, complete classification map recording only
    `bot_id`/timestamps/state names (`INTERNAL`), never any secret
    plaintext or ciphertext.

**Alternatives**:
- *Verify authenticity via Telegram's IP address ranges instead of the
  secret header.* Rejected: Telegram does not publish a stable,
  official IP allowlist for webhook delivery, and header-based
  verification is the mechanism Telegram's own documentation provides
  specifically for this purpose.
- *Store the full raw update JSON (including any message text) for
  future-proofing, redacting only at display time.* Rejected: contradicts
  the charter's explicit instruction against silently implementing a
  conversation store, and violates privacy-by-default — storing content
  "just in case" for a boundary (Conversations) that has not yet been
  designed or approved is exactly the kind of premature scope the
  governance model exists to prevent.
- *Route inbound updates through the queue for asynchronous processing,
  matching the outbound pattern.* Rejected for M01: nothing in this
  milestone's scope needs asynchronous inbound processing (there is no
  conversation logic to run), so introducing a queued inbound path now
  would be speculative infrastructure with no real consumer, to be
  reconsidered when M05 actually needs it.
- *On an uncertain `setWebhook` outcome, assume success and immediately
  swap to the new secret only.* Rejected: if the response was lost after
  Telegram actually failed to apply the change, this permanently locks
  out inbound traffic (Telegram keeps using the old secret, WordPress now
  rejects it) with no automatic recovery path.
- *On an uncertain `setWebhook` outcome, assume failure and immediately
  discard the new secret, keeping only the old one.* Rejected: if the
  response was lost after Telegram actually did apply the change, this
  produces the identical permanent lockout from the opposite direction
  (Telegram now uses the new secret, WordPress only accepts the old one).
- *Query Telegram's `getWebhookInfo` to check which secret is currently
  active after an uncertain outcome.* Rejected: `getWebhookInfo` does not
  and cannot echo back the currently configured secret (Telegram never
  returns a previously-set secret to the caller), so it cannot resolve
  the ambiguity this protocol needs to resolve — only observing which
  secret real inbound traffic actually authenticates with can.
- *Automatically discard an unresolved pending secret after a fixed
  timeout (an earlier draft of this ADR proposed exactly this, with a
  24-hour ceiling).* Rejected on Master Architect review: a timeout is
  evidence of nothing about whether Telegram actually applied the
  rotation — discarding the pending secret on a timer risks the identical
  split-brain this entire protocol exists to prevent, merely delayed by
  the timeout's duration instead of triggered immediately. Elapsed time
  is treated as a **prompt to alert an administrator**, never as grounds
  to change which secrets are accepted or which secret is stored.
- *Generate a fresh pending secret on every retry attempt.* Rejected:
  each new secret would open a new, independent race with Telegram's own
  processing of the previous attempt, multiplying — not resolving — the
  ambiguity; retrying must resend the exact secret already pending so
  that a delayed success response to an *earlier* attempt still resolves
  correctly.

**Consequences**: M05 (conversation backend), when it exists, will need
its own ADR to introduce actual message-content storage and almost
certainly a queued inbound processing path once real logic must run
against received messages — this ADR does not pre-empt that design, it
deliberately stops short of it. M08's administrative bot commands, when
built, will extend `WebhookController`'s update-type handling (adding
recognized command-message types) rather than replacing the authenticity/replay
mechanism established here. Any later milestone that ever needs to
rotate or replace a different kind of inbound-verified secret (none is
currently planned) should follow this same pattern rather than inventing
a new one: indefinite active/pending dual acceptance until resolved by an
explicit retry (resending the identical pending value), a confirmed
rollback, or organic traffic confirmation, plus a read-only stale-condition
administrator alert past a configurable age — never an automatic
discard, replacement, or promotion of anything on a timer.

**Security and privacy impact**: this ADR is M01's primary inbound
security boundary — constant-time secret comparison, uniform failure
responses preventing an authentication oracle, replay protection via a
database uniqueness constraint (not a time-window heuristic, which could
be bypassed by an attacker replaying within the window), and a hard
architectural commitment to never storing message content are all
deliberate hardening decisions, not incidental ones.

**Affected Documents/Milestones**: M05 (conversation backend — will need
its own ADR before storing any message content or introducing queued
inbound processing); M08 (administrative bot — extends
`WebhookController`'s recognized update/command types, reuses this
authenticity mechanism unchanged).

**Compatibility/Migration Impact**: none — no webhook endpoint exists
before this milestone.

---

### ADR-0014 — Telegram Provider Reliability Policy: Rate Limiting, Circuit Breaking, Dead-Letter, and Queue-Health Alerting

**Status**: Proposed

**Context**: M01's charter requires "retry and rate limiting," "circuit
breaking," "dead-letter handling," and "queue-health alerting" for the
Telegram transport, explicitly scoped as Telegram-specific reliability
mechanisms layered on top of M00's entirely generic `Queue\RetryPolicy`
(bounded attempts, exponential backoff with jitter, no provider
awareness — ADR-0006). Telegram's Bot API reference does not publish a
stable, contractual numeric rate limit; its FAQ page gives qualitative,
example-based guidance instead (see the plan's §6 for exact citations),
explicitly hedged with words like "about" and describing tolerance for
short bursts. `Queue\WorkerRunner` treats any handler that does not throw
as a fully successful attempt, and always rethrows any exception a handler
does raise after recording a generic audit entry and consulting
`RetryPolicy` — there is no partial-success or "retry me differently"
signal available to a handler through that contract.

**Decision**:
- **Failure classification** (`Telegram\Client\TelegramFailureClassifier`),
  computed from a `TelegramApiClient` response's HTTP status and
  Telegram's own `error_code`/`description` fields, external to
  `Queue\RetryPolicy` entirely:
  - `RATE_LIMITED` (HTTP 429): the handler defers by directly calling
    Action Scheduler's own public `as_schedule_single_action()` for a
    fresh attempt at `now + wait_seconds` (honoring a documented
    flood-control wait duration when Telegram's response provides one, or
    a conservative configurable fallback when it does not), then returns
    **without throwing**. This deferral does not count toward
    `RetryPolicy`'s bounded attempt budget and does not affect any
    circuit-breaker state (throttling is expected, routine behavior, not
    a signal of unavailability).
  - `TERMINAL` (e.g. chat not found, bot blocked/kicked, invalid forum
    topic): the handler transitions the message to `dead_letter`
    immediately, itself, records a Telegram-specific audit entry with the
    fixed failure code, and returns **without throwing** — no further
    `RetryPolicy` attempts are wasted on an error that cannot succeed on
    retry, and no circuit-breaker state changes (a single bad destination
    is not evidence Telegram itself, or the bot's token, is unavailable).
  - `TOKEN_INVALID` (HTTP 401): the handler opens the **bot-scope**
    circuit breaker with no automatic half-open probe scheduled (an
    invalid token does not self-heal with elapsed time), sets the bot
    profile's `status` to `invalid`, dead-letters the current message, and
    returns without throwing. Only an explicit administrator token-replace
    action (which itself validates the new token via `getMe` before
    committing) clears this state.
  - `RETRYABLE` (network error, timeout, HTTP 5xx): the handler rethrows,
    letting `WorkerRunner`'s existing, entirely unmodified sequence
    (generic audit entry → `RetryPolicy::should_retry()` →
    reschedule-or-terminal) run exactly as it does for any other job type.
    This classification additionally counts as one consecutive failure
    toward both the bot-scope and destination-scope circuit breakers,
    checked in that order — the destination-scope breaker is never
    consulted while the bot-scope breaker is already open, avoiding a
    redundant second deferral decision layered on top of a known,
    broader outage. When the handler determines — via
    `Queue\RetryPolicy::max_attempts()`, the existing public method, never
    a duplicated constant — that the current attempt is the last permitted
    one, it performs the same dead-letter transition and Telegram-specific
    audit entry as the `TERMINAL` case before still rethrowing, so
    `WorkerRunner`'s own generic terminal-failure audit entry is also
    recorded (a deliberate, harmless redundancy: one generic entry, one
    with Telegram-specific delivery context).
- **Rate limiting** (`Telegram\Reliability\RateLimiter`): a token bucket
  per `(scope_type, scope_id)`, refilled by elapsed-time delta at each
  check (correct regardless of Action Scheduler's actual scheduling
  granularity). Configurable defaults, informed by but not asserted as
  equal to Telegram's own published figures (per the plan's A11): 1
  message/second per destination (all kinds); an additional 20
  messages/minute cap specifically for `group`/`supergroup`-kind
  destinations; 20 messages/second aggregate per bot across all its
  destinations (deliberately conservative relative to Telegram's ~30/sec
  example figure). A bucket with no available token causes the same
  non-throwing, budget-preserving deferral as `RATE_LIMITED` above.
- **Circuit breaker** (`Telegram\Reliability\CircuitBreaker`): two
  independent scopes, `bot` and `destination`, each with its own `closed`
  / `open` / `half_open` state row. Configurable defaults: bot-scope opens
  after 5 consecutive `RETRYABLE` failures within a 10-minute observation
  window, first half-open cooldown 60 seconds, escalating on repeated
  failed probes by reusing `Queue\RetryPolicy::delay_seconds()` (a
  deliberate reuse of the existing generic exponential-backoff-with-jitter
  primitive rather than a duplicated implementation), capped at 900
  seconds; exactly one trial send permitted while `half_open`; a
  successful probe closes the breaker and resets its consecutive-failure
  count. Destination-scope uses a lower threshold, 3 consecutive
  `RETRYABLE` failures in the same window, with otherwise identical
  mechanics — a narrower blast radius should be visible sooner.
- **Unbounded-deferral safety bound**: independent of rate-limit or
  circuit-breaker deferral, every deferral decision also checks a
  message's total pending age against a configurable ceiling (default 24
  hours); a message older than the ceiling is dead-lettered immediately,
  regardless of remaining `RetryPolicy` attempts or breaker state,
  preventing indefinite pileup during an extended outage.
- **Dead-letter representation**: a lifecycle status
  (`outbound_messages.status = 'dead_letter'`) rather than a separate
  table, with a fixed stable `dead_letter_reason` code (never raw
  Telegram error text) and timestamp. Content ciphertext is retained
  while dead-lettered (not purged), enabling an administrator-triggered
  "Requeue" action (fresh `job_id`, `attempt` reset, `status` reset to
  `pending`) without requiring the message to be retyped. Retained per a
  configurable delivery-log retention period before an automated cleanup
  job removes the row entirely.
- **Queue-health alert** (`Telegram\Reliability\QueueHealthAlert`, built
  on the existing `Queue\QueueHealth` read surface rather than duplicating
  its counting logic): considered active when any of — a non-zero
  dead-letter count, any circuit breaker in the `open` state, or any
  message pending longer than a configurable staleness threshold (default
  30 minutes) — holds true. Surfaced exclusively through local WordPress
  admin surfaces: an extension of the existing diagnostics page/report,
  and a capability-gated `admin_notices` banner shown site-wide in
  wp-admin, cached briefly (60 seconds) to bound its query cost.
  **Deliberately never implemented as a Telegram message** — the
  transport being alerted on may itself be the thing that is failing.
- **Delivery guarantee: at-least-once, not exactly-once.** A
  network-transport-level failure while calling `sendMessage` — no HTTP
  response received at all (connection reset, DNS failure, a request
  timeout), as distinct from a definite HTTP error response — leaves it
  genuinely unknown whether Telegram received and processed the request
  before the failure occurred. Because the generic `RetryPolicy`'s
  bounded-attempt retry (or this same ADR's rate-limit/circuit-breaker
  deferral) may cause a subsequent attempt to send the identical logical
  message again, **a message that experiences this specific failure mode
  may be delivered to its destination more than once.** M01 does not
  attempt to suppress this possibility — the Bot API's `sendMessage`
  method accepts no client-supplied idempotency key Telegram could use to
  deduplicate on its own end — and does not claim a delivery guarantee
  stronger than what the underlying mechanism actually provides.
  Instead, `outbound_messages.possible_duplicate_delivery` is set once
  (and never cleared) whenever this specific ambiguous failure mode is
  observed for a message, giving administrators a stable, accurate
  diagnostic signal in the delivery log in place of a false impression of
  exactly-once delivery; `telegram_message_id` records only the
  identifier from the last confirmed successful response, never proof
  that no earlier, unconfirmed attempt also reached the destination. This
  guarantee — and the meaning of the `possible_duplicate_delivery`
  indicator — is stated explicitly in the plugin's operator-facing
  documentation (`readme.txt`, WP13), not left implicit.

**Alternatives**:
- *A single, installation-wide circuit breaker instead of per-bot/per-destination.*
  Rejected: would let one misconfigured or blocked destination on one bot
  silently stop delivery for every other bot and destination, directly
  contradicting the per-bot isolation principle established in
  ADR-0012.
- *Treat rate-limit and circuit-open deferrals as ordinary `RetryPolicy`-counted
  failures.* Rejected: would consume the same bounded 5-attempt,
  ~30-minute-total budget meant for genuine unexpected failures on
  entirely expected, routine throttling, causing messages to be
  prematurely dead-lettered during predictable, recoverable rate-limit
  or short-outage conditions.
- *Modify `Queue\RetryPolicy` or `Queue\WorkerRunner` to add
  provider-specific awareness (e.g., a "do not count this attempt"
  return value).* Rejected: ADR-0006 established `RetryPolicy` as
  deliberately, permanently generic; this milestone's reliability needs
  are fully satisfiable by composing behavior *around* the existing
  generic contract (as this ADR does), so modifying M00's foundation is
  unnecessary and would set a precedent of provider-specific creep into
  shared infrastructure that later providers (e.g., M09's AI providers)
  would then also be tempted to lean on.
- *Alert via a Telegram message to a configured "admin" destination.*
  Rejected outright by the task's own explicit instruction and by basic
  correctness: if the Telegram transport is the thing failing, an alert
  sent through that same transport may never arrive.
- *A dedicated dead-letter table, separate from `outbound_messages`.*
  Rejected: would duplicate the encrypted content column and its
  retention/purge logic across two tables for no benefit; a status value
  plus two additional columns on the existing table is simpler and keeps
  one authoritative row per message throughout its entire lifecycle.
- *Claim exactly-once delivery, or attempt to engineer it (e.g. by
  querying `getChat`/message history to check whether a prior attempt's
  message already arrived before sending again).* Rejected: the Bot API
  provides no reliable, race-free mechanism for a bot to confirm its own
  earlier, ambiguous send actually landed before deciding whether to
  retry, and attempting to approximate one would add real complexity and
  latency to purchase only a heuristic, not an actual guarantee — an
  honest at-least-once guarantee with a stable diagnostic signal is more
  trustworthy than an unearned exactly-once claim.

**Consequences**: M09 (AI-provider dispatch) will very likely want an
analogous classifier/circuit-breaker/dead-letter layer for its own
provider; this ADR's pattern (compose around the generic `RetryPolicy`
rather than modify it, reuse `RetryPolicy::delay_seconds()` for
breaker-cooldown math, express provider-specific "do not retry further"
as a non-throwing handler return rather than a new `WorkerRunner` signal)
is the demonstrated precedent it should follow, though M09 remains free to
propose its own superseding or sibling ADR if its provider's failure
semantics genuinely differ. Per ADR-0004, M12's v1.0 hardening/failure-injection
validation is scoped to exactly the mechanisms this ADR defines.

**Security and privacy impact**: dead-letter retention of encrypted
content (rather than immediate erasure) is a deliberate operator-recovery
tradeoff, mirroring ADR-0008's identical choice for credential ciphertext
on decryption failure — recoverable is safer than silently lost, provided
the content stays encrypted and access stays capability-gated, both of
which this ADR and ADR-0012 jointly guarantee.

**Affected Documents/Milestones**: M09 (AI-provider reliability
mechanisms — likely precedent, not a binding requirement); M12 (v1.0
hardening gate — failure-injection validation scoped to exactly the
mechanisms defined here, per ADR-0004).

**Compatibility/Migration Impact**: none — no Telegram reliability
mechanism of any kind exists before this milestone.

---

## 15. Final consistency validation

**Cross-checks performed**:
- Every file listed in §10's work packages is either newly added or
  modified by exactly one work package; no file is left unassigned, and
  no wildcard paths were used anywhere in this plan.
- Every table in §8 has a corresponding migration step in WP1 and a
  corresponding uninstall-cleanup line in WP12.
- Every new class referenced in §5's architectural narrative appears in
  some work package's file list in §10.
- Every acceptance criterion in the M01 charter appears in §12's
  traceability table with at least one concrete test reference from §9.
- No modification to `Queue\JobEnvelope`, `Queue\Dispatcher`,
  `Queue\WorkerRunner`, `Queue\RetryPolicy`, `Queue\HandlerRegistry`,
  `Core\Security\CredentialVault`, `Privacy\Classification`,
  `Privacy\Redactor`, `Audit\AuditLogger`, or `Core\Capabilities\CapabilityRegistrar`
  appears anywhere in §10 — confirmed consistent with §4's stated
  intent to reuse, not alter, M00's generic foundation.
- No CI workflow file change is proposed anywhere in §10, consistent with
  §9's finding that directory-suffix auto-discovery already covers every
  new test file.
- The `retry_after` field cited in §5.3/§6/ADR-0014 is stated as a
  confirmed Bot API field (`parameters.retry_after`), with no remaining
  deferred-verification language anywhere in the plan.
- Token-replace validate-before-commit behavior is assigned only to WP10
  (where `TelegramApiClient` from WP4 is available); WP2's
  `BotProfileRepository` performs no remote validation anywhere in this
  plan — confirmed consistent across §3 (A-series), §9, and §10 (WP2, WP4,
  WP10).
- The webhook secret registration/rotation protocol's active/pending
  schema columns (§8, WP1) are consumed consistently by WP7's verifier
  (indefinite dual acceptance, promotion via traffic — no expiry logic of
  any kind lives in WP7) and WP10's coordinator (`register`, `rotate`,
  `retry_pending`, `rollback`, and a read-only stale-rotation diagnostic
  extension of `QueueHealthAlert`, WP8) — no code path anywhere in this
  plan discards, replaces, or promotes a pending secret on the basis of
  elapsed time, and no path treats an uncertain outcome as either a
  success or a failure; every reference to the prior 24-hour
  automatic-discard design has been replaced with the alert-only,
  no-expiry protocol throughout §5.2, §8, §9, §10 (WP2, WP7, WP10), §13,
  and ADR-0013.
- Initial registration is described consistently, in every section that
  mentions it, as sending the bot's one pre-existing active secret with
  no pending secret ever created for that operation — no remaining text
  anywhere in this plan describes a first registration as having "no
  prior active secret" or as itself entering a `pending`/dual-acceptance
  state.
- `message_thread_id` support is restricted to `kind = 'supergroup'`
  destinations consistently across §6 (research/citation), §8 (schema
  narrative), and WP3 (validation and its test); no destination kind
  other than `supergroup` is described anywhere in this plan as accepting
  a forum-topic identifier.
- The at-least-once delivery guarantee and the
  `possible_duplicate_delivery` diagnostic signal are stated consistently
  across ADR-0014, §8 (schema), WP8 (handler behavior and tests), and
  WP13 (operator-facing `readme.txt` documentation).
- No numeric default anywhere in §5.3, §8, or ADR-0014 is described as
  pending confirmation, tentative, or open — every one is stated as a
  frozen M01 default, configurable through `Settings` (A11).
- The version-bump target (§3 A10, §11) is stated as a single resolved
  decision (`0.0.1` → `0.1.0`) with no remaining phrasing presenting it as
  an open choice for the Architect or Product Owner to make.
- No feature from `docs/future-scope.md` (chat attachments, draggable
  launcher, generic webhook action, admin-bot write commands, write-capable
  AI tools, nested OR condition groups) appears anywhere in this plan.
- No event capture, rule evaluation (M02), WooCommerce-specific event
  coverage (M03), conversation/chat storage (M05+), AI (M09+), or
  administrative bot command parsing (M08) appears anywhere in this plan,
  consistent with the M01 charter's explicit exclusions.
- ADR numbering (0012, 0013, 0014) is sequential and does not reuse any
  of the eleven reserved numbers (0001–0011) confirmed by `docs/adr/README.md`.
- All three proposed ADRs follow the exact eight-section structure
  (`docs/adr/README.md`): Status, Context, Decision, Alternatives,
  Consequences, Security and privacy impact, Affected
  Documents/Milestones, Compatibility/Migration Impact.

### Unresolved decisions

None remain. This revision (see the Revision History note at the end of
this section) resolved every item previously listed here:

- The `retry_after` field is confirmed
  (`ResponseParameters.parameters.retry_after`, §6) and every deferred-verification
  caveat has been removed from §5.3, §6, and ADR-0014.
- The version-bump target is decided (`0.0.1` → `0.1.0`, A10, §11) rather
  than left open.
- Every numeric reliability/retention/stale-rotation-alert default is
  stated as a frozen M01 default, configurable through `Settings` (A11),
  not a pending choice — and, per revision 2 below, no numeric default in
  this plan ever triggers an automatic discard, replacement, or promotion
  of a webhook secret; the rotation-related default is an alert threshold
  only.
- The token-replace validation ordering (WP2 vs. WP4/WP10), the webhook
  secret registration/rotation split-brain-resistant protocol (with no
  automatic expiry — revision 2), the at-least-once delivery guarantee,
  and the `message_thread_id` destination-kind scope are each fully
  specified with concrete schema, work-package, and test assignments
  (§8, §9, §10, ADR-0013, ADR-0014) — none is a placeholder or an open
  design question.

### Recommendation

**Ready for Master Architect re-review, with no unresolved architectural
or product-decision blocker remaining in this plan.** No modification to
any M00 foundation class's generic contract is required anywhere in this
plan (§4, §10) — M00's queue, migration framework, credential vault,
audit/privacy, capability model, and diagnostics pattern are confirmed
sufficient to build M01 entirely through extension. **An uncertain
webhook rotation can no longer become a permanent authentication
split-brain through automatic expiry or replacement of the pending
secret** — no code path or background task anywhere in this plan
discards, replaces, or generates a substitute for a pending secret on
the basis of elapsed time or an unresolved outcome; resolution happens
only through an explicit retry (resending the identical pending secret),
an explicit rollback (clearing pending only on a confirmed clean
success), or organic traffic-based confirmation, with an unresolved
rotation surfaced solely as a read-only diagnostic alert.

### Revision history

- **v1, initial draft**: as submitted for first Master Architect review.
- **v1, revision 1**: applied six required Master Architect corrections —
  (1) resolved the `retry_after` field as a confirmed Bot API fact,
  removing all WP4-deferred-verification language; (2) corrected
  WP2/WP4/WP10 ordering so token-replace validation is never claimed
  before `TelegramApiClient` exists; (3) added an explicit,
  split-brain-resistant webhook secret rotation and registration protocol
  (active/pending dual-secret acceptance, confirm-via-traffic, a
  since-superseded bounded-expiry design — see revision 2) to ADR-0013,
  §8's schema, and WP7/WP10; (4) added an explicit at-least-once outbound
  delivery guarantee to ADR-0014, §8's schema, WP8, and WP13's
  operator-facing documentation; (5) restricted `message_thread_id`
  destination support to `supergroup` only, correcting the prior
  private/channel-only rejection language to also exclude `group`,
  consistently across §6, §8, and WP3; (6) resolved every
  previously-open "unresolved decision" (version-bump target, numeric
  reliability defaults) as a decided part of this plan rather than an
  item awaiting confirmation.
- **v1, revision 2** (this version): corrected a blocker the Master
  Architect found in revision 1's webhook registration/rotation protocol
  — (1) initial registration now sends the bot's one pre-existing active
  secret and never creates a pending secret merely because it is a first
  registration (removed all "no prior active secret" framing, since the
  schema guarantees an active secret exists from bot creation); (2)
  rotation's three-way `setWebhook` outcome handling is unchanged in
  spirit but a retry now always resends the byte-identical pending
  secret, never a freshly generated one; (3) **removed the 24-hour
  automatic-discard behavior entirely** — an unresolved pending secret is
  now retained and accepted indefinitely, with the same configurable
  threshold repurposed as a read-only stale-rotation administrator
  diagnostic alert (extending `QueueHealthAlert`, WP10) that never
  writes to any secret field; (4) added an explicit **rollback** action
  (`WebhookRegistrationCoordinator::rollback()`) that re-affirms the
  active secret and discards `pending` only on a confirmed clean success,
  leaving both secrets intact on any failed or uncertain rollback
  attempt; (5) removed every remaining contradictory statement — there is
  now exactly one definition of clean-success behavior per operation, no
  statement that a clean initial-registration success remains pending,
  and no reference to a first registration having "no prior active
  secret"; (6) added the six required test scenarios (WP10) proving
  indefinite dual acceptance past the stale threshold, identical-secret
  retry, rollback's success-gated and failure-preserving behavior, clean
  immediate initial-registration confirmation, and the absence of any
  automated write path to the pending-secret fields. Sections touched:
  §5.2, §8 (schema, Settings), §9, §10 (WP2, WP7, WP10), §13, ADR-0013
  (Decision, Alternatives), §15.

No branch, commit, code, ADR file, dependency, release, or PR was created
or modified in producing either revision — only this plan file, in both
passes.

**Confirmation**: no repository changes were made in the preparation of
this plan. All investigation was read-only (`git status`, `git log`,
`git fetch --all --tags --prune`, `git rev-parse`, and `Read` of
documentation, ADR, and source files). No branch was created, no file was
written inside `/opt/biopentra/dev/universal-telegram`, no dependency was
installed, no credential was generated, and no Telegram API call was made.
