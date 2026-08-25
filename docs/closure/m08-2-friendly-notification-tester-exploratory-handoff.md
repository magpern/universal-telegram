# M08.2 — Friendly Notification Tester: Exploratory Handoff

Branch: `feature/m08-2-friendly-notification-tester`
Baseline: `main` @ `31c5480e416f2f3786663d128c0839ab8625a27e` (M08.1 merged, PR #28)
Freeze commit: `036479d` (`docs: freeze M08.2 friendly notification tester plan`)
Latest commit at handoff time: `9bbe61c` (`feat(admin): preserve legacy tab=simulator bookmarks and accessibility-pass the Test notifications page`)
Addendum freeze commit: `dcfef34` (`docs(m08-2): freeze grouped Hub navigation addendum`)
Addendum implementation commit: `b93bc02` (`feat(admin): group Hub navigation into related areas`)

## Addendum: grouped Hub navigation

This branch also includes the M08.2 grouped-Hub-navigation addendum
(`docs/plans/m08-2-friendly-notification-tester-navigation-addendum-v1.md`):
the Hub's flat 13-tab top-level row is reduced to seven top-level areas
(Overview, Bots, Notifications & activity, Conversations, AI, Settings,
Diagnostics), with every existing screen reused unchanged as an accessible
secondary-tab-row section of its new area. "Test notifications" (this
milestone's own new screen) is now reached via Notifications & activity →
Test notifications, rather than as its own top-level tab. This is a
presentation/navigation refinement only — no capability model,
persistence, event contract, Telegram, or AI behavior changed, and every
section's own capability check is untouched. It is included in this same
exploratory-testing branch and remains **unvalidated and unmerged**,
exactly like the rest of M08.2: its own tests were written but not
executed, and it is not a separate milestone.

## Status

**Implementation is complete on this feature branch, for exploratory Product
Owner UI testing only.** M08.1 is included as this task's exploratory
baseline — it is merged to `main`, and this branch was created from that
exact `main` tip, not from M08.1's own now-deleted feature branch. All six
frozen work packages (`docs/plans/m08-2-friendly-notification-tester-plan-v1.md`
§7) have landed as focused commits, in order:

1. `24365cd` — `RuleEvaluator::evaluate_conditions()`: a non-short-circuiting
   structured trace (`RuleMatchTrace`/`ConditionClauseResult`), with
   production's own `rejection_reason()` rewritten to derive from the same
   trace rather than a second evaluation algorithm.
2. `c8c0661` — `EventFamilyCatalog` extracted from `RuleBuilderPage`'s own
   private `EVENT_FAMILIES` const, a pure relocation so the tester can reuse
   the exact same event-family grouping.
3. `164d9f4` — `FailingConditionExplainer`: plain-language failing-condition
   sentences formatted from an already-computed `RuleMatchTrace`, never a
   second condition-evaluation implementation.
4. `2581f30` — `NotificationTester` (in `Administration\Automations`,
   correctly layered above the pure `Automations` engine), its
   `NotificationTestResult`/`NotificationTestOutcome` value objects, the
   integration test suite covering every scenario in the frozen plan's §8,
   and a structural allowlist test proving its constructor can only ever
   depend on `RuleEvaluator`, `NotificationRuleRepository`,
   `BotProfileRepository`, `DestinationRepository`, `Registry`, and
   `PreviewRenderer` — never a dispatcher, a dispatch-log/event-history/
   audit-log repository, a Telegram/HTTP client, or any Queue-namespace
   class.
5. `918c3de` — `NotificationTesterPage` replacing `RuleSimulatorPage`
   (`RuleSimulator`, `RuleSimulatorPage`, and `SimulationResult` deleted as
   superseded), with `Plugin`, `OverviewPage`, and `LegacyUrlRedirector`
   rewired accordingly, and the package-acceptance script's simulator smoke
   test/expected-tabs list updated to match.
6. `9bbe61c` — `HubPage::LEGACY_TAB_ALIASES` (`simulator` →
   `NotificationTesterPage::TAB_ID`) so a bookmarked `?tab=simulator` URL
   keeps landing on its own content instead of silently falling back to the
   default Overview tab, plus the accessibility-focused test coverage.

**Tests were written alongside every work package but were not executed.**
Per the frozen plan's own test-execution policy (mirroring M08.1's), the
combined validation gate (full PHPUnit suite, PHPCS, PHPStan) is a deferred,
separate step — it has **not** been run as part of this task.

## What is NOT done

- No local validation (PHPUnit/PHPCS/PHPStan) has been run.
- No CI wait occurred; CI was not triggered or checked.
- No PR was opened and no merge to `main` occurred.
- No code review of this branch has occurred.
- No release, tag, or deployment occurred.
- No bot, destination, webhook, or other plugin/site configuration was
  changed on this or any environment.
- No live Telegram or other external provider call was made at any point.

## Confirmation

No repository changes beyond what is committed on this branch occurred
outside this task's scope. `NotificationTester` — the only new code path
capable of evaluating a real, stored notification rule — never constructs a
dispatcher, a dispatch-log/event-history/audit-log repository, a queue
class, or a Telegram/HTTP client (WP4's own structural test enforces this
as an allowlist, not a blacklist); every example value and test submission
travels only by nonce-protected POST and is never persisted, cached, or
logged; and no external call of any kind was made during this task's
implementation work.

## Closure

**Superseded by acceptance.** The Product Owner approved both the grouped
menu/tab rearrangement and M08.2 as a whole. The previously-deferred
combined validation gate has since been run and repaired (commit
`398ab18`) — see `docs/closure/m08-2-friendly-notification-tester-closure.md`
for the full closure record, validation evidence, and version/database
transition. This document is retained as the historical record of the
pre-acceptance exploratory-testing state; it no longer reflects "not yet
validated."

## Ready for (historical — see Closure above)

**Product Owner exploratory UI testing only**, against a local/staging
WordPress install with this branch checked out. Formal validation (the
combined test/lint/static-analysis gate), code review, PR, merge, release,
and any production approval all remain pending and are separate, later
steps.

### Suggested exploratory checks (from the frozen plan's manual PO checklist, §9)

1. **Existing-notification mode** — Hub → "Notifications & activity" →
   "Test notifications" → "Test an existing notification" → pick a real
   notification → confirm the
   "About this notification" section shows only friendly labels and a
   rendered example preview (never `{{...}}` syntax, an event id, or a
   field path) → change one example value so a condition fails → confirm a
   plain-language "would not be sent" reason.
2. **Custom-scenario mode** — "Test a custom scenario" → pick a friendly
   event → confirm every enabled rule for that event is listed with its own
   outcome, or the empty-state message if none exist.
3. **A disabled rule** — select a disabled/draft notification in existing-
   notification mode → confirm the exact wording "This notification would
   not be sent because it is turned off." — never "would be sent."
4. **A non-match explanation** — confirm an absent-field failure reads
   differently from a present-but-wrong-value failure, and that an any-mode
   rule explains every one of its own clauses when none match.
5. **A rendered preview** — confirm a matching, eligible notification shows
   a rendered example message, and that a notification with a destination
   that is currently disabled shows the distinct "matched but destination
   disabled" message instead.
6. **The no-side-effect guarantee** — via DB inspection (not just the UI),
   confirm that running several tests in this session wrote no
   `notification_dispatch_log`, `event_history`, or audit-log row, and that
   no Telegram message was sent.
7. **The legacy bookmark** — visit `admin.php?page=universal-telegram&tab=simulator`
   directly and confirm it lands on "Test notifications" inside "Notifications
   & activity," not "Overview."
8. **Grouped navigation** — confirm the top nav shows exactly the seven
   areas (Overview, Bots, Notifications & activity, Conversations, AI,
   Settings, Diagnostics); open "Notifications & activity" and confirm its
   own secondary tab row lists Notifications, Test notifications, Events,
   Event History, Visitor Tracking with correct active-state highlighting;
   confirm "Daily operations summary" and "Threshold alerts" are still
   visible on the Notifications section; visit an old direct link such as
   `?tab=rules`, `?tab=ai`, or `?tab=operator-inbox` and confirm each lands
   on its own correct area/section rather than the default Overview tab.

Formal validation (the combined test/lint/static-analysis gate), code
review, PR, merge, release, and production approval all remain pending and
are separate, later steps.
