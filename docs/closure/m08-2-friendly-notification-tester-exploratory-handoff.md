# M08.2 — Friendly Notification Tester: Exploratory Handoff

Branch: `feature/m08-2-friendly-notification-tester`
Baseline: `main` @ `31c5480e416f2f3786663d128c0839ab8625a27e` (M08.1 merged, PR #28)
Freeze commit: `036479d` (`docs: freeze M08.2 friendly notification tester plan`)
Latest commit at handoff time: `9bbe61c` (`feat(admin): preserve legacy tab=simulator bookmarks and accessibility-pass the Test notifications page`)

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

## Ready for

**Product Owner exploratory UI testing only**, against a local/staging
WordPress install with this branch checked out. Formal validation (the
combined test/lint/static-analysis gate), code review, PR, merge, release,
and any production approval all remain pending and are separate, later
steps.

### Suggested exploratory checks (from the frozen plan's manual PO checklist, §9)

1. **Existing-notification mode** — Hub → "Test notifications" tab →
   "Test an existing notification" → pick a real notification → confirm the
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
   directly and confirm it lands on "Test notifications," not "Overview."

Formal validation (the combined test/lint/static-analysis gate), code
review, PR, merge, release, and production approval all remain pending and
are separate, later steps.
