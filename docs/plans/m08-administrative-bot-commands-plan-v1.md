# M08 — Administrative Bot Commands — Implementation Plan v1

Status: Frozen — implementation authorized. This document is self-contained: it does not require a reader to consult any earlier conversation draft or planning-session transcript. This plan incorporates three correction passes (bounded WooCommerce query strategy, two-factor authorization, confirmation framework, and family/family-count wording) applied before freeze; inline references to "pass 1"/"pass 2"/"pass 3" below are retained as revision history, matching this repository's existing plan-revision convention (see `docs/plans/m07-operator-workflow-plan-v1.md`'s own "Revision 2"/"Revision 3" markers).

## 0. Baseline

- `origin/main` verified clean at `f2868268fcb30cf0e3cb034adf821f903b8c3bc8` (matches expected `f286826`, M07 technical closure). Working tree clean, `main == origin/main`.
- M07 Product Owner acceptance is **PENDING**. Recorded, does not block M08 planning.
- `docs/milestones/README.md`'s status table is stale for M05–M07 (unchanged finding from pass 1, §0).

## 1. Command catalogue and syntax (16 commands, six families)

Recognition rule (§3) applies to every command: a `bot_command` entity at offset 0, addressed to this bot.

### Family A — Help and identity (General topic or conversation topic)

| Command | Argument | Output source |
|---|---|---|
| `/help` | none | Fixed list of commands valid in current context |
| `/whoami` | none | Mapped operator's WP display name + current availability state |

### Family B — Site/queue status (General topic only)

| Command | Argument | Output source |
|---|---|---|
| `/status` | none | `QueueHealth::pending_count()`, `::failed_count()`, `::oldest_pending_age_seconds()`; `EventHistoryRepository::count_24h_by_source()` for `wordpress_core`/`woocommerce`/`visitor` |
| `/errors` | none | `EventHistoryRepository::count_24h_by_source('wordpress_core')` + `QueueHealth::failed_count()` |

### Family C — Visitor summary (General topic only)

| Command | Argument | Output source |
|---|---|---|
| `/visitors` | none | `EventHistoryRepository::count_24h_by_source('visitor')` — fixed 24h window (unchanged finding from pass 1: no configurable-window boundary exists) |

### Family D — WooCommerce commands (General topic only, WooCommerce-gated) — **now real, not stubs**

All four commands are implemented against a new `WooCommerceCommandQueryService` (§4a). WooCommerce inactive → every Family D command returns the identical fixed "WooCommerce is not active on this site" acknowledgement, checked first, before any argument parsing runs.

