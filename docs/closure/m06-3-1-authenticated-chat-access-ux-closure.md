# M06.3.1 — Authenticated Chat Access and UX Redesign — Closure Record

## Status

**PASS.** Product Owner acceptance is **CONFIRMED** (2026-08-23).

## Baseline, freeze, PR, merge, and closure SHAs

- Baseline (M06.3 closure, prior `main`): `f4912296c580d9a84bfcdbe160c54bc20ea7ecdb`
- Feature branch: `feature/m06-3-1-authenticated-chat-access-ux`
- Plan doc + ADR-0025 committed as part of WP7 (see deviation note below): `6eedf01`
- Addendum plan doc (configurable anonymous chat): `5b9778244aeaab4e53f6d4c6536dff1db6a3ff06`
- PR: [#19](https://github.com/magpern/universal-telegram/pull/19)
- Merge commit: `58950b3da9074ad5446fb81fd356566f7f5ffa6b` (into `main`, merge strategy: merge commit)
- Closure commit: pushed directly to `main` immediately following this document

## Work-package and repair commits

**Original authenticated-only scope (WP1–WP7):**

| WP | Commit | Summary |
|----|--------|---------|
| WP1 | `69902d8` | Ownership schema/migration/repository — `owner_user_id`, generated unique-indexed `owner_active_slot`, `create_or_resume_owned()`, `find_active_for_owner()`, `rotate_secret()`, `release_owner_conversations()`, `new`-inclusive inactivity sweep |
| WP2 | `10be18b` | Authenticated REST/nonce/ownership boundary — `authenticate_session()`, `GET /conversations/mine`, server-derived display name, removal of `display_name_required`/`display_name` contract |
| WP3 | `ba5972e` | Widget state machine — signed-out state, `AccountUrlResolver`, authenticated resume, invisible start-then-message, `theme` preset, scroll-position-aware "New messages" |
| WP4 | `ba22423` | Account-deletion lifecycle wiring (`deleted_user` hook → `release_owner_conversations()`) |
| WP5 | *(delivered inside WP3, `ba5972e`)* | Theme-default preset + scrolling — one cohesive client-side change, no separate commit |
| WP6 | `217c316` | Destination-list hygiene regression coverage |
| WP7 | `6eedf01` | ADR-0025, plan doc, version/db bump (0.7.0→0.8.0, db 15→16), manual checklist |

**Phase C repair rounds (original scope):**

| Round | Commit | Defect class |
|-------|--------|--------------|
| 1 | `dc01192` | PHPCS/PHPStan findings (phpcbf auto-fixes + 2 real PHPStan findings) |
| 2 | `2059c81` | Integration test defects (cache-safety test page-identity assumption, empty-display-name WP default, migration-test connection staleness) |
| 3 | `dc0d7f8` | Full-suite-only defect (`PluginAccountDeletionTest` stale ambient `db_version`) |
| 4 | `cb1978d` | WooCommerce-present CI matrix defects (`deleted_user` hook argument-count mismatch with WooCommerce's own hooked callback; `AccountUrlResolverTest` WC-presence assumption) |

**WP8 — configurable anonymous chat addendum (authorized mid-implementation, additive):**

| Item | Commit | Summary |
|------|--------|---------|
| Addendum plan | `5b97782` | `docs: add anonymous chat policy amendment for M06.3.1` |
| WP8 implementation | `065639f` | `chat_widget_allow_anonymous` setting; `ConversationsController` auth-branch restructuring (`is_user_logged_in()`-driven, per-conversation `authorize_conversation_access()`); anonymous bearer-secret path reusing the pre-M06.3.1 protocol unchanged; fixed non-PII `"Visitor"` identity; `GET /conversations/mine` unconditionally authenticated-only in every configuration; widget/config `anonymousChatAllowed` fork |
| WP8 repair | `fd4e028` | PHPCS findings (phpcbf array-alignment auto-fixes, one section-comment/docblock-sniff false-positive) |

## Version/database transition

- Plugin version: `0.7.0` → `0.8.0` (minor bump — mandatory authentication, persistent ownership,
  database-enforced concurrency, and explicit CSRF together constitute a genuine new capability
  class). The WP8 addendum introduced no further version bump (folded into the same 0.8.0 release
  scope since it landed before merge).
- Database: `Migrator` step 16, `db_version` `15` → `16` — adds `owner_user_id BIGINT UNSIGNED NULL`
  and the generated, unique-indexed `owner_active_slot` column to
  `universal_telegram_conversations`. The WP8 addendum introduced **no further schema change** —
  `chat_widget_allow_anonymous` is a plain `Settings` boolean reusing existing
  `owner_user_id IS NULL` semantics.

## Security, ownership, and legacy-compatibility decisions

- Every conversation REST route requires an explicit, self-verified check as the first meaningful
  step of its own handler — never relied upon implicitly via core wiring.
- `messages`/`poll` resolve authorization from the specific conversation's own `owner_user_id`: an
  owned conversation requires cookie + nonce + owner match; an anonymous conversation (only reachable
  when `chat_widget_allow_anonymous` is enabled) requires only its bearer secret, matching the
  pre-M06.3.1 model exactly. `GET /conversations/mine` remains authenticated-only unconditionally,
  in every configuration — no anonymous code path or test leg exists for it.
- A database-enforced unique index (`owner_active_slot`, covering `new`/`open`/
  `waiting_for_visitor`/`waiting_for_operator`) guarantees exactly one active conversation per
  `(owner_user_id, bot_id)`; a duplicate-key collision resumes and rotates rather than retrying or
  duplicating.
- Every M05–M06.3 (ownerless) conversation remains in the database under existing retention but is
  permanently unreachable through the authenticated widget/API — never backfilled or auto-claimed.
  Anonymous conversations created under the WP8 addendum are architecturally identical ownerless
  rows and are likewise never merged or claimed on a later login.
- The numeric WordPress user id, username, and email are never serialized to any REST response,
  log, or Telegram-bound content. An authenticated conversation's identity is the WordPress display
  name (fixed generic fallback `"Member"` when empty); an anonymous conversation's identity is the
  fixed literal `"Visitor"` — no PII in either case.
- Account deletion (`deleted_user`) revokes the bearer secret and clears `owner_user_id` for every
  conversation the account owned, converting those rows into the same unreachable, ownerless shape.

## Validation evidence

**Local lean gate (final, after WP8):**
- PHPCS (changed files): clean
- PHPStan: `[OK] No errors`
- PHPUnit unit suite: 197/197 pass
- PHPUnit integration suite (full, unfiltered, WP 7.1/PHP 8.3): 544/544 pass, 39 skipped
  (WooCommerce-gated, expected)
- JS behavioural suite (Node test runner): 55/55 pass
- Package acceptance (WP 7.1/PHP 8.3): PASSED — `db_version` 16 confirmed, `owner_user_id`/
  `owner_active_slot` columns confirmed present on activation

**GitHub Actions (PR #19, both push and pull_request triggers, all 26 checks):** build, phpcs,
static-analysis, unit (8.1/8.3/8.4), integration-wp-only-floor, integration-wp-only-current,
integration-wc-present-current, js-behavioural, package-acceptance (6.9/8.1, 7.1/8.3,
7.1/8.3/WooCommerce 11.0.1) — all **pass**.

## Deviations

1. The Phase A "freeze" documents (plan doc + ADR-0025) were committed as part of WP7, after
   WP1–WP6 implementation, rather than as a standalone commit preceding implementation — recorded
   at the time in the WP7 commit message. Implementation began directly from the already-corrected
   plan-mode notes rather than a separately-frozen repository document.
2. The WP8 anonymous-chat addendum was authorized and implemented mid-flow, after WP1–WP7 were
   already committed and PR #19 was open with CI green. It is additive only (default off, no
   schema change) and was implemented as its own addendum plan doc + work package + repair commit,
   per explicit authorization, rather than folded retroactively into the original WP1–WP7 history.
3. Four Phase C repair rounds were needed (two for the original scope beyond the standard PHPCS/
   PHPStan pass, one full-suite-only defect, one WooCommerce-matrix-only defect), plus one repair
   round for WP8 — each is a direct, minimal fix to an actual defect (test-authoring assumptions,
   PHPUnit-transaction/DDL interaction artifacts, a hook argument-count mismatch with WooCommerce's
   own registered callback), never a scope change.
4. **Unrelated concurrent work found and preserved, not part of this closure**: during the merge,
   uncommitted local modifications to `universal-telegram.php` (version bump to `0.8.1`) and
   `universal-telegram-functions.php` (a new `universal_telegram_chat_is_enabled()` helper) were
   found in the working tree — not made by this task. These were stashed before switching branches
   and restored onto `main` after the merge so no other session's in-progress work was lost; they
   remain uncommitted and were not touched, authored, or evaluated by this closure.

## Product Owner acceptance

**CONFIRMED (2026-08-23).** Accepted following live verification on dev.biopentra.eu, including a
post-merge round trip on a live-reported defect (the sign-in prompt rendering alongside an already-
authenticated conversation, and the personalized greeting) fixed via PR #20 (merge commit `0a5eae8`)
on top of this milestone's `58950b3` merge.

## Confirmations

- No live Telegram calls, bot configuration, destination changes, or webhook changes occurred at
  any point.
- No release, tag, or deployment occurred.
- M06.4, M06.5, and M07 remain unstarted — no file, test, or documentation for any of them was
  added or modified.
- `main == origin/main` at `58950b3da9074ad5446fb81fd356566f7f5ffa6b`, verified via
  `git fetch && git rev-parse HEAD origin/main`.
- The tree is clean with respect to this closure's own scope (the two unrelated files noted above
  remain uncommitted, belonging to another session, not this task).
