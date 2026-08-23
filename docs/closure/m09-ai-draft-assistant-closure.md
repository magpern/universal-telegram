# M09 — AI Draft Assistant — Closure Record

## Status

**PASS** (automated/technical verification). Product Owner acceptance is **PENDING** — a manual
walkthrough with an explicitly configured dev-only OpenAI credential (per the frozen plan's
acceptance checklist) has not yet been performed and was outside this task's authorized scope,
which excluded all live provider/Telegram/bot/webhook actions, configuration, releases, tags, and
deployments.

## Baseline, freeze, PR, merge, and closure SHAs

- Baseline (prior `main`): `a5bfa2a0f994d43562fa3d04fc955f10087b69aa` (M08 technical closure)
- Feature branch: `feature/m09-ai-draft-assistant`
- Freeze commit (plan doc + ADR-0028): `f11eaf155e6c06365430255aea08dc8476128b7d` — `docs: freeze M09 AI draft assistant plan and ADR-0028`
- PR: [#23](https://github.com/magpern/universal-telegram/pull/23)
- Merge commit: `396b1d8d44357b4e2a4405e0bc195c0b157facc1` (into `main`)
- Closure commit: pushed directly to `main` immediately following this document

## Work-package and repair commits

| WP | Commit | Summary |
|----|--------|---------|
| WP1 | `5936ae1` | `Migrator` steps 19–21 (`ai_config`, `ai_drafts` tables; `conversations.ai_ack_policy_version`); `AIProviderConfig`/`AIProviderRepository` (fail-closed enablement, in-flight-cancellation on disable/delete); `AISettingsPage`; Hub "AI" tab |
| WP2 | `cd18fd8` | `ApprovedContentRepository` (approval via post meta + revision marker, in-PHP keyword-overlap ranking derived only from the conversation's own last visitor message); `ApprovedSource`; `ApprovedContentPage`; Hub "AI Content" tab; `MessageRepository::latest_visitor_message()` |
| WP3 | `dca8b9f` | `AiDraft`/`AiDraftRepository` (persistence/decrypt/review-status transitions); conversation-creation `ai_ack` field end-to-end (`ConversationRepository`, `ConversationsController`, `Conversation::is_ai_draft_eligible()`); widget acknowledgement checkbox (`chat-widget.js`, `ChatWidgetAssets` config island) |
| WP4 | `1e16172` | `AiProviderInterface`/`AiRequest`/`AiResult`; `OpenAiAdapter` (`pre_http_request`-interceptable); `AiFailureClassifier`/`AiFailureClassification` |
| WP5 | `ad83479` | `PromptBuilder` (fixed system/user split, `<source>`/`<conversation>` delimiters with angle-bracket escaping, bounded context/output); also fixed `ApprovedContentRepository::top_matches()` to score via in-PHP keyword overlap instead of `WP_Query`'s `'s'` param, whose default AND-all-terms matching failed most realistic multi-word visitor messages |
| WP6 | `6856e52` | `AIDraftGenerationHandler` (circuit-open/concurrency-cap/no-source/success/token-invalid/terminal/retryable decision tree); `AiDraftLeaseSweep` (durable recovery trigger); `AiDraftRepository` claim/lease/complete/fail/release/sweep methods (race-safe locking design) |
| WP7 | `bb4be4b` | `DraftRequestHandler`; `AiDraftRepository::request_draft()` (conversation-row-locked one-active-draft/cooldown/retained transactional check) |
| WP8 | `1b28232` | `ConversationDraftPanel` (composed into `ConversationDetailPage`); `AIDiagnosticsPanel` (composed into `DiagnosticsPage`); `AiDraftRepositoryAccessAllowListTest` (structural six-class allow-list enforcement); `StructuralBoundariesTest` updated (AI now a permitted boundary) |
| WP9 | `7080915` | Retention (30-day body-nulling, 90-day purge) and account-deletion anonymization extended to AI drafts via direct table access (preserving the allow-list); migration step 22 (`ai_drafts.requested_by_user_id` widened to nullable); version bump `0.10.0` → `0.11.0`; `readme.txt` changelog |

**Repair commits (Phase C validation-gate defects):**

| Commit | Defect class |
|--------|--------------|
| `91f8647` | PHPCS (doc-comment capitalization/short-description, missing `@param`/`@return`, `WordPress.DB.PreparedSQL` sniff-code corrections, `WP_Query` pagination-limit warning, disallowed short ternary) and PHPStan (two always-true narrowing findings) across the WP1–WP9 files; corrected every new AI test file's use of WordPress's `tear_down()` instead of this codebase's actual PHPUnit `tearDown()` convention (silently never running under a real WP bootstrap); added explicit `setUp()`-time resets of the singleton `ai_config` row, the `ai_drafts` table, and Action Scheduler's own tables to every AI test file (cross-test isolation, matching this suite's existing documented pattern for the same root cause); `AiDraftLeaseSweep`'s own permanent recurring baseline action required explicit cancellation in two pre-existing tests (`SchemaDegradedExecutionTest`, `BotCommandDispatcherFamilyBCTest`) whose queue-baseline assumptions predated it; fixed `ApprovedContentRepositoryTest`'s same-second approve-then-edit race via a direct `wp_posts` write (`wp_update_post()` always recomputes `post_modified_gmt` to "now" regardless of any explicitly-passed value); found and fixed a real gap — `Uninstaller.php` never dropped the two new AI tables on opt-in full data removal; updated `tests/package/run.sh` for `db_version` 22, thirteen Hub tabs, and new M09 schema/uninstall verification |
| `81f3e13` | Same cross-test-isolation root cause, surfaced only under GitHub Actions' `pull_request`-triggered matrix ordering (not the local/push ordering): `ConversationInboxPageTest` (pre-existing, untouched by M09 otherwise) assumed its own fixture conversation would appear in the inbox's default result set — an assumption a Migrator test's DDL-forced-commit could defeat by leaving unrelated rows uncleaned. Added the identical explicit-reset pattern to that test's own `setUp()` |

## ADR-0028

`docs/adr/0028-ai-draft-assistant-acknowledgement-gate-source-only-grounding-and-lifecycle-concurrency-control.md`
— Accepted. Explicit, unchecked-by-default visitor acknowledgement gate (never backfilled/re-prompted);
unconditional source-only grounding with no general-knowledge fallback; a provider-neutral abstraction
with exactly one shipped adapter (OpenAI), disabled by default until credential+model+enablement are
all set; race-safe lifecycle/concurrency control (conversation-row lock for one-active-draft/idempotency,
singleton-config-row lock for the site-wide 2-generation cap, a 90-second compare-and-set lease, and a
bounded/idempotent recurring sweep as the sole durable crash-recovery trigger); a fixed six-class
structural allow-list on `AiDraftRepository`, enforced by a static test, not convention alone.

## Version/database transition

- Plugin version: `0.10.0` → `0.11.0` (minor bump — a genuine new functional-capability class).
- Database: `db_version` `18` → `22` — three additive steps for the AI config/drafts tables and the
  conversation acknowledgement column (as frozen), plus one additional step (22) discovered necessary
  during WP9 implementation to widen `ai_drafts.requested_by_user_id` to nullable, required by the
  frozen plan's own account-deletion-anonymization requirement but omitted from the original WP1
  schema definition (see Deviations).

## Operator-only guarantee and structural evidence

`AI\Draft\AiDraftRepository` is referenced only by six classes: `AI\Draft\{AiDraftRepository,
DraftRequestHandler, AIDraftGenerationHandler, AiDraftLeaseSweep}` and
`Administration\AI\{ConversationDraftPanel, AIDiagnosticsPanel}` — enforced by
`AiDraftRepositoryAccessAllowListTest`, which statically scans every `.php` file under `src/` for a
reference (import, constructor type-hint, or instantiation) and asserts the referencing-file set is
exactly this allow-list, with explicit negative checks against `Conversations\Rest`, `ChatWidget`,
`Telegram\Outbound`, and `Telegram\Inbound`. `Core\Plugin.php` (the composition root, which
necessarily wires every service in the plugin) is the one documented exemption. Approving a draft
(`AiDraftRepository::mark_approved()`) only changes a status column — no code path connects a draft
to any visitor-facing REST route, webhook handler, or Telegram outbound class.

## Acknowledgement/privacy and source-only boundaries

- The chat widget shows an unchecked, optional checkbox before the first message, only while AI is
  enabled (`ChatWidgetAssets`'s public, cache-safe JSON-island config — text/version only, never a
  credential or model identifier).
- Only `ai_ack === true` on the exact conversation-creation request sets
  `conversations.ai_ack_policy_version` to the current disclosure version — identically for
  anonymous and logged-in chat, no new tracking identifier. This is the sole write path; declined,
  omitted, malformed, pre-enablement, and stale-version conversations are permanently ineligible.
- Retrieval is restricted to explicitly administrator-approved, published, non-password-protected
  posts/pages, re-validated against a captured `post_modified_gmt` marker at retrieval time — an
  edit since approval excludes a source until re-approved. The retrieval query is derived only from
  the conversation's own last visitor message (`ApprovedContentRepository::top_matches()` takes no
  free-text parameter from any caller). Zero matches is the fixed `no_matching_source` terminal
  outcome, with no provider call ever made.
- This is a technical enforcement mechanism only, not legal advice on consent or regulatory
  compliance.

## Provider reliability, lease recovery, and the honest at-least-once limitation

- `AIDraftGenerationHandler` mirrors `Telegram\Outbound\SendMessageHandler`'s exact reliability
  structure: circuit-open and concurrency-cap deferrals never throw and never consume
  `Queue\RetryPolicy`'s attempt budget; `TOKEN_INVALID`/`TERMINAL` failures dead-letter immediately;
  `RETRYABLE` failures release the lease and rethrow for `WorkerRunner`'s own bounded retry sequence,
  except at the shared attempt budget, where this handler also dead-letters directly.
- The site-wide 2-generation cap and per-draft 90-second lease use two explicit-transaction row locks
  (the migration-seeded singleton `ai_config` row for the cap; the owning conversation row for
  one-active-draft/idempotency) — the only two places in the `AI` boundary using an explicit
  transaction rather than a single atomic `UPDATE ... WHERE`, because "at most N rows in a state" and
  "at most one row per conversation" cannot be expressed as a single-row compare-and-set.
- `AiDraftLeaseSweep` (fixed job type `ai_draft_lease_sweep`, idempotently registered, 60-second
  cadence) is the sole durable trigger that notices an expired lease and re-dispatches — re-enqueuing
  below the shared 5-attempt budget, or dead-lettering (`crashed_exhausted`) at/above it. Bound on
  total staleness: ≈150 seconds worst case (90s lease + 60s sweep interval).
- **Honest guarantee, stated precisely, not overclaimed**: eligible work is retried/recovered within
  this bounded policy until a defined terminal state. Circuit-open, `no_matching_source`,
  cancellation, and terminal pre-call classification correctly and by design produce **zero**
  provider calls. Only once a call has actually begun is invocation **at-least-once, not
  exactly-once** — a rare crash between provider acceptance and the database write can duplicate a
  call, an explicitly accepted, bounded cost, since no draft is ever auto-sent regardless of how many
  times it was generated.

## Focused local validation and CI evidence

**Local focused gate (final, after both repair commits):**
- PHPCS (all changed files, source and tests): clean
- PHPStan (all changed source files): `[OK] No errors`
- PHPUnit unit suite: 233/233 pass
- PHPUnit integration suite, full run (not scoped — the cross-test-isolation defects required full-run
  visibility to surface and verify): WordPress-only (6.9): 752/752 pass, 50 skipped (WooCommerce-gated);
  WordPress+WooCommerce (7.1/PHP 8.3/WooCommerce 11.0.1): 752/752 pass, 3 skipped
- JS behavioural suite: 57/57 pass
- Package acceptance (WordPress 7.1/PHP 8.3/WooCommerce 11.0.1): PASSED — `db_version` 22 confirmed,
  `ai_config` singleton seeded and disabled by default, `ai_ack_policy_version` and lease/claim columns
  present, `requested_by_user_id` confirmed nullable, thirteen Hub tabs in order, default-retention and
  opt-in-uninstall table lifecycle correct including both new AI tables

**GitHub Actions (PR #23, final commit `81f3e13`, all 13 checks × 2 triggers):** build, phpcs,
static-analysis, unit (8.1/8.3/8.4), integration-wp-only-floor, integration-wp-only-current,
integration-wc-present-current, js-behavioural, package-acceptance (6.9/8.1, 7.1/8.3,
7.1/8.3/WooCommerce 11.0.1) — all **pass**. One transient failure (`integration-wp-only-current`,
`integration-wc-present-current` on the `pull_request`-triggered run only, `ConversationInboxPageTest`)
was diagnosed and fixed by `81f3e13`, then reverified green on both triggers.

**Post-merge CI (merge commit `396b1d8`, all checks):** all **pass**.

## Deviations

1. **Merge strategy**: the PR was merged with `--squash` instead of this repository's established
   merge-commit convention (every prior milestone's closure record — e.g. M07, M08 — shows a "Merge
   pull request #NN" merge commit with two parents). This collapsed the twelve individually-authored
   WP/repair commits into a single commit (`396b1d8`) on `main`'s linear history. The full, correctly
   attributed commit history remains intact and inspectable on the `feature/m09-ai-draft-assistant`
   branch and in PR #23 itself; no code content, test, or commit message text was lost — only the
   per-commit granularity on `main`'s own `git log`. This was an execution error, not a deliberate
   scope or process change; flagged here rather than silently corrected via a `main` history rewrite,
   which was not authorized and would itself be a materially riskier action.
2. **Schema**: one additional migration step (22) beyond the three specified in the frozen plan's §4
   persistence design, discovered necessary during WP9 implementation — `ai_drafts.requested_by_user_id`
   was specified `NOT NULL` in the original WP1 schema (step 20), but the frozen plan's own §4
   retention table requires it be anonymizable (nulled) on operator account deletion. Resolved via an
   additive `ALTER TABLE ... MODIFY COLUMN ... NULL` (no destructive change, no data loss), and the
   plugin/db_version, package-acceptance script, and this closure record all updated accordingly. This
   is an implementation-detail correction of an internal inconsistency in the frozen plan's own
   schema definition, not a scope or decision change.
3. **Pre-existing infrastructure discovery**: `universal_telegram_conversations.consent_state` (values
   `unknown|granted|declined`) and `.ai_participation_state`, added at M05 and explicitly reserved in
   that milestone's own doc comments for "M9+" use, were found during WP3 implementation but
   deliberately left untouched — the frozen plan (ADR-0028) specifies a separate, additive
   `ai_ack_policy_version` column as the sole acknowledgement mechanism, and repurposing or dual-writing
   the pre-existing reserved fields would have been an unplanned, un-reviewed design change beyond this
   task's authorization to substitute or redesign the approved plan. Flagged for the Master
   Architect/Product Owner's awareness in case a future milestone wants to reconcile the two.

No other deviations. All nine work packages were implemented in the planned order and scope; ADR-0028
was recorded exactly as approved.

## Product Owner acceptance

**PENDING.** The frozen plan's manual acceptance checklist (enable AI with a placeholder key and
confirm masking; verify unacknowledged/pre-enablement conversations are refused a draft; verify
approve-then-edit excludes a source until re-approved; verify `no_matching_source` with no HTTP call;
verify the "NOT SENT" banner and traceability with HTTP stubbed; verify duplicate-click idempotency;
verify the 2-generation concurrency cap; verify circuit-breaker/lease-sweep recovery; verify no
widget/webhook code path can display or send a draft) requires a separately authorized session with
an explicitly configured dev-only OpenAI credential, which was out of this task's scope.

## Confirmations

- No live OpenAI/provider credential was configured or called at any point; every provider-facing
  test uses WordPress's `pre_http_request` interception only.
- No live Telegram call, bot configuration, destination change, or webhook change occurred.
- No release, tag, or deployment occurred.
- No configuration change outside this milestone's own repository content occurred.
- M10 (and M11, M12) have not started — no file, test, or documentation for any of them was added or
  modified.
- `main == origin/main` at `396b1d8d44357b4e2a4405e0bc195c0b157facc1`, verified via
  `git fetch && git rev-parse HEAD origin/main`.
- The tree is clean.