| Command | Argument | Exact output (nothing beyond this list) |
|---|---|---|
| `/orders` | none | Exact count of orders (any status) created in the trailing 24 hours, via a bounded count-only probe (§4a) — a real order count, replacing pass 1's event-log approximation. If the matching set exceeds the 500-record safe processing cap, returns the fixed "Too many matching orders — use the Hub." acknowledgement instead of a count |
| `/order` | one bounded numeric id (1–20 digits) | `status`, `date_created` (site timezone), `currency`, `total`, `item count`. Never customer name, email, address, payment details, shipping method, coupon codes, order notes, or line-item product names. Order not found, not retrievable, or not a normal order object → generic "not found or unavailable" (see §4a's oracle-avoidance rule) |
| `/stock` | one bounded token (1–100 chars, no wildcards) — treated as a SKU | `product name`, `stock-managed?` (yes/no), `stock quantity` (only if managed), `stock status` (in stock / out of stock / on backorder). The submitted SKU is never echoed back. No matching product → generic "not found or unavailable" |
| `/sales` | one of the literals `today`/`week`/`month` | Order count and gross total (sum of `get_total()`) for `completed`+`processing` orders in the fixed window (§4a timezone rule), computed only via the bounded paged-fetch strategy (§4a) — exact figures when the matching set is within the 500-record cap; if it exceeds the cap, the fixed "Too many matching orders — use the Hub." acknowledgement, with **no partial count or total ever returned**. No customer or order identifiers |

### Family E — Conversations list (General topic only)

| Command | Argument | Output source |
|---|---|---|
| `/conversations` | none | `ConversationRepository::for_inbox(status: 'open', limit: 10, offset: 0, bot_id: $bot_id)` — short `session_ref` + status only, capped at 10 rows |

### Family F — Conversation-topic operator workflow (conversation topic only)

| Command | Argument | Effect | Requires | Confirmation? |
|---|---|---|---|---|
| `/here` | none | Shows short reference, status, assignee's display name | mapped operator | No |
| `/presence` | `available\|busy\|offline` | Sets caller's own availability (General topic only, listed here for auth grouping) | mapped operator | No |
| `/claim` | none | CAS-assigns to caller (rejected if caller busy/offline) | mapped operator, not busy/offline | No |
| `/release` | none | CAS-clears assignment | **current assignee only** | No |
| `/resolve` | none | Transitions to `resolved` | **current assignee only**, status `open`/`waiting_*` | **Yes** |
| `/reopen` | none | Transitions `resolved → open` | **current assignee only** (tightened, §5), status `resolved` | **Yes** |
| `/confirm` | none | Executes the caller's own pending `/resolve` or `/reopen` | mapped operator, matching pending confirmation (§5) | — (is itself the confirmation) |

`/claim`, `/release`, and `/presence` remain immediate (no confirmation step) — justification in §5.

## 2. Command authorization and execution sequence

Unchanged from pass 1 (§2 steps 1–9: authenticity/dedup gates, entity-based recognition, chat-identity gate, two-factor sender authorization merged into `bot_command.rejected_unauthorized`, General/conversation-topic/unknown-topic context resolution with unknown-topic silence), with these additions:

10. **Family D dispatch** calls `WooCommerceCommandQueryService` (§4a) — a read-only wrapper the command dispatcher calls exactly as it calls any other injected repository; no new write path, no new REST route, no new background task.
11. **Family F write dispatch, revised**: `/claim`, `/release`, `/presence` execute immediately via the same repository calls as pass 1 (§7 rationale). `/resolve` and `/reopen`, once their own preconditions (assignee match, status eligibility) pass, do **not** call `transition()` yet — instead they call `ConfirmationStore::request($bot_id, $conversation_id, $wp_user_id, $command)` (§5) and send a fixed confirmation-instruction acknowledgement. `/confirm` calls `ConfirmationStore::consume($bot_id, $conversation_id, $wp_user_id)`; on a hit, it re-validates the same preconditions fresh (state may have drifted in the confirmation window) and only then calls `transition()`.
12. Acknowledgement and audit steps are otherwise unchanged from pass 1 (§2 steps 11–12).

## 3. Strict recognition and bot-username resolution — **verified against code, not assumed**

Re-checked directly (`src/Telegram/Configuration/BotProfile.php`, `BotProfileRepository.php`, `BotSetupWizardState.php`, `Administration/Telegram/BotManagementController.php`):

- **`telegram_username` is already persisted for every bot in this codebase.** `universal_telegram_bots.telegram_username VARCHAR(191) NULL` (existing column, `Migrator` — no migration needed). It is populated by `BotProfileRepository::update_telegram_identity(int $id, int $telegram_bot_id, string $telegram_username)`, called from `BotManagementController` after a **synchronous `TelegramApiClient::get_me()` call**, which itself runs during bot token registration/rotation (`BotManagementController` lines ~161/190/285) — this is an already-authenticated, already-existing verification flow, not something this plan adds.
- **`BotSetupWizardState::readiness()`** (line 89) already asserts `null !== $bot->telegram_username()` as part of "every bot created" being setup-complete — i.e., no bot capable of receiving webhook traffic in this codebase has ever existed without this field populated. There is no legacy-bot gap to migrate: `getMe` validation has been a precondition of bot creation since M01, before any bot row could be registered for webhook delivery at all.
- **Design, confirmed implementable with zero new persistence**: `CommandParser` receives the already-resolved `BotProfile` (`WebhookController::handle_request()` already resolves `$bot` before any further processing) and compares its `telegram_username()` case-insensitively against any `@suffix` on the parsed command word. A command addressed to `@someotherbot` is treated as not-a-command-for-us and falls through unchanged to existing non-command handling (§2 step 4, unchanged from pass 1). A bare command with no `@suffix` (the common single-bot-in-chat case) matches regardless of username, exactly as Telegram's own client behavior implies.
- No migration, no new registration flow, and no behavior-changing default is introduced by this design — pass 1's assumption is confirmed correct by code, not merely re-asserted.

## 4. Topic/context matrix

| Context | A | B | C | D | E | F (read: `/here`) | F (write: `/claim`/`/release`) | F (`/resolve`/`/reopen`, first send) | F (`/confirm`) |
|---|---|---|---|---|---|---|---|---|---|
| Chat-id mismatch | no effect, no reply, no audit — every command | | | | | | | | |
| General topic | OK | OK | OK | OK | OK | reject (acked) | reject (acked) | reject (acked) | reject (acked) |
| Conversation topic, `new`/`open`/`waiting_*` | OK | reject | reject | reject | reject | OK | `/claim` OK if not busy; `/release` OK only if assignee, else reject | `/resolve` OK only if assignee — sets pending confirmation; `/reopen` reject (wrong status) | consumes a pending `/resolve` only |
| Conversation topic, `resolved` | OK | reject | reject | reject | reject | OK | both reject | `/resolve` → "already resolved"; `/reopen` OK only if assignee — sets pending confirmation | consumes a pending `/reopen` only |
| Conversation topic, `resolved`, **unassigned** | OK | reject | reject | reject | reject | OK | reject | `/reopen` rejects for every mapped operator (no assignee to match) — Hub required | no pending confirmation possible |
| Conversation topic, `archived` | OK | reject | reject | reject | reject | OK | both reject (structural — `transition()`/`assign_with_expected()` refuse) | both reject (no `archived → *` edge) | no pending confirmation possible |
| Unknown topic | **silent, no reply, audited only** — every command |

## 4a. `WooCommerceCommandQueryService` — design

New file: `src/Integrations/WooCommerce/WooCommerceCommandQueryService.php`, in the existing WooCommerce integration boundary alongside `WooCommerceSupport` and the `Events/` emitters. **Read-only, documented-API-only, no persistence, no REST route, no background task, no mutation** — it never calls a WooCommerce setter, never schedules a job, and introduces no new table or column.

- **Order access** reuses the exact pattern `OrderEventEmitter` already documents and uses: `wc_get_order( $order_id )`, WooCommerce's own storage-agnostic (HPOS-compatible) accessor — no storage-backend branching, matching this codebase's existing M03 convention verbatim.
- **`order_summary(int $id): ?array`**: calls `wc_get_order($id)`; if the return is not a `WC_Order` instance (covers not-found, `WP_Error`-shaped failures, and non-order object types such as a refund id passed by mistake), returns `null` uniformly — the caller renders the identical "not found or unavailable" text regardless of *why* it was null, foreclosing an existence oracle exactly as ADR-0013's uniform-401 and ADR-0021's uniform-404 already establish for this codebase's other boundaries. On success, returns exactly `{status, date_created (site timezone via wp_date()), currency, total, item_count}` — built as a fixed-shape array so no other order property can leak by omission-mistake in a later edit.
- **`stock_summary(string $sku): ?array`**: `wc_get_product_id_by_sku($sku)` (documented WC function); `0` → `null` (same uniform not-found path). Otherwise `wc_get_product($id)`; if not a valid `WC_Product`, `null`. On success, returns `{name, manages_stock (bool), stock_quantity (int|null, only when managing_stock), stock_status}`. The SKU argument is used only as a lookup key and is never included in the returned array or in any audit context.
- **Bounded query strategy — hard cap, no unbounded load, ever.** `WooCommerceCommandQueryService` never calls `wc_get_orders()` with `'limit' => -1`, and never otherwise loads an unbounded order set into memory. Two fixed constants govern both `/orders` and `/sales`: `PAGE_SIZE = 100` (one page of `wc_get_orders()`, `'return' => 'objects'`) and `SAFE_PROCESSING_CAP = 500` (5 pages). Both commands follow the identical two-step shape:
  1. **Cheap count-only probe** (never loads order objects): `wc_get_orders(['status' => $statuses, 'date_created' => "{$since}...{$until}", 'limit' => PAGE_SIZE, 'paginate' => true, 'return' => 'ids'])` (WooCommerce's own documented, HPOS-safe pagination flag) — reads only the `->total` property of the returned pagination result object, which WooCommerce computes via a bounded-cost `COUNT`-shaped query regardless of how large the matching set is; no order object or id array beyond the first page is ever materialized by this step.
  2. **Cap check, then either an exact answer or the fixed refusal — never a partial one.** If `->total > SAFE_PROCESSING_CAP` (500), the method returns a distinguished `null`/cap-exceeded result immediately, without issuing any further query; the dispatcher renders the single fixed acknowledgement **"Too many matching orders — use the Hub."** with no count, no total, and no partial figure of any kind. If `->total <= 500`, the exact number of pages needed is already known (`ceil(->total / 100)`, at most 5), and only that many further `wc_get_orders()` calls are made — page `1..N`, `'limit' => 100`, `'page' => $page`. **Stop condition**: the loop terminates after exactly `ceil(->total / 100)` pages (never open-ended, never re-checking `->total` mid-loop); as a defensive belt-and-suspenders check, if cumulative rows processed would ever exceed 500 (only possible if the store's data changed between the probe and the fetch), the method aborts and returns the same cap-exceeded result rather than a partial sum — **a partial sales figure is never returned as if it were complete.**
- **`recent_order_count(): int|null`** (`/orders`): statuses = "any" (all statuses, matching the charter's own unqualified `/orders`), window = trailing 24 hours. Step 1 only is needed (count-only probe) — `/orders` never has to load order objects at all, since it reports a count, not a sum. Returns the exact `->total` when `<= 500`; returns `null` (cap-exceeded) when `> 500`, rendered as the same fixed "Too many matching orders — use the Hub." acknowledgement, never a truncated or lower-bound count.
- **`sales_summary(string $window): ?array`** (`today`/`week`/`month`, pre-validated by `CommandParser`): computes `[$since, $until]` in `wp_timezone()` (WordPress's configured site timezone — the "fixed timezone rule"): `today` = site-local calendar midnight to now; `week`/`month` = a trailing 7-day / 30-day rolling window ending now, matching the same rolling-window convention `EventHistoryRepository::count_24h()` already uses in this codebase, rather than a calendar week/month (avoids calendar-boundary ambiguity). Statuses = `['completed', 'processing']`. Runs the full two-step strategy above; when within the cap, sums `(float) $order->get_total()` across the bounded page fetch and returns `{count, gross_total}`; when the cap is exceeded, returns `null`, rendered as the identical fixed cap-exceeded acknowledgement — no partial count, no partial total. No customer or order identifier is ever included in either outcome.
- **Bounded by construction, doubly so**: every method already operates over a small, fixed set of arguments (one numeric id, one bounded token, one of three window literals), and the query volume itself is now hard-capped at 500 records / 5 pages regardless of store size or argument content — there is no caller-controlled unbounded query shape at any layer, and Telegram's own per-chat send rate additionally bounds invocation frequency (pass 1 §8, unchanged).

## 5. Confirmation framework for `/resolve` and `/reopen`

**Mechanism: WordPress core transients (`set_transient`/`get_transient`/`delete_transient`), not a new table.**

- **Why a transient, not a new persistence mechanism**: (1) the confirmation state is genuinely ephemeral (60-second lifetime) with no audit or durability requirement of its own — an expired confirmation has zero data-loss consequence, the operator simply re-sends `/resolve`; (2) it introduces no schema migration, keeping `db_version` at `18` and matching this plan's existing "no schema change" posture (pass 1 §5, unchanged); (3) WordPress core's transient API already handles expiry natively, so this plugin writes no sweep/cleanup logic of its own; (4) **direct precedent already exists in this codebase**: `Administration/Diagnostics/DiagnosticsPage.php` already uses `set_transient(..., 60)` for short-lived, non-critical cached state — this plan reuses the identical TTL and mechanism, not a novel pattern.
- **Key**: `ut_telegram_cmd_confirm_{bot_id}_{conversation_id}_{wp_user_id}` — a deterministic composite of three already-resolved, all-INTERNAL identifiers. This composition is itself the enforcement mechanism for "only the same mapped operator, same bot, same known conversation topic may confirm": `/confirm` can only reconstruct the identical key if all three match the original request's own resolved identity and context, which are independently re-verified by the unchanged authorization/context pipeline (§2) on every `/confirm` exactly as on every other command. No token or nonce beyond the key itself is needed.
- **Value**: the single literal `resolve` or `reopen` — nothing else. Never a Telegram id, never message content.
- **TTL**: 60 seconds, matching the existing `DiagnosticsPage` precedent.
- **Replay-safety**: `/confirm` calls `get_transient()` then immediately `delete_transient()` on the same key before executing anything — single-use by construction. A second `/confirm` (duplicate, or a genuine race) finds nothing and receives the same "no pending confirmation" response as an expired or never-requested one (non-disclosing, consistent with this plan's existing philosophy of merged, non-enumerating rejection outcomes).
- **Defense in depth against a true concurrent race**: even in the theoretical case of two `/confirm` webhook deliveries processed concurrently before either `delete_transient()` commits, the actual mutation is still gated by `ConversationRepository::transition()`'s own database-level compare-and-set (`WHERE id = ? AND status = ?`, unchanged from ADR-0021/ADR-0026) — a transient-level race can, at worst, attempt the same transition twice; the second attempt matches zero rows and is a silent no-op, never a double-transition or a double-audit-entry beyond what the existing `transition()` guard already prevents. The transient is a UX/authorization gate, not the safety-critical write itself.
- **Freshness re-check on `/confirm`**: because up to 60 seconds elapse between request and confirm, `/confirm` re-validates the same preconditions the original command checked (still the assignee, status still eligible) before calling `transition()` — if state drifted (e.g. a Hub-side action already changed status, or reassigned the conversation), `/confirm` returns the same idempotent-safe "already resolved"/"already open"/"no longer eligible" messaging pass 1 already defined for direct re-sends, never a stale write.
- **No audit entry at the request phase** (no state has changed yet) — matching the existing convention that only successful state-changing actions are audited (pass 1 §6, unchanged). The eventual `transition()` success, once confirmed, is audited exactly as pass 1 specified (`conversation.status.resolved` / `conversation.status.reopened`, `source=telegram_command`).

### `/claim`, `/release`, `/presence` remain immediate — explicit justification

- **`/claim`**: gated by `assign_with_expected()`'s own database CAS (a stale expectation cannot silently overwrite a concurrent claim) and by the busy/offline self-check; fully reversible in one step via `/release`; assigning a conversation to oneself neither destroys data nor changes what a visitor sees. Low enough risk that a confirmation round-trip would add friction with no safety benefit.
- **`/release`**: restricted to the current assignee only (tightened alongside `/reopen`, unchanged from pass 1 for this command); reversible in one step via `/claim`; only the assignment pointer changes, the conversation and its history are untouched.
- **`/presence`**: affects only the acting operator's own visibility state, has zero effect on any conversation or any other operator, and is trivially reversible by re-sending `/presence` with a different value.
- **`/resolve`/`/reopen`, by contrast**: a status transition has real downstream consequences this codebase already treats as consequential — `resolved` starts the retention countdown (`RetentionCleanupHandler`'s 30-day-then-90-day purge clock, unchanged from ADR-0021) and changes what the conversation's own transition map permits next; this is exactly the class of action M08's charter names "confirmation framework" for, and is why these two, and only these two, require it.

## 6. Privacy and audit matrix

| Command outcome | Action code | actor_type | Context fields (all INTERNAL) |
|---|---|---|---|
| Unmapped sender **or** capability-revoked sender | `bot_command.rejected_unauthorized` | system | `bot_id` |
| Wrong context (known topic, acked) / unknown topic (silent) | `bot_command.rejected_wrong_context` | system | `bot_id`, `command` |
| `/presence` set | `conversation.operator_availability.set` (reused) | operator | `target_user_id`, `state`, `source=telegram_command` |
| `/claim` success | `conversation.assignment.set` (reused) | operator | `conversation_id`, `operator_user_id`, `source=telegram_command` |
| `/release` success | `conversation.assignment.cleared` (reused) | operator | `conversation_id`, `source=telegram_command` |
| `/resolve` confirmed | `conversation.status.resolved` (new, unchanged from pass 1) | operator | `conversation_id`, `source=telegram_command` |
| `/reopen` confirmed | `conversation.status.reopened` (reused) | operator | `conversation_id`, `source=telegram_command` |
| `/resolve`/`/reopen` request (pending confirmation) | none (no state change yet) | — | — |
| `/confirm` with no pending entry | none (matches existing no-op-reject precedent) | — | — |
| Family A/B/C/E reads | none | — | — |
| Family D reads (`/orders`,`/order`,`/stock`,`/sales`) | none — read-only, matching existing precedent that reads are unaudited | — | — |

No command output ever includes: bearer secret, message ciphertext/plaintext, visitor display name, raw Telegram numeric id, Telegram username, internal database primary key, WooCommerce customer name/email/address/payment/shipping/coupon/notes/line-item product names, or a submitted SKU echoed back.

## 7. Capability and identity-mapping model

Unchanged from pass 1 (§7): two-factor, live-checked (`OperatorIdentityRepository` mapping + fresh `user_can(..., MANAGE_CONVERSATIONS)`), merged into one non-enumerating outcome for both failure causes. `/confirm` and Family D's real WooCommerce queries are subject to the identical pipeline as every other command — no separate or weaker check for reads.

## 8. Error, duplicate, and rate-limit semantics

Unchanged base rules from pass 1 (§8: malformed/unknown falls through or gets a generic malformed-command ack; duplicate updates covered by existing `(bot_id, update_id)` dedup; no new rate limiter). Additions:

- **Confirmation-specific replay/expiry/wrong-actor semantics** are specified in full in §5 (single-use transient, 60s TTL, key-scoped to bot+conversation+operator, freshness re-check on confirm).
- **`/order`/`/stock` not-found is never distinguished from "exists but unreadable"** — both return the identical fixed string (§4a), preventing an operator (or anyone able to trigger a command, bounded by §7's authorization) from using the response to enumerate valid order ids or SKUs beyond what their own WooCommerce-domain knowledge already gives them.
- **`/resolve` remains new domain surface, not reused** (unchanged finding, pass 1 §7): confirmed via code that no operator-triggered call site for `open/waiting_* → resolved` existed before this plan.
- **`/orders`/`/sales` cap-exceeded is a distinct, fixed outcome, never a partial one.** When the bounded count-only probe (§4a) reports more than 500 matching orders, the command returns the single fixed acknowledgement "Too many matching orders — use the Hub." — no count field, no total field, no lower-bound or truncated figure is ever included. This is not an error in the malformed-command sense (the command syntax was valid) and not the WooCommerce-inactive acknowledgement (WooCommerce is active and answered) — it is its own third fixed outcome, so an operator can tell "not configured," "syntactically wrong," and "too much data for this channel, use the Hub" apart without any of the three ever leaking a number.

## 9. Work packages

1. **WP1 — Command grammar and recognition.** `CommandParser` (entity-based, `@username` resolved via `BotProfile::telegram_username()` — confirmed pre-existing, §3), `CommandCatalogue` (sixteen-command fixed set). Files: `src/Telegram/Commands/CommandParser.php`, `CommandCatalogue.php`. No DB impact. Tests: entity-offset matching, `@username` disambiguation (own bot / other bot / no suffix), per-family argument-shape validation. Commit: "feat(telegram): strict entity-based command grammar and catalogue (M08 WP1)".
2. **WP2 — Webhook dispatch wiring and two-factor authorization.** `WebhookController` extension: recognition branch, merged `bot_command.rejected_unauthorized`, three-way context resolution with unknown-topic silence. Tests: chat-mismatch silent-drop, unmapped/capability-revoked rejection (identical outcome), unknown-topic silence, known-topic wrong-context ack, non-command fallthrough unchanged. Commit: "feat(telegram): two-factor command authorization and context resolution (M08 WP2)".
3. **WP3 — Family A/E commands.** `/help`, `/whoami`, `/conversations`. Commit: "feat(telegram): help, identity, and conversation-list commands (M08 WP3)".
4. **WP4 — Family B/C commands.** `/status`, `/errors`, `/visitors`. Commit: "feat(telegram): site status, error, and visitor summary commands (M08 WP4)".
5. **WP5 — `WooCommerceCommandQueryService` and Family D commands.** New service (§4a) plus `/orders`, `/order`, `/stock`, `/sales`. Files: `src/Integrations/WooCommerce/WooCommerceCommandQueryService.php`, dispatcher wiring. Tests: order found/not-found/unreadable (identical response), SKU found/not-found (SKU never echoed), sales window boundary correctness (site-timezone `today`, rolling `week`/`month`) against fixture orders, WooCommerce-inactive branch for all four, malformed-argument-vs-stub-response distinction preserved, **`/orders` and `/sales` exact-figure result when the fixture set is at or under 500 matching orders, `/orders` and `/sales` returning the fixed cap-exceeded acknowledgement with no count/total field present at all when the fixture set exceeds 500 (mocked `wc_get_orders(['paginate' => true, ...])` returning `->total = 501` — no real 500-order fixture need be materialized), confirmation that no test double or assertion ever permits a `'limit' => -1` call, and confirmation that the paged-fetch step is invoked at most `ceil(->total / 100)` times**. Commit: "feat(telegram): real, bounded WooCommerce order/stock/sales query commands (M08 WP5)".
6. **WP6 — `ConfirmationStore` and `/resolve`/`/reopen`/`/confirm`.** New `src/Telegram/Commands/ConfirmationStore.php` (thin wrapper over `set_transient`/`get_transient`/`delete_transient`, §5). Tests: duplicate `/confirm` (second is no-op), expiry (mocked/forced TTL elapse → "no pending confirmation"), wrong-operator `/confirm` (different mapped operator, same conversation → no match), wrong-topic `/confirm` (same operator, different conversation → no match), already-transitioned-before-confirm (state drift during the 60s window → idempotent-safe message, no stale write), reopen-requires-assignee (a different mapped operator's `/reopen` request rejected outright, never reaches confirmation), unassigned-resolved-conversation `/reopen` rejected for every operator. Commit: "feat(telegram): confirmation-gated resolve/reopen and immediate claim/release/presence commands (M08 WP6)".
7. **WP7 — Diagnostics.** Existing Diagnostics Hub tab gains a read-only bot-commands panel (activity/rejection counts, no raw ids). Commit: "feat(telegram): bot command diagnostics panel (M08 WP7)".
8. **WP8 — Docs, ADR-0027 freeze, version.** Freeze ADR-0027 (§11, full text), milestone/registry doc corrections, operator onboarding note, version bump. Commit: "docs: M08 administrative bot commands — plan, ADR-0027, version bump".

## 10. Test plan

Unchanged scope discipline from pass 1 (§10): tests added per work package; final local validation gate scoped to the changed seams (`WebhookController`, `Telegram/Commands/*`, `Integrations/WooCommerce/WooCommerceCommandQueryService`, the reused `Administration/Conversations`/`Administration/Diagnostics` call sites) via PHPCS/PHPStan on changed files and the relevant PHPUnit suites — not an unfiltered full-repository sweep. GitHub Actions remains the full independent matrix (including its existing `integration-wc-present-current` job, which now has real coverage value for WP5's WooCommerce-active branch). No live Telegram calls at any point.

## 11. ADR-0027 (complete text)

# ADR-0027 — Administrative Bot Commands: Entity-Based Recognition, Two-Factor Authorization, Bounded Read-Only WooCommerce Queries, and Confirmation-Gated Lifecycle Transitions

## Status

Proposed

## Context

M08's charter requires a command registry; read-only status, order, stock, error, sales, visitor, and conversation commands; capability mapping; a confirmation framework; auditing; and an extension API, excluding all write commands beyond what M07 already established and prohibiting arbitrary WordPress, SQL, shell, or PHP execution. No existing mechanism distinguishes a Telegram bot command from ordinary operator-reply text. `OperatorIdentityRepository`'s mapping (ADR-0026) verifies identity only, not a live WordPress capability. `BotProfile::telegram_username` is already persisted for every bot via an existing `getMe`-backed registration flow (§3 of the governing plan), so no new bot-identity mechanism is needed. No per-order, per-product, or revenue query boundary existed before this ADR; `Integrations\WooCommerce\WooCommerceSupport` exposed only `is_active()`. No confirmation mechanism exists anywhere in this codebase for a Telegram-triggered action.

## Decision

1. **Command recognition is entity-based.** A message is a command only if `message.entities` contains a `bot_command` entity at `offset === 0`; the covered word is matched against a fixed sixteen-command literal set; an `@username` suffix, if present, must case-insensitively match the receiving bot's own already-persisted `telegram_username` (confirmed populated for every bot capable of receiving webhook traffic, via the existing `getMe`-backed `BotManagementController`/`BotProfileRepository::update_telegram_identity()` flow — no new field, no migration). A mismatch is treated as addressed to a different bot and falls through to existing non-command handling.
2. **Authorization is two-factor and outcome-merged**, unchanged from this ADR's own earlier draft basis: `OperatorIdentityRepository` mapping plus a freshly evaluated `user_can($mapped_user_id, MANAGE_CONVERSATIONS)`, both failures collapsing to one audit code, `bot_command.rejected_unauthorized`, carrying only `bot_id`.
3. **Unknown-topic context is fully silent** for every command, authorized or not — no acknowledgement into a topic this plugin has no record of.
4. **Read-only commands reuse existing bounded query boundaries, extended by one new, narrowly scoped, hard-capped service.** `/status`/`/errors`/`/visitors`/`/conversations` reuse `QueueHealth`, `EventHistoryRepository`, and `ConversationRepository::for_inbox()` exactly as before. `/orders`, `/order`, `/stock`, and `/sales` are now backed by a new `Integrations\WooCommerce\WooCommerceCommandQueryService` — read-only, using only documented, HPOS-safe WooCommerce functions (`wc_get_order()`, matching `OrderEventEmitter`'s existing HPOS-compatible access pattern; `wc_get_product_id_by_sku()`; `wc_get_product()`; `wc_get_orders()`), introducing no new table, column, REST route, or background task. `wc_get_orders()` is **never called with `'limit' => -1`, and no unbounded order set is ever loaded**: `/orders` and `/sales` first issue a cheap `'paginate' => true` count-only probe (`PAGE_SIZE = 100`), then either compute an exact result from at most `ceil(total / 100)` further 100-row pages when `total <= SAFE_PROCESSING_CAP` (500), or return the single fixed "Too many matching orders — use the Hub." acknowledgement — with no partial count or total ever surfaced — when the cap is exceeded (full strategy: governing plan §4a). Output is otherwise a fixed, narrow field set per command; a not-found or unreadable order/product produces the identical generic response regardless of cause, foreclosing an existence oracle exactly as ADR-0013's uniform-401 and ADR-0021's uniform-404 already establish elsewhere in this codebase. This is the read-only WooCommerce-command portion of M08's own charter, not new scope.
5. **`/resolve` and `/reopen` require confirmation; `/claim`, `/release`, and `/presence` do not.** Confirmation state is a WordPress core transient (`set_transient`/`get_transient`/`delete_transient`, 60-second TTL — the identical mechanism and TTL `Administration/Diagnostics/DiagnosticsPage.php` already uses for other short-lived state), keyed deterministically on `(bot_id, conversation_id, wp_user_id)`, value the pending command literal, consumed exactly once via `/confirm`. The key composition itself enforces same-operator/same-bot/same-conversation; `/confirm` re-runs the full authorization/context pipeline and re-validates the underlying command's own preconditions fresh before executing, so state drift during the confirmation window degrades to the same idempotent-safe messaging a direct re-send already produces, never a stale write. The eventual mutation remains protected by `ConversationRepository::transition()`'s own database-level CAS regardless of any transient-layer race, so the transient is a UX/authorization gate, not the safety-critical write. `/claim`/`/release`/`/presence` are exempted because each is independently guarded by an existing CAS or assignee restriction, is a single, low-blast-radius, immediately-reversible action, and produces no downstream side effect analogous to `resolved`'s retention-timer start.
6. **`/reopen` now requires the current assignee**, matching `/resolve` and `/release`. This is a deliberate, Telegram-command-specific tightening beyond the existing web-UI `ConversationActionHandler::reopen()`, which remains unchanged (any `MANAGE_CONVERSATIONS` holder may reopen via the Hub) — reassignment or an administrator override of this restriction is a Hub-only workflow, never available from Telegram. A resolved-and-unassigned conversation cannot be reopened by any mapped operator from Telegram; the Hub is required.
7. **Every state-changing command reuses M07's exact write paths** — unchanged from this ADR's earlier basis: `assign_with_expected()`, `transition()`, `OperatorAvailabilityRepository::set_state()`, with an added `source: 'telegram_command'` audit-context field.

## Alternatives

- *(Unchanged from the earlier draft basis of this ADR)* entity-vs-prefix recognition, mapping-only vs. two-factor authorization, split vs. merged rejection codes, and silent-vs-acknowledged unknown-topic handling — see the governing plan §2–§3 for the full reasoning, reused verbatim here.
- *Build a general WooCommerce order/product query API rather than four fixed, narrow-output commands.* Rejected: the charter scopes this to specific, bounded operator actions, not an ad hoc query surface; a general API would reintroduce the "arbitrary query" risk this milestone's own architectural constraint explicitly prohibits.
- *Call `wc_get_orders(['limit' => -1, ...])` and sum/count in PHP.* Rejected: an unbounded load is a synchronous, Telegram-input-triggered cost proportional to store size, executed inside the same webhook-handling request this codebase otherwise deliberately keeps bounded and low-cost (ADR-0013's own "bounded, synchronous, low-cost work" posture for the webhook handler) — a large store could make a single `/sales month` command load and sum thousands of order objects in one request. Rejected in favor of the two-step, hard-capped strategy (governing plan §4a): a cheap count-only `'paginate' => true` probe first, then at most 5 further 100-row pages only when the total is already known to be within a 500-record cap.
- *When the matching set exceeds the cap, return a truncated or first-N-only count/total instead of refusing outright.* Rejected: a truncated figure presented without qualification is indistinguishable from a complete one to an operator glancing at a Telegram reply, and is worse than no figure at all — the charter's own privacy/accuracy posture is better served by an explicit "too much data for this channel, use the Hub" refusal than by a silently-partial number.
- *Treat a not-found order/SKU differently from an unreadable one (e.g. distinguishing a trashed order).* Rejected: any distinguishable response becomes a probing signal; uniform failure is the same posture this codebase already applies to its other authentication/lookup boundaries.
- *Use a new database table for confirmation state instead of a transient.* Rejected: the state is genuinely ephemeral and non-critical (§5), a table would require a migration this plan has otherwise avoided entirely, and WordPress core's transient expiry removes the need for this plugin to write its own cleanup sweep — and a directly analogous precedent (`DiagnosticsPage`) already exists in this codebase using the identical mechanism and TTL.
- *Require confirmation for `/claim`/`/release`/`/presence` too, for uniformity.* Rejected: none of the three carries a downstream consequence comparable to `resolved`'s retention-timer start, each is already CAS- or assignee-guarded, and each is a one-step reversible action — a confirmation round-trip would add friction with no corresponding safety gain, and the charter's own "confirmation framework" language is reasonably read as scoped to genuinely consequential actions, not every write.
- *Leave `/reopen` open to any mapped operator, matching the web UI.* Rejected on explicit correction: a bot command has no confirmation-dialog affordance to show a MANAGE-level override warning the way the Hub UI does, so the bot surface adopts the strictly narrower assignee-only rule; anyone needing the Hub's broader reopen/reassignment behavior already has it available there.

## Consequences

Family D graduates from pass-1 stubs to genuine, narrowly scoped WooCommerce read access, fully bounded by fixed output shapes, documented WooCommerce APIs, and a hard 500-record/5-page processing cap on `/orders` and `/sales` — no general query capability is introduced, no unbounded synchronous load is ever possible regardless of store size, and a future milestone wanting broader commerce-domain access still needs its own ADR. `/resolve` and `/reopen` gain a lightweight, low-overhead confirmation step that adds no schema and reuses an existing in-codebase pattern; the underlying transition safety was never dependent on this step (the database CAS already provided it) — confirmation is purely a "did the operator mean this" gate. `/reopen`'s new assignee restriction means a resolved-and-unassigned conversation, or one assigned to a different operator, can only be reopened via the Hub — an intentional, narrower Telegram-command posture than the web UI's own.

## Security and privacy impact

Family D's fixed-shape outputs and uniform not-found handling ensure no customer PII, payment data, or exact SKU is ever disclosed via Telegram, and no order/product existence can be enumerated beyond what a legitimately authorized operator's own WooCommerce-domain knowledge already provides. The 500-record/5-page cap on `/orders` and `/sales` is itself a security-relevant bound, not just a UX one: it removes the possibility of a single Telegram-triggered command forcing a synchronous, store-size-proportional database load inside the webhook-handling request, and its cap-exceeded outcome never degrades into a silently-partial figure that could be mistaken for a complete one. The confirmation mechanism adds no new sensitive-data surface (its transient value is a fixed literal, its key is composed only of already-INTERNAL identifiers) and closes a "fat-fingered destructive action" usability gap without weakening any existing authorization or CAS guarantee. The `/reopen` assignee tightening closes a cross-operator-interference gap the bot surface would otherwise have inherited unnecessarily from the web UI's own deliberately broader administrator affordance.

## Affected Documents/Milestones

ADR-0013, ADR-0021, ADR-0026, ADR-0010 (all reused unchanged, per the governing plan §2–§3, §7). `docs/milestones/m08-administrative-bot.md`. `Administration/Diagnostics/DiagnosticsPage.php` (cited as the transient-mechanism precedent, not modified by this ADR).

## Compatibility/Migration Impact

None. No schema change, no new table or column, `db_version` remains `18`. No existing REST route, webhook behavior, or repository/service method signature changes for any existing caller. `WooCommerceCommandQueryService` is a new, additive class with no existing consumer to break.

## 12. Manual Product Owner acceptance checklist (post-merge, existing configured dev bot only)

1. Map a test operator; confirm `/help` lists the commands valid in the current context.
2. Unmapped sender `/claim` in an open conversation topic — no reply, no change, one `bot_command.rejected_unauthorized` row.
3. Mapped operator with `MANAGE_CONVERSATIONS` removed — repeat step 2, identical outcome and action code.
4. `/presence busy` then `/claim` — rejection; `/presence available` then `/claim` — success, `/here` reflects it.
5. `/resolve` as assignee — confirm the fixed confirmation-instruction ack is sent, **no status change yet**; then `/confirm` — confirm the transition and its audit row.
6. Send `/resolve` again without ever sending `/confirm` and wait past 60 seconds, then `/confirm` — confirm "no pending confirmation," no change.
7. `/resolve`, then a **different** mapped operator sends `/confirm` in the same topic — confirm no match, no change.
8. `/resolve`, then the same operator sends `/confirm` in a **different** conversation topic — confirm no match, no change.
9. `/resolve`, `/confirm`, then `/confirm` again immediately — confirm the second is a no-op ("no pending confirmation").
10. `/resolve`, then (via the Hub, simulating drift) reassign the conversation to a different operator before sending `/confirm` — confirm the original operator's `/confirm` produces the idempotent-safe "no longer eligible" message, not a stale transition.
11. `/reopen` on a resolved conversation from a mapped operator who is **not** the assignee — confirm outright rejection, never reaching a confirmation prompt.
12. `/reopen` on a resolved-but-unassigned conversation — confirm rejection for every mapped operator; perform the reopen via the Hub instead and confirm it succeeds there.
13. `/status`, `/errors`, `/visitors` in the General topic — bounded, non-sensitive output matching the Diagnostics page for the same window.
14. `/orders` — confirm a real trailing-24h order count (verified against the WooCommerce admin order list for the same window), assuming the dev store is under the 500-record cap.
15. `/order <a real order id>` — confirm exactly `status`/`date`/`currency`/`total`/`item count`, nothing else. `/order <a nonexistent id>` — confirm the identical "not found or unavailable" text as a real-but-unreadable case.
16. `/stock <a real SKU>` — confirm `name`/`managed?`/`quantity`/`status` only, and that the SKU itself is never echoed back. `/stock <a nonexistent SKU>` — confirm the identical "not found or unavailable" text.
17. `/sales today`, `/sales week`, `/sales month` — confirm count + gross total only, matching WooCommerce's own reporting for the same window and site timezone, assuming the dev store is under the 500-record cap for each window.
18. With WooCommerce deactivated, repeat `/orders`/`/order`/`/stock`/`/sales` — confirm the identical fixed "WooCommerce is not active" acknowledgement for all four.
19. `/conversations` — at most 10 rows, short reference and status only.
20. A command inside a manually created, unrelated forum topic — confirm no reply at all.
21. `/help@someotherbot` in the support group — confirm this plugin's bot does not respond.
22. Confirm no acknowledgement anywhere in this session ever contains a raw Telegram id, WP user id, bearer secret, WooCommerce customer/payment/shipping/coupon/note detail, line-item product name, or a submitted SKU.
23. Cap-exceeded behavior (`/orders`/`/sales` returning "Too many matching orders — use the Hub." with no count/total) is not expected to be reproducible against the live dev store (which will not plausibly hold 500+ orders in any tested window) — treat WP5's automated test with a mocked `->total = 501` (§9) as the accepted evidence for this specific behavior rather than requiring a live reproduction here.

## 13. Version/database/documentation recommendation

- **Version**: minor bump, `0.9.0 → 0.10.0` — unchanged rationale, now covering the full charter command surface including real WooCommerce reads and a confirmation framework.
- **Database**: no change, `db_version` stays `18` — confirmed again under pass 2 (WooCommerce queries are live reads against WooCommerce's own storage; confirmation state is a transient, not a table).
- **Documentation**: unchanged from pass 1 (§13), plus a note that `/resolve`/`/reopen` require a `/confirm` follow-up within 60 seconds, and that `/reopen` is assignee-restricted on Telegram specifically (Hub remains unrestricted to `MANAGE_CONVERSATIONS` holders).

## 14. Closure requirements

Unchanged from pass 1 (§14), with the manual acceptance checklist now §12 above (23 items) and ADR-0027's complete text included in this draft rather than deferred to freeze (unchanged from pass 1's own correction).

**Final self-check (pass 3):** `WooCommerceCommandQueryService` contains no `'limit' => -1` call and no code path that loads an order set without first bounding it via the `'paginate' => true` count-only probe; `/orders` and `/sales` are the only two commands with query volume at all, and both share the identical 500-record/5-page cap and the identical fixed cap-exceeded acknowledgement; no cap-exceeded path returns a partial count or total under any circumstance; site-timezone (`wp_timezone()`) and fixed-window (`today`/rolling `week`/rolling `month`) semantics are unchanged from pass 2; no customer name, email, address, payment detail, shipping method, coupon code, order note, line-item product name, or order/SKU identifier is returned by any Family D command in any outcome (found, not-found, WooCommerce-inactive, or cap-exceeded); the catalogue now correctly states six families (A–F).
