# M08.2 — Friendly Notification Tester & Grouped Hub Navigation — Closure Record

## Status

**PASS.** Product Owner acceptance: **approved** (menu/navigation rearrangement approved;
M08.2 as a whole approved).

## Baseline, freeze, and commit SHAs

- Baseline (prior `main`): `31c5480e416f2f3786663d128c0839ab8625a27e` (M08.1 merged, PR #28)
- Feature branch: `feature/m08-2-friendly-notification-tester`
- Freeze commit (base plan): `036479d` — `docs: freeze M08.2 friendly notification tester plan`
- Freeze commit (navigation addendum): `dcfef34` — `docs(m08-2): freeze grouped Hub navigation addendum`
- Merge: squash/merge to `main` immediately following this document (see merge commit in `git log`)

## Work-package and repair commits

| WP | Commit | Summary |
|----|--------|---------|
| WP1 | `24365cd` | `RuleEvaluator::evaluate_conditions()`: non-short-circuiting structured trace (`RuleMatchTrace`/`ConditionClauseResult`); production's own `rejection_reason()` rewritten to derive from the same trace |
| WP2 | `c8c0661` | `EventFamilyCatalog` extracted from `RuleBuilderPage`'s own private `EVENT_FAMILIES` const (pure relocation) |
| WP3 | `164d9f4` | `FailingConditionExplainer`: plain-language failing-condition sentences from an already-computed `RuleMatchTrace` |
| WP4 | `2581f30` | `NotificationTester` (`Administration\Automations`), `NotificationTestResult`/`NotificationTestOutcome`, full scenario coverage (plan §8), structural allow-list test proving no dependency on any dispatcher, dispatch-log/event-history/audit-log repository, Telegram/HTTP client, or `Queue\*` class |
| WP5 | `918c3de` | `NotificationTesterPage` replacing `RuleSimulatorPage` (`RuleSimulator`, `RuleSimulatorPage`, `SimulationResult` deleted); `Plugin`, `OverviewPage`, `LegacyUrlRedirector` rewired; package-acceptance script updated |
| WP6 | `9bbe61c` | `HubPage::LEGACY_TAB_ALIASES` (`simulator` → `NotificationTesterPage::TAB_ID`); accessibility test coverage |
| Addendum | `b93bc02` | Grouped Hub navigation: `Hub\AreaPage` (new), `Tab::is_accessible()`/`has_accessibility_override()` opt-in closure, `HubPage::LEGACY_TAB_ALIASES` extended to every relocated screen, `Plugin` registrations regrouped into seven top-level areas |
| Bug fix | `6526392` | The Example values form's own POST `action` URL did not repeat `mode`/`rule_id`/`event_type` as query params, so `$_GET` was empty on the POST request and the page silently fell back to "nothing selected" — the button appeared to just refresh the page. Fixed by mirroring the hidden fields into the action URL's query string |
| UX | `309111a` | Moved the Result region beside the Example values form (two-column flex layout once a result exists; unchanged single column beforehand and on narrow viewports), per Product Owner screenshot feedback |
| **Repair** | `398ab18` | Combined validation gate (WP7): see below |

## What M08.2 is

**"Test notifications"** (Hub → Notifications & activity → Test notifications) replaces the
developer-oriented Simulator with two modes:

1. **Test an existing notification** — pick a real notification, see its friendly "About this
   notification" summary (event, destination, plain-language conditions, rendered example
   preview), fill in the same example-value fields the rule builder itself uses, and submit to
   see a plain-language would-send / would-not-send result beside the form.
2. **Test a custom scenario** — pick a friendly event, fill in example values, and see every
   enabled rule for that event evaluated against them, each with its own outcome.

Every example value and the test action itself travel only via nonce-protected POST — never
persisted, cached, logged, or exposed via GET. `NotificationTester` is structurally proven (a
reflection-based allow-list test) to never construct `NotificationDispatcher`,
`DispatchLogRepository`, `MessageDispatcher`, `TelegramApiClient`, `EventHistoryRepository`,
`AuditLogger`/`AuditLogRepository`, or any `Queue\*` class — no Telegram message is ever sent and
no dispatch-log/event-history/audit-log row is ever written by a test, regardless of outcome.

**Grouped Hub navigation** (addendum): the Hub's flat 13-tab top-level row is reduced to seven
grouped areas — Overview, Bots, Notifications & activity, Conversations, AI, Settings,
Diagnostics — each listing its own existing screens as an accessible secondary tab row. This is a
presentation/navigation change only: no screen's own capability check, persistence, or behavior
changed. Every old direct tab URL, including a bookmarked `?tab=simulator`, still resolves to its
own content via `HubPage::LEGACY_TAB_ALIASES`; an invalid or currently-inaccessible child section
falls back to the first accessible one; a parent area is listed in the nav only when at least one
of its children is accessible to the current viewer.

## Combined validation gate (WP7) — what the deferred run found

The frozen plan deferred PHPCS/PHPStan/PHPUnit execution to closure. Running it for the first time
(commit `398ab18`) surfaced, alongside routine style violations, four real defects, all now fixed:

