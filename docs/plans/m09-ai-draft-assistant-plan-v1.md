# M09 — AI Draft Assistant — Implementation Plan v1

Status: Frozen — implementation authorized (revision 4). This document is self-contained; inline "revision 2"/"revision 3"/"revision 4" markers below are retained as revision history, matching this repository's existing plan-revision convention (see `docs/plans/m07-operator-workflow-plan-v1.md` and `docs/plans/m08-administrative-bot-commands-plan-v1.md`).

# Context

Product Owner authorized M09 planning for `universal-telegram` (AI Draft Assistant): an operator-assist-only AI drafting capability that must never auto-send to a visitor/Telegram. This is a **planning-only** task — no repository files may be created or modified. The deliverable is a single standalone plan document to `docs/plans/m09-ai-draft-assistant-plan-v1.md`, now frozen and implementation-authorized.

This is **revision 2**, a correction pass over the first draft. Five gaps were identified by review and are resolved below: (1) the acknowledgement mechanism was a silent timestamp, not a visitor choice — replaced with an explicit, unchecked-by-default checkbox with a server-validated contract; (2) an ambiguous "source-restricted toggle" implied a non-source-grounded fallback mode — removed, M09 is unconditionally source-only; (3) draft lifecycle/concurrency/idempotency were underspecified — a full state machine and exact limits are now defined; (4) persistence design left an open question (dedicated table vs. generic settings) — resolved to one design with complete DDL-level detail; (5) ADR-0028's full text was deferred — it is included in full below, now.

**Revision 3** is a further focused correction pass: (a) the concurrency-cap and one-active-draft locking design used an aggregate `COUNT(*) FOR UPDATE` that does not reliably serialize — replaced with an exact singleton-row-lock and conversation-row-lock design; (b) worker-crash / retryable-failure recovery was undefined, risking a row stranded forever in `generating` — a generation-lease/claim mechanism is added, with an explicit, honest at-least-once (not exactly-once) provider-invocation guarantee; (c) the cooldown rule contradicted itself (30s after discard vs. no cooldown after discard) — resolved to one rule: cooldown applies only after `failed`, never after an explicit discard. A cache-safe widget configuration detail for the public acknowledgement text/version is also added.

**Revision 4** is a final focused correction pass: (a) the lease design had no durable trigger that would actually cause an expired `generating` row to be looked at again after a crash — a bounded, idempotent stale-lease sweep job on the existing Action Scheduler recurring-action mechanism is added, with an honest, precisely-scoped reliability guarantee (bounded scheduling/recovery, not "provider is always called"); (b) the structural rule "`AiDraftRepository` is read only by `Administration\AI\*`" was factually wrong, since the AI worker/request classes themselves must read and write it — replaced with the real allow-list rule and a matching structural-test design.

# Verified Baseline

- Repository: `universal-telegram`, branch `main`, HEAD `a5bfa2a` (matches M08 technical closure), working tree clean.
- `db_version` = 18, plugin version `0.10.0`.
- M05 (`Conversations`, ADR-0021) and M07 (operator workflow, ADR-0026) are technically closed PASS; M08 acceptance pending (does not block M09 planning).
- The `AI` boundary (`UniversalTelegram\AI`) is empty — ADR-0005 reserves it for M09.
- Next ADR number: **0028**.

# 1. Visitor Acknowledgement — Explicit and Enforceable

**Widget interaction.** When (and only when) AI processing is enabled site-wide, the chat widget renders one additional control above the message input on first load of a conversation: an **unchecked** checkbox — "I agree my messages may be reviewed with AI assistance to help support staff respond faster" — with a link to the disclosure text. The visitor may send their first message with the box **unchecked**; nothing blocks them. The box is read once, at the moment the first message is submitted, and is not re-shown or re-askable later in the same conversation (no mid-conversation opt-in).

**Server contract.** The conversation-creation request (the existing endpoint that creates a `universal_telegram_conversations` row on first message, in both logged-in and anonymous modes) gains one new field:

| Field | Type | Default | Server behavior |
|---|---|---|---|
| `ai_ack` | boolean | `false` | Accepted only as literal `true`/`false`; any other value is treated as `false` (fail-closed) |

Server-side, at conversation-creation time only:
- If AI is disabled site-wide → `ai_ack_policy_version` is always `NULL`, regardless of `ai_ack`.
- If AI is enabled and `ai_ack === true` → `ai_ack_policy_version` is set to the provider config's current `ack_policy_version` at that instant.
- If AI is enabled and `ai_ack !== true` (including omitted/malformed) → `ai_ack_policy_version` is `NULL`; the conversation proceeds normally as a support conversation, permanently ineligible for AI drafts.
- This is the **only** write path to `ai_ack_policy_version`. No later request, message, or admin action ever sets or upgrades it for an existing conversation.

