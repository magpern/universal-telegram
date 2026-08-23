# M07 — Operator Workflow — Closure Record

## Status

**PASS** (automated/technical verification). Product Owner acceptance is **PENDING** — manual
Telegram/topic operator-workflow testing has not yet been performed (no live Telegram calls,
bot/destination/webhook changes, releases, tags, or deployments occurred as part of this task).

## Baseline, freeze, PR, merge, and closure SHAs

- Baseline (prior `main`): `a126a85fa88db300b5a34103e71bf11b26c5ed98`
- Feature branch: `feature/m07-operator-workflow`
- Freeze commit (plan doc + ADR-0026): `1419c4d` — `docs: freeze M07 operator workflow plan`
- PR: [#21](https://github.com/magpern/universal-telegram/pull/21)
- Merge commit: `c0b45b0ac376f327857a0cbb8506326b1c214f73` (into `main`, merge strategy: merge commit)
- Closure commit: pushed directly to `main` immediately following this document

## Work-package and repair commits

| WP | Commit | Summary |
|----|--------|---------|
| WP1 | `64ff393` | Schema migrations 17–18 (`operator_identities`, `conversation_notes`, `operator_availability`; `assignee_last_seen_message_id`, indexed `telegram_sender_user_id`) and the `MANAGE_CONVERSATIONS` capability |
| WP2 | `12b502e` | `RESOLVED → OPEN` reopen transition; extracted shared `ConversationPurgeService` out of `RetentionCleanupHandler`'s own inline 90-day purge sequence |
| WP3 | `d9d8d7a` | Operator identity mapping (`OperatorIdentity[Repository]`, `OperatorIdentityPage`/`RequestHandler`); full operator-account-deletion cleanup sequence wired onto `deleted_user`, additive to the existing M06.3.1 visitor-owner cleanup |
| WP4 | `4593888` | Inbound Telegram operator-authorization gate — `WebhookController::maybe_route_to_conversation()` rejects an unmapped sender before any decrypt/store/transition/forward; rejected-sender audit entry carries only a fixed code plus `bot_id`/`conversation_id` |
| WP5 | `fcaf835` | Three-state operator availability (`OperatorAvailability[Repository]`); `ConversationActionHandler` self-service vs. `MANAGE` override split |
| WP6 | `257c2c5` | Operator inbox + detail view (`ConversationInboxPage`/`ConversationDetailPage`); unread derivation (`mark_seen()`, `unread_assigned_conversations()`) from `assignee_last_seen_message_id` |
| WP7 | `2f543d3` | Concurrency-safe (`assign_with_expected()`) assignment/unassignment; reopen, add-note, and busy/offline-override lifecycle actions on `ConversationActionHandler` |
| WP8 | `20a565f` | Archived-only manual conversation deletion via the shared `ConversationPurgeService` |
| WP9 | `a14e35a` | Bounded conversation search (uuid prefix, bot, assignee, date range) on the operator inbox — never a Telegram id/username filter |
| WP10 | `b995dd9` | Version bump `0.8.1` → `0.9.0`; `db_version` documentation; milestone status update |

**Phase C repair rounds:**

| Round | Commit | Defect class |
|-------|--------|--------------|
| 1 | `2fa3560` | Six pre-existing `Migrator*SchemaTest` files hardcoded the prior `target_version` (16) and needed updating to 18 after WP1 raised the schema target; PHPCS formatting (`phpcbf`) and one established `NonceVerification.Missing` exclude-pattern addition (matching the existing `RuleBuilderRequestHandler` precedent) for the two new op-dispatch handlers, whose nonce is verified once in `handle_request()` before dispatch |
| 2 | `c084454` | `Uninstaller::run()` never dropped M07's three new tables on opt-in `remove_data_on_uninstall`; `tests/package/run.sh` had hardcoded hub-tab-count/order (9) and `db_version` (16) expectations, both updated for M07's two new tabs and schema |
| 3 | `48f43ac` | CI-only (not reproducible in a locally-reused/warm database): `UninstallTest::recreate_all_tables()` hand-duplicated `Migrator`'s DDL and had silently gone stale since M05 (committing `db_version=12` with only M05-era table shapes, missing every M06 column and now M07's tables) — dormant since no migration step after M05 used `CREATE TABLE` until M07's step 17, at which point WordPress core's own `_create_temporary_tables()` test-transaction query filter (rewriting `CREATE TABLE` → `CREATE TEMPORARY TABLE`, invisible to `INFORMATION_SCHEMA`) caused `Migrator`'s own postcondition check to fail for any test running after `UninstallTest` in the same process. Fixed at the root by having `recreate_all_tables()` call the real `Migrator` instead of hand-copied DDL, so it can never drift stale again on a future milestone's own schema additions. |

## Version/database transition

- Plugin version: `0.8.1` → `0.9.0` (minor bump — identity-gated inbound authorization, operator
  presence/unread state, an operator inbox/detail view, concurrency-safe assignment, a reopen
  transition, archived-only manual deletion via a shared purge service, and bounded conversation
  search together constitute a genuine new functional-capability class, not a patch-scale fix).
- Database: `Migrator` steps 17–18, `db_version` `16` → `18`.
  - Step 17 — new tables: `universal_telegram_operator_identities`,
    `universal_telegram_conversation_notes` (`operator_user_id` nullable, to support
    anonymization on operator account deletion), `universal_telegram_operator_availability`.
  - Step 18 — alters: `universal_telegram_conversations` (+ `assignee_last_seen_message_id`),
    `universal_telegram_conversation_messages` (+ indexed `telegram_sender_user_id`).
  - `Uninstaller` extended to drop all three M07 tables on opt-in full removal.