1. **POST bug** (already fixed pre-gate, commit `6526392`): described above.
2. **Absent-field evaluation bug**: `NotificationTester::build_envelope()` defaulted every
   eligible field to `FieldTypeCatalog::preview_value()` even when the administrator left it
   unset, so a field genuinely absent from the example values could never actually be evaluated
   as absent — contradicting the frozen plan's own §8 scenario 5 ("a clause's field absent from
   sample values → `NOT_MATCHED` with the absent-field sentence, distinct from a non-matching
   value"). Fixed: an unset field now stays genuinely absent from the synthetic envelope.
3. **Test-harness nonce bug**: several nonce-verified-POST tests set `$_POST['_wpnonce']` but not
   `$_REQUEST['_wpnonce']`; PHP does not resync `$_REQUEST` when a test mutates `$_POST` after
   bootstrap, so `check_admin_referer()` always saw a missing nonce. Fixed by setting
   `$_REQUEST['_wpnonce']` alongside `$_POST['_wpnonce']`, matching this suite's own existing
   convention elsewhere (`RuleBuilderRequestHandlerTest`, `VisitorTrackingPageTest`, etc.).
4. **Test-harness capability-denial bug**: two tests tried to deny a capability via
   `get_userdata($id)->remove_cap(...)` / a stale current-user object, on a capability that is
   only ever granted at the **role** level (`CapabilityRegistrar` grants to the `administrator`
   role, not per-user) — `WP_User::remove_cap()` cannot override a role-inherited capability, and
   even removing it from the role left the already-instantiated current-user object's cached
   `allcaps` stale (`wp_set_current_user()` is a no-op for the same user id). Fixed by removing
   the capability from the role and calling `wp_get_current_user()->get_role_caps()` to recompute
   in place.

Also fixed as part of the same repair commit: a tab-id collision between `HubPageTest`'s own
generic fixture (`'events'`) and the real `HubPage::LEGACY_TAB_ALIASES['events']` entry added by
the navigation addendum (fixture renamed to `'reports'`); `phpstan.neon.dist` still ignoring an
error path in the now-deleted `RuleSimulator.php` (PHPStan refused to run at all); a stale
`db_version=29` assertion in `tests/package/run.sh` (a later, already-merged milestone, M08.1/
ADR-0032, had already advanced it to 30); and two tests' literal `'wordpress.*'` event-type
identifier strings that `phpcbf`'s spelling sniff had auto-corrected to `'WordPress.*'`, silently
breaking them (reverted with a targeted `phpcs:ignore`).

## Lean local validation evidence (final, after repair commit `398ab18`)

- **PHPCS** (`src/`, `tests/`): clean for every file this branch touches. Four files show
  pre-existing errors (`ConversationPurgeService.php`, `EventCatalogPage.php`,
  `OutboundMessageRepository.php`, `SendMessageHandler.php`) — confirmed byte-identical to `main`
  via `git diff --stat main`, out of this milestone's scope.
- **PHPStan** (level 5, full `src/`): `[OK] No errors`.
- **PHPUnit unit suite**: 312/312 pass (1 skipped, pre-existing/unrelated).
- **PHPUnit integration suite**, run three ways — WordPress-only 6.9/PHP 8.1, WordPress-only
  7.1/PHP 8.3, WooCommerce-present 7.1/WooCommerce 11.0.1/PHP 8.3 — all three: 999/999 tests pass
  except 2 pre-existing errors (`SendMessageHandlerTest`, `CircuitBreakerTest`), confirmed
  byte-identical to `main`, unrelated to this milestone.
- **Package acceptance** (WordPress 7.1/PHP 8.3/WooCommerce 11.0.1): **PASSED** — `db_version` 30
  confirmed, activation/deactivation/default-retention/opt-in-uninstall all correct, no new
  tables, the notification tester writes no dispatch-log row, the Hub registers exactly the seven
  expected top-level areas, and the legacy `tab=bots`-style redirect still works.

No CI run occurred as part of this task (this repository has no configured CI trigger observed in
this session); local Docker-based validation is the full gate for this closure.

## Version/database transition

- Plugin version: `0.15.0` → `0.16.0` (minor bump — a genuine new functional-capability class: the
  friendly notification tester plus the grouped Hub navigation).
- Database: unchanged. `db_version` stays `30` (M08.1/ADR-0032's own last migration step). M08.2
  adds no migration.

## Deviations

None from the frozen base plan (`docs/plans/m08-2-friendly-notification-tester-plan-v1.md`) or the
frozen navigation addendum
(`docs/plans/m08-2-friendly-notification-tester-navigation-addendum-v1.md`). The repair commit
(`398ab18`) addressed only defects surfaced by the previously-deferred combined validation gate —
one real evaluation-logic bug (absent-field defaulting), test-harness nonce/capability-denial
bugs, a stale test expectation, and routine formatting — never a scope change.

## Product Owner acceptance

**Approved.** The grouped menu/tab rearrangement was explicitly approved, and M08.2 as a whole was
explicitly approved for merge to `main`.

## Confirmations

- No live Telegram or other external provider call was made at any point in this milestone's
  implementation or closure work.
- No bot, destination, webhook, or other plugin/site configuration was changed on this or any
  environment.
- `NotificationTester` — the only new code path capable of evaluating a real, stored notification
  rule — never constructs a dispatcher, a dispatch-log/event-history/audit-log repository, a queue
  class, or a Telegram/HTTP client (a structural allow-list test enforces this).
- Every example value and test submission travels only by nonce-protected POST and is never
  persisted, cached, or logged.