**Logged-in vs. anonymous.** Both modes use the identical `ai_ack` field on the identical conversation-creation call already used by each mode today (ADR-0021/M07's existing dual-mode conversation-creation path is unchanged in shape, just gains one boolean). No new identifier, cookie, session, or fingerprint is introduced for either mode — the acknowledgement is a property of the conversation row, not of the visitor.

**Existing conversations.** Any conversation row created before this field existed, or before AI was enabled, has `ai_ack_policy_version = NULL` and is never backfilled or inferred — permanently ineligible. If an administrator later edits the disclosure text (bumping `ack_policy_version`), conversations acknowledged under the prior version also become ineligible (version must match exactly) — a visitor must start a new conversation to re-acknowledge under the new text.

# 2. Approved-Source Policy — Source-Only, No Toggle

The ambiguous `source-restricted` toggle from draft 1 is removed entirely. **M09 always requires an approved-source match; there is no general-knowledge fallback mode, configurable or otherwise.**

- Eligible sources: published, non-password-protected posts/pages, explicitly approved via post meta, with an `approved_revision_id` (captured `post_modified_gmt`) validated fresh at retrieval time — a source edited since approval is excluded until re-approved.
- If zero approved sources match the query (see below), the draft job stores `status='failed'`, `failure_class='no_matching_source'`, and **the provider is never called.**
- **Query derivation (no operator search surface):** the operator does not type or supply any query. `AIDraftGenerationHandler` derives the retrieval query automatically and only from the conversation's own data: the text of the visitor's most recent inbound message in that conversation, run through a fixed internal normalizer (lowercase, stopword strip, tokenize) inside `ApprovedContentRepository::top_matches()`. This method takes no free-text parameter from any request — its only caller-supplied input is the internal `conversation_id`, from which it reads the last visitor message itself. This forecloses any path by which an operator (or a future UI) could turn this into an arbitrary content search tool.

# 3. Draft Lifecycle, Concurrency, Recovery, and Idempotency

**State machine.**

| State | Entered when | Exits to |
|---|---|---|
| `queued` | `DraftRequestHandler` accepts a request and inserts the row | `generating` (a worker claims it), or `failed` (rejected before any claim, e.g. concurrency cap exhausted past a retry budget — see below) |
| `generating` | A worker claims the row (atomic claim + lease, see below) | `generated` (success), `queued` (retryable failure — lease released), `failed` (terminal classification, token-invalid, circuit already open, or retries exhausted) |
| `generated` | Provider returned a bounded, valid draft | `reviewed`, `discarded` |
| `failed` | No matching source / terminal provider error / token-invalid / circuit-open / retries exhausted | Terminal — a new request creates a **new** `draft_uuid` row |
| `reviewed` | Operator opens/reads the draft and marks it reviewed | `approved`, `discarded` |
| `approved` | Operator marks the draft approved (audit trail only — **never triggers any send**) | Terminal |
| `discarded` | Operator discards a `generated`/`reviewed` draft, or an admin cancels an in-flight one | Terminal |

## 3.1 Locking design (race-safe)

Two independent locks are used, on **existing** rows only — no new lock table. Each transaction type acquires exactly one of them, so no deadlock ordering conflict exists between the two paths (they never contend for the same row in different orders); the rule "acquire the singleton config-row lock before any draft-row operation" additionally fixes the internal order within the worker path itself.

**A. One-active-draft-per-conversation — lock the conversation row.**
`DraftRequestHandler::request()`, in one transaction:
1. `SELECT id FROM universal_telegram_conversations WHERE id = ? FOR UPDATE` — locks the owning conversation row (it already exists; this never waits on a row that might be absent, unlike locking a not-yet-inserted draft row).
2. While holding that lock: `SELECT draft_uuid, status FROM universal_telegram_ai_drafts WHERE conversation_id = ? AND status IN ('queued','generating') LIMIT 1`. If found, return its `draft_uuid` (idempotent — see 3.3) and commit without inserting.
3. Otherwise, evaluate eligibility/cooldown (§3.2), and if eligible, `INSERT` a new `queued` row, commit.

Because the conversation row is always locked first and released only at commit, two concurrent requests for the same conversation serialize on step 1, and the second one always observes the first one's insert in step 2 — no duplicate `queued` row is possible.

**B. Site-wide concurrency cap — lock the singleton config row.**
The singleton `universal_telegram_ai_config` row (`id=1`) is **seeded during migration** (step 19 inserts the single row with `enabled=0` and defaults) so it always exists for locking, before any config is ever saved by an admin. `AIDraftGenerationHandler`'s claim step, in one transaction:
1. `SELECT id FROM universal_telegram_ai_config WHERE id = 1 FOR UPDATE` — the global generation-admission mutex. Every worker claim attempt across the whole site serializes on this single row lock.
2. While holding it: `SELECT COUNT(*) FROM universal_telegram_ai_drafts WHERE status = 'generating' AND generation_lease_expires_at > NOW()` (a plain read is sufficient here — mutual exclusion is already guaranteed by the config-row lock, so no separate `FOR UPDATE` on the count is needed). Rows with an **expired** lease do not count against the cap (§3.3) — they are eligible for reclaim, not double-counted as active.
3. If the count is `< 2`: select one eligible candidate row — `status='queued'`, or `status='generating' AND generation_lease_expires_at <= NOW()` (an expired, reclaimable lease) — ordered by `created_at`, `FOR UPDATE` on that specific row (a real, existing row at this point, never an absent one); set `status='generating'`, a fresh `lease_token` (new UUID), `generation_lease_expires_at = NOW() + 90s`, `claimed_at = NOW()`, `attempt_count = attempt_count + 1`; commit.
4. If the count is `= 2`: release the config-row lock (commit/rollback the read-only transaction) without claiming anything; the job is deferred, not failed (§ lifecycle table) — `WorkerRunner` re-attempts on its normal polling/backoff schedule, no `failed` status, no `failure_class` recorded.

**Fixed transaction order, stated once:** the config-row lock (B) is always acquired, if at all, before any draft-row `FOR UPDATE`, and a single transaction never holds both a conversation-row lock (A) and the config-row lock (B) — path A (request-time) never touches the config row, and path B (claim-time) never touches the conversation row. This makes cross-path deadlock structurally impossible, not just unlikely.

## 3.2 Cooldown — one rule only

- **30-second cooldown applies only after a draft reaches `failed`.** A new request for that conversation is rejected with a safe "please wait" response until 30 seconds after the `failed` row's `updated_at`.
- **No cooldown after an explicit operator discard.** The instant a `generated`/`reviewed` draft is discarded, the conversation immediately has zero active/retained drafts and a new request may be made right away.
- **No fresh request while a `generated`/`reviewed`/`approved` draft is retained**, regardless of its age — `DraftRequestHandler` rejects with "discard the current draft first," never a timed cooldown, until the operator explicitly discards it.

This is the only cooldown rule in the plan; no other section states a conflicting figure.

## 3.3 Recovery semantics (worker crash / retryable failure)

Schema additions to `universal_telegram_ai_drafts` (folded into the same migration step that creates the table — see §4): `lease_token` (CHAR(36) NULL), `generation_lease_expires_at` (DATETIME NULL), `claimed_at` (DATETIME NULL), `attempt_count` (INT UNSIGNED NOT NULL DEFAULT 0).

- **Claim** (§3.1-B) sets a fresh `lease_token`, `generation_lease_expires_at = NOW() + 90s` (comfortably longer than the 30s provider timeout plus margin), `claimed_at = NOW()`, increments `attempt_count`.
- **Success write** is a compare-and-set: `UPDATE universal_telegram_ai_drafts SET status='generated', body_ciphertext=?, generated_at=NOW(), lease_token=NULL, generation_lease_expires_at=NULL WHERE id=? AND lease_token=?`. If zero rows are affected (the lease was reclaimed by another worker after expiring — this worker was merely slow, not crashed), the write is silently discarded as stale; the row's current (reclaiming) owner is authoritative. **Stale worker completion can never overwrite a newer claim.**
- **Retryable failure**: before rethrowing to the existing `RetryPolicy`, the handler releases the claim with the same compare-and-set pattern: `UPDATE ... SET status='queued', lease_token=NULL, generation_lease_expires_at=NULL WHERE id=? AND lease_token=?`, then rethrows so `WorkerRunner`'s bounded 5-attempt backoff applies to the next claim attempt.
- **Terminal failure / dead-letter / token-invalid / circuit-open**: same compare-and-set, `SET status='failed', failure_class=?, lease_token=NULL, generation_lease_expires_at=NULL WHERE id=? AND lease_token=?`.
- **Crashed worker — durable recovery trigger required.** A lease field alone does not cause anything to run again; something durable must notice the expiry and re-dispatch. §3.5 defines this exactly: a bounded, idempotent Action Scheduler recurring sweep is the sole recovery trigger for a row whose original Action Scheduler action never completed (crash, PHP fatal, host OOM-kill, Action Scheduler's own claim timeout). No row can remain `generating` indefinitely, because the sweep's cadence plus the lease duration is a fixed, bounded upper bound on staleness (§3.5).
- **Honest reliability guarantee — precisely scoped.** Eligible queued/in-flight work is retried or recovered within the defined bounded policy (claim → normal `RetryPolicy` retry → sweep-triggered reclaim, all counted against one shared `attempt_count` budget, §3.5) **unless and until it reaches a defined terminal state** (`failed` via terminal/token-invalid/circuit-open classification, `no_matching_source`, `provider_disabled`/cancellation, or attempt-budget exhaustion; or `discarded`). This is a bounded-scheduling/recovery guarantee, not a promise that a provider call always happens: circuit-open, `no_matching_source`, cancellation, and terminal pre-call classification correctly and by design result in **zero** provider calls. Separately, once a provider call has actually begun, invocation is **at-least-once, not exactly-once** — in the narrow crash window after the provider accepts a request but before the outcome is durably recorded, the same draft can be sent to the provider more than once (resolved harmlessly by the compare-and-set write in the bullets above, and bounded in count by the shared `attempt_count` budget). A duplicate call never produces a duplicate customer-visible effect, because the structural no-auto-send rule (§ADR decision 6) means no draft, duplicated or not, ever reaches a visitor or Telegram automatically.

## 3.5 Durable Recovery Trigger — Stale-Lease Sweep

The recovery trigger reuses the plugin's existing Action Scheduler-based queue mechanism (the same mechanism `Dispatcher::enqueue()` already uses for every job type, including Telegram outbound) — no bespoke cron or polling loop is introduced.

- **Per-draft job reference.** `ai_drafts.job_reference` always holds the Action Scheduler action ID of the *currently scheduled* attempt for that draft — set on the initial enqueue, and overwritten every time a new attempt is scheduled for the same draft (a normal `RetryPolicy` backoff retry, or a sweep-triggered re-enqueue below). This is a durable, queryable reference usable in Diagnostics and support investigation; it is metadata only, never referenced from any visitor-facing or queue-payload path.
- **Fixed sweep job type: `ai_draft_lease_sweep`.** Registered once, at plugin init, as an Action Scheduler **recurring action** (`as_schedule_recurring_action`), guarded by the standard existing-schedule check (`as_has_scheduled_action`) so re-registration on every page load/reactivation is idempotent and never creates duplicate recurring schedules.
- **Bounded cadence: every 60 seconds**, independent of whether AI is currently enabled (the sweep's query is cheap and a no-op whenever no `generating` row has an expired lease).
- **Sweep query and action, in one transaction per candidate row:** `SELECT id, attempt_count, lease_token FROM universal_telegram_ai_drafts WHERE status='generating' AND generation_lease_expires_at < NOW() FOR UPDATE` (reuses index `idx_ai_drafts_lease`), for each matching row:
  - If `attempt_count < 5` (the existing shared `RetryPolicy` budget): clear the stale lease (`lease_token=NULL`, `generation_lease_expires_at=NULL`), leave `status='queued'`, and enqueue a fresh `ai_draft_generate` Action Scheduler action for the same `draft_uuid`, overwriting `job_reference` with the new action ID. This is the only path that re-arms a crashed row — it is what makes the claim query in §3.1-B relevant again, since a purely expired lease with no new action scheduled would never be re-examined by any worker.
  - If `attempt_count >= 5`: transition directly to `status='failed'`, `failure_class='crashed_exhausted'`, clear the lease — the shared attempt budget is exhausted regardless of whether prior attempts failed via a caught exception or via a crash, so this path cannot loop.
- **Idempotency.** The sweep's own `WHERE status='generating' AND generation_lease_expires_at < NOW()` predicate is naturally idempotent — a row already reclaimed (by this or a prior sweep run) or already terminal no longer matches, so overlapping/concurrent sweep runs (e.g. a slow run still executing when the next is due) cannot double-schedule for the same row; the `FOR UPDATE` row lock also serializes two sweep runs that do overlap.
- **Cleanup.** No new cleanup mechanism is needed: Action Scheduler's own existing completed/failed-action pruning already governs the sweep's own past action records, exactly as it does for every other job type; the sweep is never unscheduled on AI disablement (its cost when idle is one cheap empty-result query per minute) so re-enabling AI later requires no re-registration step.
- **Bound on total staleness.** Any `generating` row is re-examined within, at most, the 90-second lease plus one 60-second sweep interval (≈150 seconds worst case) of its original worker crashing — this is the exact, stated upper bound for "no row can remain `generating` indefinitely."
- **Bound on total attempts, across all paths.** `attempt_count` is incremented on every claim regardless of origin (initial request, `RetryPolicy` backoff retry, or sweep-triggered re-enqueue), and the sweep itself refuses to re-arm past 5 — so the total provider-call attempt budget is bounded identically whether a row's failures came from caught exceptions, a crash, or a mix of both.

## 3.4 Idempotency (client and server)

Client-side, the "Request draft" button disables immediately on click. Server-side, idempotency is guaranteed structurally by §3.1-A's transactional conversation-row-locked check — a duplicate request while one is `queued`/`generating` always returns the existing `draft_uuid`, never creates a second row or a second queue job. Queue payloads remain opaque identifiers only (`draft_uuid`, `conversation_id`, `bot_id`) regardless of path. No client-generated idempotency key is required or used.

**Failure-path outcomes, restated with recovery semantics:**
- **Timeout** → classified `RETRYABLE` → lease released to `queued` (§3.3) → rethrown → existing bounded `RetryPolicy` (5 attempts) → `failed`/`failure_class='provider_timeout'` on final attempt.
- **Circuit-open** (checked before any provider call, while still holding the claim) → immediate `failed`/`failure_class='circuit_open'` via the terminal compare-and-set, no HTTP call attempted.
- **Concurrency cap reached** → row stays `queued`, no lease taken, job deferred (not failed) on the normal polling schedule (§3.1-B step 4).
- **Dead-letter** → `status='failed'` plus `failure_class` **is** the dead-letter record; no separate dead-letter table.
- **Discard** → operator action on `generated`/`reviewed`, sets `discarded` immediately, no cooldown (§3.2).
- **Worker crash mid-generation** → lease expires; the `ai_draft_lease_sweep` recurring action (§3.5), not the lease field alone, is what re-enqueues a fresh attempt (or, past the shared 5-attempt budget, transitions directly to `failed`/`failure_class='crashed_exhausted'`) — so a permanently-crashing path still terminates in `failed` within a bounded time, never an infinite reclaim loop.

# 4. Persistence Design (final)

**Decision: one dedicated singleton config table**, not a generic Settings entry — because the row carries a `CredentialVault`-encrypted secret column, and the plugin's established pattern (bot credentials) is that anything holding ciphertext gets its own table rather than living in a generic key/value settings store. This is the only design considered further; no alternative persists into the plan. **The singleton row (`id=1`) is seeded by the migration step itself** (`enabled=0`, empty credential, default `ack_policy_version`/`ack_text`) — it must always exist, since §3.1-B's global concurrency mutex locks this row unconditionally, before any admin has ever saved configuration.

All new tables: `utf8mb4`/`utf8mb4_unicode_520_ci` (matching existing plugin tables), `InnoDB`, no foreign-key constraints (matches the plugin's existing FK-less convention — referential integrity enforced in application code, consistent with every prior milestone).

**`universal_telegram_ai_config`** (singleton — application enforces exactly one row, `id=1`)

| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | NO | PK | always `1`, enforced in `AIProviderRepository::save()` (upsert on id=1) |
| provider | VARCHAR(32) | NO | | fixed `'openai'` in M09 |
| model | VARCHAR(191) | NO | | admin-entered, bounded |
| api_key_ciphertext | LONGTEXT | YES | | NULL until first configured; `CredentialVault::encrypt()` |
| enabled | TINYINT(1) UNSIGNED | NO | | default `0` |
| ack_policy_version | VARCHAR(32) | NO | | default `'v1'`; admin bumps on disclosure-text change |
| ack_text | TEXT | NO | | current disclosure copy shown in widget |
| created_at | DATETIME | NO | | |
| updated_at | DATETIME | NO | | |

No secondary indexes needed (single-row table).

**`universal_telegram_ai_drafts`**

| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | NO | PK | |
| draft_uuid | CHAR(36) | NO | UNIQUE `uq_ai_drafts_uuid` | opaque queue/reference key |
| conversation_id | BIGINT UNSIGNED | NO | INDEX `idx_ai_drafts_conversation` | no FK, app-enforced |
| status | VARCHAR(16) | NO | INDEX `idx_ai_drafts_status` | enum values per §3 state machine |
| provider | VARCHAR(32) | NO | | traceability copy at request time |
| model | VARCHAR(191) | NO | | traceability copy at request time |
| prompt_policy_version | VARCHAR(32) | NO | | e.g. `'v1'` |
| source_ids_json | TEXT | YES | | JSON array of `{post_id, revision_id}` |
| context_fingerprint | CHAR(64) | YES | | SHA-256 of submitted context, not raw text |
| body_ciphertext | LONGTEXT | YES | | NULL until `generated`; `CredentialVault::encrypt()` |
| failure_class | VARCHAR(32) | YES | | NULL unless `status='failed'`; fixed taxonomy code |
| requested_by_user_id | BIGINT UNSIGNED | NO | | operator identity |
| reviewed_by_user_id | BIGINT UNSIGNED | YES | | set on `reviewed`/`approved`/`discarded` |
| job_reference | VARCHAR(64) | YES | | Action Scheduler action id of the *current* scheduled attempt; overwritten on every retry or sweep-triggered re-enqueue (§3.5); diagnostics only |
| lease_token | CHAR(36) | YES | | set on claim, cleared on any terminal/release write; compare-and-set guard (§3.3) |
| generation_lease_expires_at | DATETIME | YES | INDEX `idx_ai_drafts_lease` | `NOW() + 90s` on claim; expired + `status='generating'` rows are reclaimable |
| claimed_at | DATETIME | YES | | last claim timestamp, diagnostics |
| attempt_count | INT UNSIGNED | NO | | default `0`; incremented on every claim, bounded by existing `RetryPolicy` (5) |
| created_at | DATETIME | NO | | |
| generated_at | DATETIME | YES | | |
| reviewed_at | DATETIME | YES | | |
| updated_at | DATETIME | NO | | bumped on every status transition; `failed`'s value drives the 30s cooldown check (§3.2) |

Composite index `idx_ai_drafts_conv_status (conversation_id, status)` supports both the "one active draft" lookup and the operator's per-conversation draft list. Index `idx_ai_drafts_lease (status, generation_lease_expires_at)` supports the claim query's candidate scan.

**`universal_telegram_conversations` — additive column:**

| Column | Type | Null | Notes |
|---|---|---|---|
| ai_ack_policy_version | VARCHAR(32) | YES | `NULL` = not eligible; else must equal `ai_config.ack_policy_version` exactly at request time |

**Migration ownership:** `Migrator` steps 19 (`ai_config` table), 20 (`ai_drafts` table), 21 (`conversations.ai_ack_policy_version` column) — `db_version` `18 → 21`. Split into three steps (not two) so each step is independently idempotent/re-runnable per the plugin's existing per-step migration convention.

**Retention/cleanup, by status:**

| Status | Cleanup behavior |
|---|---|
| `queued` / `generating` | If the owning conversation is purged (30/90-day pass) while a draft is still active, the draft row is force-transitioned to `discarded` first, then follows conversation cleanup — never left orphaned/active against a nulled conversation |
| `generated` / `reviewed` / `approved` / `failed` / `discarded` | All follow the owning conversation's existing 30-day body-nulling pass (nulls `body_ciphertext`, keeps metadata/traceability) and 90-day full-deletion pass (row deleted with the conversation) — extends the existing two call sites, no parallel cleanup job |
| Operator account deletion | `requested_by_user_id` / `reviewed_by_user_id` nulled (mirrors existing note-anonymization precedent); draft content untouched |

**Provider config deletion/disablement:**
- **Disable** (`enabled → 0`): the `api_key_ciphertext` and `model` are left intact so re-enabling doesn't require reconfiguration; any `queued`/`generating` drafts at the moment of disablement are immediately transitioned to `failed`/`failure_class='provider_disabled'` by the same admin action (no orphaned in-flight jobs against a disabled provider).
- **Explicit "delete credential" action** (separate from disable): clears `api_key_ciphertext` to `NULL` and forces `enabled=0` in the same update; same in-flight-cancellation behavior as disable applies.

# 5. Data Flow (unchanged shape, boundaries re-confirmed)

Operator request → authorization/eligibility → queue → provider → stored draft → operator review, with every sensitive-data boundary as in §1–4 above: browser never contacts the provider; queue payload carries only `draft_uuid`/`conversation_id`/`bot_id`; decryption happens only inside the worker process; API key is used only inside the outbound HTTP call and never logged; draft body is encrypted at rest. `AiDraftRepository` is referenced only by the fixed allow-list of `AI\*` domain/worker classes and explicitly named `Administration\AI\*` operator-review classes defined in §6 below — no visitor-facing REST controller, chat-widget asset/config path, webhook route, or Telegram outbound class ever references it.

# 6. Structural Boundary — `AiDraftRepository` Access Allow-List

The previous draft's rule ("read only by `Administration\AI\*`") was factually wrong: the AI request-eligibility and worker/generation path must itself read and write draft rows, and that path lives in the `AI\*` domain namespace, not `Administration\*`. The corrected, enforceable rule is a fixed allow-list, not a namespace-prefix rule alone:

**Permitted to reference `AiDraftRepository`:**

| Class | Access | Reason |
|---|---|---|
| `AI\Draft\AiDraftRepository` | — | the repository itself |
| `AI\Draft\DraftRequestHandler` | read + write (insert `queued`) | operator-initiated, server-side eligibility-gated request path (§ Functional workflow) |
| `AI\Draft\AIDraftGenerationHandler` | read + write (claim/lease/complete/fail) | the queue worker (§3.1-B, §3.3) |
| `AI\Draft\AiDraftLeaseSweep` | read + write (reclaim/dead-letter) | the durable recovery trigger (§3.5) |
| `Administration\AI\ConversationDraftPanel` | read + write (status: `reviewed`/`approved`/`discarded` only — never `queued`/`generating`/`generated`) | operator-facing draft list/approve/discard controls on `ConversationDetailPage` |
| `Administration\AI\AIDiagnosticsPanel` | read-only (aggregate counts/status only, never `body_ciphertext`) | Diagnostics tab's AI panel (§ Administration and Operator UX) |

**Explicitly prohibited from referencing `AiDraftRepository`, by name, in the structural test:** `Conversations\ConversationsController` and every other visitor-facing REST controller; every chat-widget bootstrap/asset/config class; every webhook-routing class; every `Telegram\Outbound\*` class; and every `Administration\*` class **not** in the table above (e.g. the Bots tab, the generic Settings page, other Hub tabs unrelated to AI).

**Structural test design.** `StructuralBoundariesTest` gains one new assertion, symmetrical with its existing pattern for other boundaries: statically scan the codebase for `use UniversalTelegram\AI\Draft\AiDraftRepository` imports and for the fully-qualified class name in `new AiDraftRepository(...)`/constructor-injection type-hints, collect the referencing class's own fully-qualified name, and assert that set is exactly a subset of the six-class allow-list above — failing loudly (naming the offending file) if any other class, especially anything under `Conversations\*`, `Telegram\Outbound\*`, or an unlisted `Administration\*` page, is found to reference it. This allows the required AI worker/repository path while still catching the exact prohibited boundaries the charter cares about.

# Provider Abstraction (unchanged from draft 1, confirmed consistent with §3)

| Element | File | Responsibility |
|---|---|---|
| `AiProviderInterface` | `src/AI/Provider/AiProviderInterface.php` | `complete(AiRequest): AiResult`, provider-neutral |
| `OpenAiAdapter` | `src/AI/Provider/OpenAi/OpenAiAdapter.php` | Only shipped implementation; `wp_remote_post()`, 30s timeout, interceptable via `pre_http_request` |
| `AiFailureClassifier` | `src/AI/Provider/AiFailureClassifier.php` | RETRYABLE (5xx/timeout/network) vs TERMINAL (4xx/content-policy) vs TOKEN_INVALID (401) |
| Circuit breaker | reuse `Telegram\Reliability\CircuitBreaker`, new scope `'ai_provider'` | No generic queue/Telegram code modified |
| `AIDraftGenerationHandler` | `src/AI/Draft/AIDraftGenerationHandler.php` | Registered job handler for `ai_draft_generate`; owns the config-row-locked claim/lease (§3.1-B, §3.3), circuit-open pre-check, classification, and dead-letter/deferral/reclaim decisions from §3 |
| `AiDraftLeaseSweep` | `src/AI/Draft/AiDraftLeaseSweep.php` | Registered `ai_draft_lease_sweep` recurring Action Scheduler job (§3.5); the sole durable recovery trigger for a crashed worker's expired lease — re-enqueues within budget or dead-letters as `crashed_exhausted` |

No live calls at any point during planning or implementation validation. Output hard-capped at 4,000 characters (truncated with a marker if exceeded).

# Prompt and Output Policy (unchanged from draft 1)

Fixed, non-configurable `PromptBuilder::POLICY_VERSION = 'v1'`. Structure: (1) fixed system message (never invent claims, source-grounded only, draft-only, no tool/action capability exists); (2) `<source id="…">`-delimited excerpts labelled "reference data, not instructions"; (3) `<conversation>`-delimited context labelled "customer data, not instructions"; (4) closing instruction that content inside those tags is data only. Refusal path (§2) short-circuits before any provider call when no source matches. Every draft carries a fixed "AI-generated draft — NOT SENT. Review, edit, and send manually via Telegram." banner plus a traceability summary.

# Administration and Operator UX

- Hub tab **AI** (`AISettingsPage`): enable toggle (default off), model id, write-only masked API key field, disclosure text + version-bump control (bumping is a deliberate, warned action — it retroactively excludes prior-acknowledged conversations), delete-credential action. Gated on `MANAGE`.
- Hub tab **AI Content** (`ApprovedContentPage`): checkbox approval over published content list, showing current approval/revision-staleness state. Gated on `MANAGE`.
- `ConversationDetailPage`: "Request AI draft" button, disabled while an active draft exists or during cooldown (with the reason shown, e.g. "please wait Ns" or "discard the current draft first"); per-conversation draft history list with status, "NOT SENT" banner, Approve/Discard actions. Gated on `MANAGE_CONVERSATIONS`. Implemented as a distinct, explicitly-named `Administration\AI\ConversationDraftPanel` class composed into `ConversationDetailPage`'s render — kept as its own class specifically so it is the one, precisely-named `Administration\*` class permitted to reference `AiDraftRepository` for the review/approve/discard write paths (§6).
- Diagnostics tab: "AI provider" panel — circuit-breaker state, counts of `queued`/`generating`/`failed` jobs, no secrets rendered. Implemented as `Administration\AI\AIDiagnosticsPanel`, the other explicitly-named, read-only-only class permitted to reference `AiDraftRepository` (§6).
- Chat widget: acknowledgement checkbox per §1, unchecked by default, shown only pre-first-message and only while AI is enabled. The checkbox label text and `ack_policy_version` are emitted to the widget as **public, fixed, non-secret configuration** — no credential, no visitor-specific or per-session value — encoded via the plugin's established JSON-island escaping pattern (the same `wp_json_encode()`-into-a-`<script type="application/json">`-tag convention already used for other public widget bootstrap config), so the fragment is safe to output-cache/CDN-cache identically to the rest of the widget bootstrap payload; changing the text/version simply invalidates that cached fragment like any other config edit, it does not require a private or per-visitor cache key.

# Privacy / Security / Audit / Retention Matrix

| Concern | Mechanism |
|---|---|
| Visitor disclosure | Explicit, unchecked-by-default checkbox at first message; `ai_ack` boolean validated server-side; recorded as `ai_ack_policy_version` only on that exact request. **Technical mechanism only — not legal/GDPR advice.** |
| Declined / pre-enablement / stale-version conversations | Column NULL or version-mismatched → permanently ineligible; no retroactive consent inferred, no re-prompt mid-conversation |
| Anonymous chat | Identical `ai_ack` field on the same conversation-creation call; no new identifier introduced |
| Source grounding | Always source-only; no toggle, no general-knowledge fallback; zero-match → `no_matching_source`, no provider call |
| Query surface | `top_matches()` takes no free-text input from any request; derives its query only from the conversation's own last visitor message |
| Credential exposure | `CredentialVault` only; never in HTML/JS/logs/audit/queue/REST/diagnostics/exports |
| Queue payload | Ids only (`draft_uuid`, `conversation_id`, `bot_id`); `JobEnvelope`'s existing fail-closed check makes other fields construction-time impossible |
| Duplicate jobs | Conversation-row-locked "one active draft per conversation" check (§3.1-A) is the sole idempotency mechanism; no client-generated key needed |
| Concurrency pressure | Site-wide cap of 2 concurrent generations enforced via the seeded singleton config-row lock (§3.1-B); deferral (not failure) when at cap |
| Worker crash / stranded row | 90s generation lease with compare-and-set release/completion (§3.3); expired leases are reclaimed by the ordinary claim query, no separate sweep job; bounded by the existing 5-attempt `RetryPolicy` |
| Duplicate provider calls | At-least-once (not exactly-once) invocation is an explicit, accepted guarantee (§3.3) — bounded cost only, never a duplicate customer-visible effect, because no draft is ever auto-sent |
| Cooldown | Exactly one rule: 30s after `failed` only; no cooldown after explicit discard; no new request while `generated`/`reviewed`/`approved` is retained (§3.2) |
| Widget config caching | Acknowledgement text/version is public, fixed, non-secret, JSON-island-encoded — safely cacheable, no per-visitor value |
| Visitor-facing leak | No code path connects `AiDraftRepository` to any visitor REST route, webhook, or Telegram outbound path (structurally absent) |
| Audit | `AuditLogger` entries for settings change, credential delete, ack-version bump, draft requested/generated/failed/reviewed/approved/discarded, content approved/revoked — excludes draft/message body and API key |
| Retention | Extends existing 30-/90-day cleanup passes per §4 table; provider disable/delete force-cancels in-flight jobs |
| Reliability | Circuit breaker + concurrency cap + cooldown + bounded 5-attempt retry — no unbounded retry/queue pressure possible |

# Work Packages

| WP | Objective | Key files | Migration | Tests | Commit message |
|---|---|---|---|---|---|
| 1 | Provider config schema + settings + acknowledgement column | `Migrator` steps 19–21, `AIProviderRepository`, `AISettingsPage` | db_version 18→21 | `MigratorAiSchemaTest`, `AIProviderRepositoryTest` (singleton upsert, disable/delete cancellation) | `WP1: add AI provider config schema, settings UI, and conversation acknowledgement column` |
| 2 | Approved-content model + query derivation | `ApprovedContentRepository`, `ApprovedContentPage` | none | revision-staleness exclusion, `top_matches()` derives-from-conversation-only tests | `WP2: add approved-content selection and conversation-derived retrieval` |
| 3 | Draft persistence + acknowledgement gate + widget checkbox | `AiDraftRepository`, conversation-creation endpoint (`ai_ack` field), widget checkbox component | none (uses step 21 column) | ack-set/ack-declined/ack-malformed tests, anonymous+logged-in parity tests, widget state tests | `WP3: add AI draft persistence and explicit visitor acknowledgement gate` |
| 4 | Provider abstraction + OpenAI adapter | `AiProviderInterface`, `OpenAiAdapter`, `AiFailureClassifier` | none | `OpenAiAdapterTest` (via `pre_http_request`), classifier tests | `WP4: add provider-neutral AI abstraction and OpenAI adapter` |
| 5 | Prompt builder + source-only policy | `PromptBuilder` | none | injection/delimiter tests, `no_matching_source` short-circuit test | `WP5: add prompt-policy builder with source-only grounding and injection defenses` |
| 6 | Queue job, lifecycle state machine, race-safe concurrency, lease-based recovery, durable stale-lease sweep | `AIDraftGenerationHandler`, `AiDraftLeaseSweep`, `HandlerRegistry`, `CircuitBreaker` scope, `QueueHealthAlert` ext, `ai_drafts` lease columns | schema fields folded into step 20 | concurrent-worker cap test (≥3 workers racing, assert never more than 2 `generating`), config-row-lock serialization test, lease-expiry reclaim test, compare-and-set stale-completion-discarded test, retryable-failure release-to-queued test, terminal/circuit-open/dead-letter tests, sweep re-enqueue-below-budget test, sweep dead-letter-at-budget (`crashed_exhausted`) test, sweep idempotency-under-overlap test, `job_reference` overwritten-on-retry/-sweep test, honest-guarantee documentation tests (zero-call terminal paths vs. at-least-once post-call paths) | `WP6: implement AI draft lifecycle with race-safe concurrency, lease-based recovery, and a bounded stale-lease sweep` |
| 7 | Operator draft-request endpoint | `DraftRequestHandler` | none | disabled/unacknowledged/unauthorized tests; concurrent-request race test (≥2 simultaneous requests for one conversation, assert exactly one `queued` row via conversation-row lock, §3.1-A); cooldown-after-failed test; no-cooldown-after-discard test; reject-while-generated/reviewed/approved-retained test | `WP7: add operator draft-request endpoint with conversation-row-locked idempotency and one-rule cooldown` |
| 8 | Conversation-detail UX | `ConversationDetailPage` ext, `Administration\AI\ConversationDraftPanel`, `Administration\AI\AIDiagnosticsPanel` | none | UI/controller tests; structural-boundary allow-list test (§6) asserting only the fixed six-class set references `AiDraftRepository` | `WP8: add draft review UI and diagnostics panel, enforced by a fixed AiDraftRepository access allow-list` |
| 9 | Retention/deletion/version bump | `RetentionCleanupHandler`/`ConversationPurgeService` ext, deleted_user step, version docs | none (existing steps + step 21 column) | retention-by-status tests, account-deletion cleanup, provider-disable in-flight-cancellation test | `WP9: extend retention and account-deletion cleanup to AI drafts; bump version` |

# Testing and Release Evidence

Tests are written per-package but not run until all WPs are complete; then one focused local validation gate (PHPUnit unit+integration, PHPCS, PHPStan, package-acceptance for db_version=21). GitHub Actions remains the independent full matrix. Scenario coverage: disabled-by-default; acknowledgement declined/omitted/malformed; acknowledgement version-mismatch on pre-existing conversations; anonymous-mode acknowledgement parity; authorization; queue opacity (`JobEnvelope` content-rejection assertion); **conversation-row-locked one-active-draft idempotency under concurrent requests (multi-process/thread race test)**; **config-row-locked site-wide concurrency cap under concurrent workers (race test asserting never >2 `generating`)**; **generation-lease expiry and reclaim (simulated crash: force-expire a lease, assert a second claim succeeds)**; **compare-and-set stale-completion rejection (simulate a delayed first worker's write arriving after reclaim, assert it is a no-op)**; **retryable-failure lease release back to `queued`**; **stale-lease sweep re-enqueues an expired `generating` row below the shared attempt budget and updates `job_reference`**; **stale-lease sweep dead-letters as `crashed_exhausted` at/above the shared attempt budget, regardless of whether prior attempts failed via exception or crash**; **sweep idempotency under overlapping/concurrent runs**; **bounded total staleness (row cannot remain `generating` past lease+sweep-interval)**; **zero-provider-call terminal paths (circuit-open, `no_matching_source`, cancellation) verified distinct from at-least-once post-call-begun duplication**; **one-rule cooldown: 30s enforced after `failed`, zero after discard, hard-reject while `generated`/`reviewed`/`approved` retained**; provider failure/retry/circuit-breaker/dead-letter; prompt injection; source-only grounding and `no_matching_source` short-circuit; output bounds; **`AiDraftRepository` structural allow-list test (exactly the six named classes, explicit negative assertions for `Conversations\*`, `Telegram\Outbound\*`, webhook, widget, and unlisted `Administration\*` classes)**; audit redaction; retention-by-status; provider disable/delete in-flight cancellation; **widget acknowledgement config is public/cacheable and contains no secret or per-visitor value**; WooCommerce-absent mode; package acceptance.

**Manual Product Owner acceptance checklist** (dev-only, explicitly configured provider, no live call unless separately authorized): enable AI with placeholder key, confirm masking; leave the widget checkbox unchecked and confirm that conversation is refused a draft; check the box, start a new conversation, confirm eligibility; approve then edit a page and confirm it drops from retrieval until re-approved; request a draft with no approved source matching and confirm `no_matching_source` with no HTTP call made; request a draft with HTTP stubbed and confirm "NOT SENT" banner + traceability; click "Request draft" twice quickly and confirm only one job/row is created; fire several draft requests across different conversations simultaneously and confirm never more than 2 are `generating` at once; force a lease to expire (test hook) and confirm the stale-lease sweep, not the mere passage of time, is what re-enqueues the row within its next 60-second run, and that it completes rather than staying stuck; force a lease to expire on a row already at the attempt budget and confirm the sweep dead-letters it as `crashed_exhausted` rather than looping; simulate a 500 and confirm circuit breaker opens and Diagnostics reflects it; disable the provider mid-flight and confirm the active draft is cancelled; discard a generated draft and confirm a new request is accepted immediately with no cooldown; let a draft fail and confirm a new request is rejected for 30 seconds; confirm `AiDraftRepository` is referenced only by the six allow-listed classes (spot-check via the structural test's own output) and that no widget/webhook code path can display or send a draft.

# ADR-0028 — AI Draft Assistant: Explicit Acknowledgement Gate, Source-Only Grounding, Provider Abstraction, Lifecycle/Concurrency Control, and Structural Delivery Prohibition

## Status

Proposed

## Context

M09's charter (`docs/milestones/m09-ai-draft-assistant.md`) requires an operator-only AI drafting capability with a provider abstraction, model configuration, approved-content retrieval, source traceability, a human approval workflow, prompt-injection defenses, and reliability mechanisms equivalent to M01's Telegram transport, while never permitting an AI draft to reach a visitor under any code path, and while requiring a visitor be clearly informed and given a real choice before their conversation content may be processed by an external AI provider. ADR-0005 names `AI` (`UniversalTelegram\AI`) as a boundary reserved for M09, with no files created yet. No existing ADR defines a persistence model for AI drafts, a provider abstraction, a per-conversation acknowledgement mechanism, or a bounded/source-only retrieval policy. ADR-0009's classification/redaction model and ADR-0006's fail-closed `JobEnvelope` payload policy already exist and are reused, not re-decided, here.

## Decision

1. **Explicit acknowledgement gate.** A visitor's conversation becomes AI-eligible only if, at the exact moment of conversation creation, the visitor submitted `ai_ack=true` in response to an unchecked-by-default widget checkbox shown only while AI processing is enabled. This value is written once, at that request, to `universal_telegram_conversations.ai_ack_policy_version` (set to the provider config's `ack_policy_version` at that instant, else `NULL`). It is never backfilled, inferred, upgraded, or re-prompted later in the conversation. A later disclosure-text version bump renders previously-acknowledged conversations ineligible for new drafts (exact-version match required), by design. This mechanism applies identically to anonymous and logged-in conversation creation and introduces no new tracking identifier. This is a technical enforcement mechanism only; it is not legal advice on consent or regulatory compliance.
2. **Source-only grounding, unconditionally.** There is no configurable fallback to unsourced/general-knowledge generation. Retrieval is restricted to explicitly administrator-approved, published, non-password-protected WordPress posts/pages, re-validated against a captured revision marker at approval time. The retrieval query is derived exclusively, inside `ApprovedContentRepository::top_matches()`, from the conversation's own most recent visitor message — no request, operator action, or admin UI can supply an arbitrary query string. When zero approved sources match, the job terminates as `failed`/`no_matching_source` before any provider call is made.
3. **Provider abstraction.** `AI\Provider\AiProviderInterface`, with exactly one shipped implementation, `AI\Provider\OpenAi\OpenAiAdapter`, disabled by default until an administrator configures credential, model identifier, and enablement together via a dedicated singleton configuration table (`universal_telegram_ai_config`) rather than the generic settings mechanism, consistent with the plugin's existing convention that any row holding `CredentialVault` ciphertext owns its own table. No runtime provider model-list discovery is ever performed.
4. **Persistence and traceability.** Two additive tables (`universal_telegram_ai_config`, `universal_telegram_ai_drafts`) and one additive nullable column (`universal_telegram_conversations.ai_ack_policy_version`), added via `Migrator` steps 19–21 (`db_version` 18→21). No existing table's semantics change. Every draft row records provider, model, prompt-policy version, approved-source identifiers/revisions, a context fingerprint (not raw text), status, failure classification, requester/reviewer identity, and timestamps — and never a raw credential, raw prompt text beyond already-authorized retained content, or a raw provider error body.
5. **Lifecycle and concurrency control.** Drafts move through a fixed state machine (`queued → generating → generated → reviewed/discarded`, with `approved` and `discarded` terminal, `generating` able to release back to `queued` on a retryable failure, and `failed` terminal from any pre-`generated` step). Exactly one active (`queued`/`generating`) draft is permitted per conversation, enforced by locking the **existing** owning conversation row (`SELECT ... FOR UPDATE`) before checking for or inserting a draft row — this lock is also the sole request-idempotency mechanism; no client-generated idempotency key is required. Site-wide concurrent generation is capped at 2, enforced by locking the **existing, migration-seeded singleton** `universal_telegram_ai_config` row (`SELECT ... FOR UPDATE`) as a global admission mutex before counting active (non-lease-expired) `generating` rows and claiming a candidate row; a request arriving at capacity is deferred, not failed. The conversation-row lock and the config-row lock are never held by the same transaction and are each the only lock their respective code path acquires, making cross-path deadlock structurally impossible rather than merely unlikely. Each claim carries a time-bounded generation lease (`generation_lease_expires_at`, a fresh `lease_token`) so a crashed worker's row is eligible for reclaim once the lease expires — but eligibility alone does not schedule anything, so a fixed, bounded, idempotent Action Scheduler recurring action, `ai_draft_lease_sweep` (60-second cadence, reusing the same Action Scheduler mechanism every other job type already uses), is the durable trigger that either re-enqueues a fresh attempt for an expired row (below the shared 5-attempt budget) or dead-letters it as `crashed_exhausted` (at or above that budget) — bounding both total staleness (≈150 seconds worst case) and total attempts identically whether prior failures came from caught exceptions or process crashes. All lease-clearing writes (success, retryable release, terminal failure, sweep reclaim/dead-letter) are performed as a compare-and-set or lease-expiry-gated update so a stale, delayed worker can never overwrite a newer claim's outcome. This design provides an honest, precisely-scoped guarantee: eligible work is retried/recovered within this bounded policy until it reaches a defined terminal state; it does **not** claim a provider call always happens — circuit-open, `no_matching_source`, cancellation, and terminal pre-call classification correctly result in zero provider calls by design. Only once a call has actually begun is invocation at-least-once, not exactly-once — a rare crash between provider acceptance and the database write can cause a duplicate call, accepted as a bounded cost because no draft is ever auto-sent regardless of how many times it was generated. A single cooldown rule governs re-requesting: 30 seconds after a `failed` terminal state, zero after an explicit operator discard, and an outright rejection (not a timed cooldown) while a `generated`/`reviewed`/`approved` draft remains un-discarded. These are all additions scoped to the `AI` boundary's own handler, sweep, and repository, operating only on rows the boundary already owns; the generic `Queue\Dispatcher`/`WorkerRunner`/`JobEnvelope`/`RetryPolicy`/`Telegram\Reliability\CircuitBreaker` mechanisms, including the existing Action Scheduler recurring-action facility, are reused unmodified, under a new `'ai_provider'` circuit-breaker scope.
6. **Structural delivery prohibition.** `AI\Draft\AiDraftRepository` is referenced only by a fixed six-class allow-list: the `AI\Draft\*` domain/worker classes that must legitimately read and write it (`DraftRequestHandler`, `AIDraftGenerationHandler`, `AiDraftLeaseSweep`, and the repository itself) plus exactly two explicitly-named `Administration\AI\*` operator-review classes (`ConversationDraftPanel` for review/approve/discard, `AIDiagnosticsPanel` for read-only diagnostics). No visitor-facing REST controller, chat-widget asset/config class, webhook handler, Telegram outbound class, or any other `Administration\*` page references it, anywhere in the codebase — enforced by an addition to the existing `StructuralBoundariesTest` guard pattern that asserts the referencing-class set is exactly this allow-list, not by UI wording alone. Marking a draft `approved` is an audit-trail action only and triggers no send of any kind.
7. **Prompt policy authority.** A fixed, non-runtime-configurable `PromptBuilder::POLICY_VERSION` constant governs a fixed message structure — non-overridable system message, `<source>`-delimited approved excerpts, `<conversation>`-delimited context, and a closing instruction that content inside those delimiters is data, never instruction — so that no source, visitor message, or administrator-configured field can ever occupy the system-message slot or grant any tool/permission beyond what the plugin's own capability model already grants (none — the AI boundary has zero write-capable tools).

## Alternatives

- *Silent acknowledgement via a timestamp set whenever AI happens to be enabled at conversation start.* Rejected: this records a system state, not a visitor choice, and does not satisfy the charter's requirement that the visitor be clearly informed and given the opportunity to decline before their conversation begins.
- *A configurable "source-restricted" toggle allowing an administrator to permit unsourced generation.* Rejected: reintroduces exactly the general-knowledge/hallucination and unbounded-scope risk the charter's source-approval requirement exists to prevent; M09 ships one unconditional policy, not an admin-selectable risk level.
- *Storing the provider configuration as generic Settings key/value entries.* Rejected: the plugin's own established pattern is that any row holding `CredentialVault` ciphertext gets a dedicated table, and mixing a secret-bearing singleton into a generic settings blob would obscure that classification boundary.
- *A generic, queue-wide concurrency-limiting primitive.* Rejected as out of scope: the charter's constraint is to use the established M01 reliability pattern "without modifying generic queue foundations"; a handler-local concurrency and cooldown check fully satisfies the requirement without touching `Dispatcher`/`WorkerRunner`.
- *Client-generated idempotency keys for duplicate-request protection.* Rejected as unnecessary: the conversation-row-locked "one active draft per conversation" check already provides exact idempotency with no additional client-side state.
- *An aggregate `SELECT COUNT(*) ... FOR UPDATE` over `generating` rows as the concurrency gate.* Rejected: an aggregate/range lock over a possibly-empty or non-uniform row set does not reliably serialize concurrent transactions in the plugin's supported database engines, and can allow the cap to be exceeded under contention. Locking the single, always-present, migration-seeded config row instead gives one unambiguous mutex with no dependency on how many rows currently match a predicate.
- *A dedicated dead-letter or lock table separate from `ai_drafts`.* Rejected: `status='failed'` plus `failure_class` already is the dead-letter record, and the singleton config row already exists and is the natural, already-present row to serve as the concurrency mutex — a new table would duplicate state without adding capability.
- *Claiming exactly-once provider invocation via distributed transactions or an outbox pattern.* Rejected as disproportionate: M09's structural no-auto-send guarantee already bounds the consequence of a duplicate provider call to a harmless duplicate draft-generation cost, so an honest at-least-once guarantee with compare-and-set completion is sufficient and far simpler.
- *Relying on the lease field alone, with no scheduled trigger, and assuming "some future claim attempt" will eventually reclaim it.* Rejected: nothing in a purely request-driven system (no visitor request, no operator action) would ever cause that future claim attempt to happen — a stale row would only be reclaimed by coincidence, if an unrelated new draft request happened to run the claim query, which cannot be relied upon as a bound. A dedicated, bounded, idempotent recurring action is required to make "no row remains `generating` indefinitely" an actual guarantee rather than a hope.
- *A new bespoke cron/polling mechanism for lease recovery, separate from Action Scheduler.* Rejected: the plugin already has a working, tested queue/scheduler mechanism (Action Scheduler recurring actions) used by every other job type; introducing a second scheduling mechanism only for this one sweep would duplicate infrastructure the charter's "use the established M01 reliability pattern" instruction already directs against.
- *`AiDraftRepository` restricted to `Administration\AI\*` only.* Rejected as factually unworkable: the request-eligibility path and the queue worker are not administration screens, they are the `AI` domain's own server-side logic, and both must read/write draft rows to do their job; a rule that excludes them would be violated by the plan's own required components on day one.
- *A namespace-prefix-only structural rule (e.g. "anything under `AI\*` or `Administration\AI\*` is allowed").* Rejected as too permissive: it would allow any future class added under those namespaces, including ones with no legitimate reason to touch draft content, to reference the repository without the structural test ever flagging it. A fixed, named six-class allow-list is exact and catches scope creep within the same namespaces, not just violations from outside them.
- *A vector database or embeddings-based retrieval layer.* Rejected outright by the charter's own exclusion; keyword-derived retrieval against an explicit administrator allow-list, sourced only from the conversation's own last message, fully satisfies bounded retrieval without a hosted dependency or an operator-facing search surface.

## Consequences

M10 (controlled AI responses), if and when authorized, is the first milestone that may need to relax decision 6's structural visitor-isolation boundary — it must introduce its own ADR to do so; this ADR's prohibition is a deliberate, load-bearing default, not an incidental one. An administrator who bumps `ack_policy_version` after editing the disclosure text must accept that every previously-acknowledged conversation becomes permanently draft-ineligible; the settings UI must surface this consequence at the point of the bump (WP1). Because the query for source retrieval is derived solely from the conversation's own last message, retrieval quality is bounded by that message's clarity — no operator override is available in M09, by design; a future milestone could add operator query refinement, but that is out of scope here and would need its own review of the "no arbitrary search surface" guarantee this ADR establishes.

## Security and Privacy Impact

Establishes the plugin's first external-AI-provider security boundary, gated by a real, declinable visitor choice rather than an implicit system state. Credentials never leave `CredentialVault`; queue payloads structurally cannot carry content or credentials by construction of the existing `JobEnvelope` fail-closed check; drafts are encrypted at rest identically to conversation messages; no draft is structurally reachable from any visitor-facing or Telegram-outbound path; approved-content retrieval is bounded, allow-listed, and query-restricted to the conversation's own content, excluding all customer/order/comment/profile data and any operator-supplied search term by construction; concurrency and cooldown limits bound resource and cost exposure from repeated requests.

## Affected Documents/Milestones

ADR-0005 (boundary status line updated to "Implemented at M09," not a decision edit); ADR-0006 (`JobEnvelope`'s existing fail-closed policy reused unmodified); ADR-0009 (classification model applied to new AI-boundary fields); ADR-0014-equivalent circuit-breaker pattern reused under a new scope, not modified; M10 (controlled AI responses — must introduce its own ADR to relax this ADR's structural visitor-isolation decision).

## Compatibility/Migration Impact

Additive only: two new tables and one new nullable column across `Migrator` steps 19–21 (`db_version` 18→21). Step 19 seeds exactly one row (`id=1`) into `universal_telegram_ai_config` as part of the migration itself, since that row's existence is a precondition for the concurrency mutex in decision 5, not merely a convenience. `universal_telegram_ai_drafts` (step 20) includes the lease/claim columns (`lease_token`, `generation_lease_expires_at`, `claimed_at`, `attempt_count`) from its initial creation, not as a later alteration. No existing table's schema changes destructively; no existing REST route or repository method signature changes for any caller outside the new AI-boundary code this ADR introduces, except the conversation-creation endpoint gaining one new optional boolean field (`ai_ack`) that defaults safely to `false`/ineligible when absent, preserving backward compatibility with any existing client.

# Documentation, Versioning, Closure

- Plugin version: `0.10.0 → 0.11.0` (minor bump — new functional boundary, matching M05/M07/M08 precedent).
- Database: `db_version` `18 → 21` (three additive steps: config table, drafts table, conversation column).
- M09/M10 boundary: M09 ships operator-reviewed drafts only, zero auto-send paths, gated behind a real visitor choice; M10 (controlled AI responses) requires its own ADR to relax the structural visitor-isolation decision this ADR establishes. M10, M11, M12 remain unstarted and unscheduled by this plan.

# Consistency Check (self-review, revision 3)

- No path sends an unapproved draft to a visitor or Telegram: confirmed structurally via `AiDraftRepository` reader restriction + `StructuralBoundariesTest` extension (§ADR decision 6) — unaffected by this revision's locking/lease changes.
- Provider secrets/content never enter queue payloads, diagnostics, audit, or frontend: confirmed via `JobEnvelope` opaque-id-only payload and `CredentialVault`-only credential handling (§4, §Provider Abstraction); the new lease/claim columns are internal scheduling metadata, never exposed to the frontend or queue payload.
- Acknowledgement is a real, declinable, per-conversation, server-validated choice, not an inferred state: confirmed (§1, ADR decision 1); its public config (text/version) is now explicitly specified as cache-safe (§Administration/Operator UX, §Privacy matrix).
- No general-knowledge fallback exists; retrieval query cannot be supplied by any request or operator: confirmed (§2, ADR decision 2) — untouched by this revision.
- **Concurrency is now genuinely race-safe**: the one-active-draft check locks the always-present conversation row (§3.1-A), and the 2-generation cap locks the always-present, migration-seeded singleton config row (§3.1-B) — no aggregate/count-based lock remains anywhere in the plan.
- **No deadlock ordering risk**: the two lock paths never overlap on the same transaction, stated explicitly (§3.1).
- **Crash recovery is defined and bounded**: a time-bounded lease with compare-and-set release/completion means a crashed worker's row is eventually reclaimed, stale completions can never overwrite a newer claim.
- **The cooldown rule no longer contradicts itself**: exactly one rule — 30s after `failed`, zero after discard, hard-reject while retained — stated once (§3.2) and propagated consistently through the lifecycle table, UI copy, endpoint tests, ADR-0028, and the manual checklist.
- Persistence design is singular and fully specified at column/key/index/retention level, now including the lease/claim columns and the migration-time seed row (§4).
- All work packages map to explicit tests, including new concurrent-worker, lease-reclaim, and cooldown-specific test scenarios (§Work Packages, §Testing and Release Evidence).

# Consistency Check (self-review, revision 4)

- **A real, durable recovery trigger now exists**: the `ai_draft_lease_sweep` recurring Action Scheduler action (§3.5) is the stated mechanism that notices an expired lease and re-dispatches — the lease field is no longer, on its own, asserted to cause anything.
- **"No row remains `generating` indefinitely" is now a stated, bounded guarantee** (≈150 seconds worst case: 90s lease + 60s sweep interval), not an assumption.
- **The total attempt budget is bounded across crash/reclaim paths, not just caught exceptions**: `attempt_count` is shared and incremented on every claim regardless of origin, and the sweep itself refuses to re-arm past the budget, dead-lettering as `crashed_exhausted` instead.
- **The reliability guarantee is now honestly scoped**: it no longer claims "at-least-once, never zero" as a blanket statement; zero-provider-call terminal paths (circuit-open, `no_matching_source`, cancellation, terminal pre-call classification) are explicitly acknowledged as correct and by-design, and "at-least-once, not exactly-once" is scoped specifically to calls that have already begun.
- **The structural boundary rule is now factually correct**: `AiDraftRepository` access is a fixed six-class allow-list that includes the `AI\Draft\*` classes that must legitimately read/write it, rather than an unworkable "`Administration\AI\*` only" rule the plan's own required components would have violated.
- **The structural test design matches the corrected rule**: it asserts the referencing-class set equals the allow-list exactly, with explicit negative checks against visitor REST, widget, webhook, Telegram outbound, and unlisted `Administration\*` classes — narrower than a namespace-prefix rule, and it no longer forbids the plan's own necessary classes.
- All prior revision-3 corrections (race-safe locking, cooldown single-rule, cache-safe widget config) remain intact and consistent with these additions — the sweep and allow-list changes only add to, and do not contradict, §3.1–3.4 or §1–§2.
- Work packages, test traceability, ADR-0028, and the manual checklist all reflect the sweep job and the corrected allow-list; no section still states the old "read only by `Administration\AI\*`" rule or an unqualified "at-least-once, never zero" claim.
- ADR-0028 is complete in this document, including the revised locking/lease/sweep/allow-list decisions and their alternatives — not deferred.

**The plan is ready to freeze.**