## Security and privacy posture

- **Inbound authorization**: the manually maintained WordPress-user ↔ Telegram numeric-id mapping
  (`OperatorIdentityRepository`) is the sole inbound Telegram operator-authorization gate. An
  unmapped sender's reply produces no message row, no ciphertext write, no status transition, and
  no visitor-side forwarding — the identical fail-closed shape as every other gate already in
  `maybe_route_to_conversation()`. Telegram usernames are never used as an identity signal.
  Every human who may reply as an operator must be mapped via the `MANAGE`-gated
  `OperatorIdentityPage` before their first reply — an onboarding requirement, not an automatic
  inference from any webhook payload.
- **SENSITIVE classification**: `telegram_sender_user_id` and `telegram_username` are both
  classified SENSITIVE (not INTERNAL) end-to-end. The raw sender id exists only as a protected
  join key on operator-direction message rows — never rendered, never placed in an admin URL/GET
  parameter, never a search filter, never written into a raw audit context. `telegram_username` is
  displayed only on the `OperatorIdentityPage` itself. The rejected-sender audit entry carries only
  a fixed rejection code plus `bot_id`/`conversation_id`.
- **Concurrency safety**: `ConversationRepository::assign_with_expected()` performs a genuine
  compare-and-set update (matching the caller's displayed current assignment, including the
  unassigned/`null` case); a stale request matches zero rows, makes no change, and is not audited.
  Status transitions continue to use the existing CAS `transition()` guard.
- **Availability/unread**: three-state presence (available/busy/offline); self-set requires
  `MANAGE_CONVERSATIONS`, setting another mapped operator's state requires the broader `MANAGE`.
  Assigning a busy/offline operator is blocked unless a `MANAGE` holder explicitly confirms an
  override, audited with a distinct action code. Unread state is always derived from
  `assignee_last_seen_message_id`, never separately stored, and reset to `NULL` on reassignment. No
  push, email, WebSocket, polling daemon, or Telegram-side notification was introduced.
- **Manual deletion**: `MANAGE_CONVERSATIONS`-gated, nonce-protected, restricted to `archived`
  status only, audited before execution, routed through the same shared `ConversationPurgeService`
  scheduled retention uses — never calls the Telegram Bot API.
- **Operator account deletion** (`deleted_user`, additive to the existing, unchanged M06.3.1
  `release_owner_conversations()` visitor-owner cleanup): resolves the deleted operator's mapped
  Telegram id before deleting the mapping row that holds it, clears `telegram_sender_user_id` on
  their prior message rows (content untouched), anonymizes their internal-note authorship (content
  untouched, rendered "— former operator —"), unassigns their conversations and resets
  `assignee_last_seen_message_id`, deletes their availability/identity rows, and records exactly
  one system audit entry with an **empty** context — no `wp_user_id`, Telegram id, or username.

## Validation evidence

**Local lean gate (final, after all repair rounds):**
- PHPCS (all changed files, source and tests): clean
- PHPStan: `[OK] No errors`
- PHPUnit unit suite: 198/198 pass
- PHPUnit integration suite (full, unfiltered, WordPress 6.9/PHP 8.1, fresh database matching CI):
  622/622 pass, 39 skipped (WooCommerce-gated, expected)
- Package acceptance (WordPress 6.9/PHP 8.1): PASSED — `db_version` 18 confirmed, all three M07
  tables and both new columns confirmed present on activation, eleven Hub tabs confirmed in order,
  both default-retention and opt-in uninstall confirmed correctly keeping/removing the three new
  M07 tables.

**GitHub Actions (PR #21, final commit `48f43ac`, all 14 required checks):** build, phpcs,
static-analysis, unit (8.1/8.3/8.4), integration-wp-only-floor, integration-wp-only-current,
integration-wc-present-current, js-behavioural, package-acceptance (6.9/8.1, 7.1/8.3,
7.1/8.3/WooCommerce 11.0.1) — all **pass**.

## Deviations

1. Two Phase C repair rounds addressed pre-existing test-infrastructure staleness that predates
   M07 (six `Migrator*SchemaTest` files frozen at the prior `target_version`; `UninstallTest`'s
   hand-duplicated recreate-tables helper frozen at M05's own `db_version`), surfaced only because
   M07 is the first milestone since M05 to raise `target_version` and the first to add a new
   `CREATE TABLE` migration step, respectively. Both are direct, minimal fixes to actual defects —
   never a scope change — and the second (round 3) additionally makes the fixed helper
   self-maintaining against any future milestone's schema additions, rather than merely patching
   today's symptom.
2. One repair round (round 2) closed a real gap M07 itself introduced: `Uninstaller` did not drop
   the three new M07 tables on opt-in full removal.
3. No other deviations from the frozen plan (`docs/plans/m07-operator-workflow-plan-v1.md`) or
   ADR-0026 occurred. All ten work packages were implemented in the planned order and scope.

## Product Owner acceptance

**PENDING.** Manual Telegram/topic operator-workflow acceptance testing (per the manual Product
Owner acceptance checklist in the frozen plan, §12) has not yet been performed and is explicitly
outside this task's own authorized scope, which excluded all live Telegram/bot/webhook actions.

## Confirmations

- No live Telegram calls, bot configuration, destination changes, or webhook changes occurred at
  any point.
- No release, tag, or deployment occurred.
- No configuration change outside this milestone's own repository content occurred.
- M08 has not started — no file, test, or documentation for it was added or modified.
- `main == origin/main` at `c0b45b0ac376f327857a0cbb8506326b1c214f73`, verified via
  `git fetch && git rev-parse HEAD origin/main`.
- The tree is clean.
