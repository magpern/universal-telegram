# M08.2 — Friendly Notification Tester: Plan v1 (revised)

## Context

The current Hub Simulator tab (`RuleSimulatorPage` + `RuleSimulator`,
`src/Administration/Automations/RuleSimulatorPage.php`,
`src/Automations/RuleSimulator.php`) is a developer diagnostic: a raw
`event_type` `<select>` of technical identifiers, a free-text JSON textarea
for `actor/subject/context/payload`, and a bare rule/outcome/reason-code
table. It is safe (no dispatch, no log write) but unusable by a normal
administrator, and it also submits sample data over GET, which is unsafe
once sample data can include real-looking operational values (usernames,
order totals). M08.1 (branch `feature/m08-1-friendly-rule-builder`, frozen
at `6012c75`, latest `59fd012`) already solved the friendly-metadata
problem for the rule *builder*: `EventCatalogLabels`, `FieldTypeCatalog`,
`ConditionRowRenderer`, `PreviewRenderer`, `RuleEditor`. M08.2 reuses that
catalog, replaces the Simulator tab with an operator-safe "Test
notifications" tab, and fixes the older tool's own submission-channel and
layering weaknesses along the way.

## Verified baseline and M08.1 dependency status

- Current branch: `feature/m08-1-friendly-rule-builder`, clean, up to date
  with `origin/feature/m08-1-friendly-rule-builder`.
- `docs/closure/m08-1-friendly-rule-builder-exploratory-handoff.md`:
  implementation **complete** on this branch, **not merged to `main`**, not
  validated, not reviewed, no PR.
- `EventCatalogLabels`, `FieldTypeCatalog`, `ConditionRowRenderer`,
  `PreviewRenderer`, `RuleEditor`, `PresetCatalog` all read directly.
- **Gate**: M08.2 implementation must not start until M08.1 lands on its
  implementation baseline (merged to `main`, or at minimum its own
  validation gate passes as the agreed integration point). Re-verify the
  seams below if that shape changes before merge.

## Seams reused (read, not modified, during this planning task)

| Concern | Existing class | Reused how |
|---|---|---|
| Event/field labels | `EventCatalogLabels` | `event_type_label()`, `field_label()` |
| Field type/operators/preview/choices | `FieldTypeCatalog` | `has()`, `type()`, `operators()`, `preview_value()`, `choice_options()`, `label()` |
| Eligible fields for an event | `ConditionRowRenderer::eligible_fields()` | sample-value field list |
| Operator friendly labels | `ConditionRowRenderer::operator_labels()` | plain-language reasons |
| Legacy-rule representability | `RuleEditor::from_existing()`'s `representable` flag | compatibility notice trigger |
| Example message rendering | `PreviewRenderer::render()` | both modes' preview, incl. Mode 1's "About" section (Correction 5) |
| Rule storage | `NotificationRuleRepository::find()`, `for_event_type()`, `all()` | rule lookup, per-event list |
| Real dispatch-eligibility gate | `NotificationDispatcher`'s own `BotStatus::ACTIVE` + `$destination->enabled()` check | mirrored, not `DigestEligibility::destination_is_eligible()` (that method also excludes conversation-linked destinations — a digest-target rule, not a general dispatch rule) |
| Condition matching | `RuleEvaluator` (extended, see WP1) | both modes, and production dispatch |
| Event families grouping | `RuleBuilderPage::EVENT_FAMILIES` | extracted to `EventFamilyCatalog` (WP2), not duplicated |
| Legacy tab/slug redirects | `LegacyUrlRedirector`, `HubPage::resolve_tab_id()` | extended (WP5/WP6) |

## Layering fix

The original draft placed a `NotificationTester` in the core `Automations`
namespace while having it depend on `Administration\Automations\
FieldTypeCatalog` and `PreviewRenderer` — a layering violation (core
depending on admin UI metadata). Corrected:

- **`Automations` (core, pure)**: `RuleEvaluator` gains a structured,
  non-short-circuiting evaluation method (below) but stays free of any
  `Administration\*` dependency, exactly like today.
- **`Administration\Automations` (admin orchestration)**: the tester
  service, its result/outcome types, and the explanation formatter all
  live here, alongside `FieldTypeCatalog`/`PreviewRenderer`/
  `ConditionRowRenderer` — the same layer, not a new shared boundary.
  `RuleSimulator`'s old home in `Automations` worked only because it had
  no UI-metadata dependency; the tester does, so it belongs one layer up.

## 1. Structured evaluator result (replaces `would_match(): ?string`)

New core-layer value objects, `src/Automations/`:

```php
final class ConditionClauseResult {
    public function __construct(
        private readonly string $field,          // dot-notation path, never shown raw to users
        private readonly string $operator,
        private readonly mixed  $expected_value,
        private readonly mixed  $actual_value,    // null if absent
        private readonly bool   $field_present,
        private readonly bool   $matched,
        private readonly bool   $field_valid,     // false => uncatalogued/removed field
        private readonly bool   $operator_valid,
    ) {}
}

final class RuleMatchTrace {
    public function __construct(
        private readonly bool $matched,
        private readonly string $match_mode,
        private readonly array $clause_results,   // ConditionClauseResult[], every clause, never short-circuited
    ) {}
}
```

`RuleEvaluator` gains:

- `private function evaluate_clause( array $clause, EventEnvelope $event, array $allowed_fields ): ConditionClauseResult`
  — the single per-clause evaluation, extracted from the current inline
  loop body in `rejection_reason()` (field validity check, operator
  validity check, `EventEnvelope::value_at()`, `ConditionOperator::matches()`
  — logic unchanged, only isolated into a named unit).
- `public function evaluate_conditions( NotificationRule $rule, EventEnvelope $event ): RuleMatchTrace`
  — loops every clause via `evaluate_clause()` (no short-circuit — an
  empty condition list yields `matched = true` with zero clause results,
  matching today's rule), computes the overall `matched` flag using the
  existing `all`/`any` semantics (ADR-0032), and returns the full trace.
- `rejection_reason()` (private, used by production `evaluate_rule()`) is
  rewritten to call `evaluate_conditions()` once and translate its result
  into the existing fixed reason-code strings
  (`invalid_condition_field`, `invalid_condition_operator`,
  `condition_not_matched`) — **one evaluation algorithm**, not two;
  production dispatch derives its short-circuit-shaped outcome from the
  same trace rather than re-running condition logic.
- `RuleEvaluatorTest.php` gains trace-shaped assertions alongside the
  existing reason-code assertions, proving both surfaces agree.

`Administration\Automations\FailingConditionExplainer` (WP3, unchanged
role) consumes `RuleMatchTrace`/`ConditionClauseResult` — it formats
existing clause results into sentences; it never evaluates a condition
itself. For `match_mode='all'`, it explains every clause with
`matched === false`. For `match_mode='any'`, it explains every clause
(none matched, by construction) under a "none of the following matched"
frame — both are now honest because the trace was never short-circuited.
An `invalid_field`/`invalid_operator` clause is excluded from per-clause
prose (its field/operator can't be labelled) and instead flips the
result's separate legacy-compatibility flag (§2).

## 2. Result model: outcome + legacy flag, disabled semantics

```php
enum NotificationTestOutcome: string {
    case WOULD_SEND             = 'would_send';
    case NOT_MATCHED            = 'not_matched';
    case DISABLED                = 'disabled';               // rule.enabled() === false
    case DESTINATION_INELIGIBLE = 'destination_ineligible';   // matched, bot inactive/destination disabled
    case TEMPLATE_INVALID       = 'template_invalid';         // matched + eligible, render failed
}

final class NotificationTestResult {
    public function __construct(
        private readonly int $rule_id,
        private readonly string $rule_name,
        private readonly NotificationTestOutcome $outcome,
        private readonly array $failing_reasons,        // string[], NOT_MATCHED only
        private readonly ?string $rendered_preview,      // WOULD_SEND only
        private readonly bool $has_unrepresentable_legacy_conditions, // separate flag, any outcome
    ) {}
}
```

**Disabled**: `NotificationTester::test_rule()` checks `$rule->enabled()`
first. A disabled rule is never evaluated for a match at all — outcome is
forced to `DISABLED` immediately (mirrors `NotificationRuleRepository::
for_event_type($type, true)`, which never even loads a disabled rule for
real dispatch). UI text: *"This notification would not be sent because it
is turned off."* `test_event()` never needs this path — it only loads
`for_event_type($type, true)` (enabled rules), same as today's
`RuleSimulator`.

**Legacy**: `has_unrepresentable_legacy_conditions` is an independent
boolean, not a mutually exclusive outcome — a legacy rule is still fully
evaluated against its real stored conditions via `evaluate_conditions()`
and can land on any of the outcomes above. When true, the page renders the
compatibility notice *alongside* the normal outcome sentence and suppresses
per-clause prose (source clauses aren't safely labellable), rather than
replacing the outcome.

## 3. UX and interaction flow

Two modes selected via a `fieldset`/radio GET form (bookmarkable —
`mode`, `rule_id`, or `event_type` are catalog keys/ids, not
administrator-entered data, so GET is fine for them per Correction 6 of
this pass). Progressive disclosure below is GET-derived and read-only:

```
Test notifications
"Test your notification setup safely. No Telegram message is sent."

( ) Test an existing notification   ( ) Test a custom scenario

--- Mode 1: rule chosen via GET ---
[About this notification]  (read-only, GET-derived)
  Event: <friendly label>   Destination: <bot> -> <destination>
  Conditions: plain-language sentence(s), or the compatibility notice
  Example notification preview: rendered via PreviewRenderer against
    FieldTypeCatalog defaults — NEVER the raw template text or {{tokens}}
    (Correction 5). If rendering fails even with defaults: the same
    plain-language "message could not be built" notice TEMPLATE_INVALID
    uses, not a blank or raw fallback.

[Example values]  (POST form, own fieldset)
  <friendly field label>: <typed input, pre-filled from
    FieldTypeCatalog::preview_value()>  ... [Test this notification]
  Hidden: _wpnonce, mode, rule_id.

--- Mode 2: event chosen via GET ---
[Choose an event]  family-grouped picker (EventFamilyCatalog, WooCommerce
  families disabled+explained when inactive)

[Example values]  (POST form; fields = ConditionRowRenderer::eligible_fields())
  Hidden: _wpnonce, mode, event_type.

[Result region, aria-live="polite"]  rendered inline in the SAME POST
  response (Correction 1) — never a redirect, never persisted anywhere.
  Mode 1: one outcome statement + preview if WOULD_SEND.
  Mode 2: one row per enabled rule for that event type (empty state if
  none), each with its own outcome + preview if WOULD_SEND.
```

**Submission channel (Correction 1)**: only `mode`/`rule_id`/`event_type`
travel by GET, for bookmarking the selection screen. Every administrator-
entered example value, and the "run the test" action itself, is a POST to
the tab's own URL (not `admin-post.php`, since there is nothing to redirect
to — the result renders inline) carrying a nonce
(`NOTIFICATION_TEST_NONCE_ACTION = 'universal_telegram_notification_test'`).
`NotificationTesterPage::render_tab_content()` checks
`$_SERVER['REQUEST_METHOD'] === 'POST'`, calls `check_admin_referer()`,
reads `mode`/`rule_id`/`event_type`/`values[...]` from `$_POST` (never
`$_GET` on that branch), runs the test, and renders the result section
before the method returns — nothing is written to a transient, option,
table, log, or queue.

## 4. Class/file/module changes

**Core (`src/Automations/`, pure, no new WordPress or Administration dependency):**
- `RuleEvaluator.php` — add `evaluate_clause()`, `evaluate_conditions()`;
  rewrite `rejection_reason()` to derive from the same trace (§1).
- `ConditionClauseResult.php`, `RuleMatchTrace.php` (new).

**Admin orchestration (`src/Administration/Automations/`):**
- `NotificationTestResult.php`, `NotificationTestOutcome.php` (new, moved
  here per the layering fix).
- `NotificationTester.php` (new) — `test_rule( NotificationRule $rule,
  array $sample_values ): NotificationTestResult` and
  `test_event( string $event_type, array $sample_values ):
  array<NotificationTestResult>`. Depends only on: `RuleEvaluator`,
  `NotificationRuleRepository`, `BotProfileRepository`,
  `DestinationRepository`, `Registry`, `PreviewRenderer`,
  `FieldTypeCatalog`, `EventCatalogLabels`, `FailingConditionExplainer`.
  Never constructs `NotificationDispatcher`, `DispatchLogRepository`,
  `MessageDispatcher`, `TelegramApiClient`, `EventHistoryRepository`,
  `AuditLogger`/`AuditLogRepository`, or any `Queue\*` class — enforced by
  WP4's structural test (§8).
- `FailingConditionExplainer.php` (new) — formats `RuleMatchTrace`/
  `ConditionClauseResult` into sentences (§1); no evaluation logic.
- `EventFamilyCatalog.php` (new) — `EVENT_FAMILIES` relocated verbatim
  from `RuleBuilderPage` (`private const` → public catalog);
  `RuleBuilderPage` delegates, zero behavior change.
- `NotificationTesterPage.php` (new, replaces `RuleSimulatorPage.php`) —
  `SLUG` unchanged (`universal-telegram-rule-simulator`, so
  `LegacyUrlRedirector`'s page-slug redirect keeps working unmodified);
  `TAB_ID = 'test-notifications'`. Implements the GET-selection /
  POST-test / inline-result flow (§3).
- Delete `RuleSimulatorPage.php`, `src/Automations/RuleSimulator.php`
  (superseded by `NotificationTester::test_event()`).

**Hub shell (Correction 6):**
- `src/Administration/Hub/HubPage.php` — `resolve_tab_id()` currently
  falls back silently to the *default* tab (`overview`) for any unknown
  `tab=` value, which would silently strand `tab=simulator` bookmarks on
  the wrong tab once `TAB_ID` changes. Add a small, generic
  `private const LEGACY_TAB_ALIASES = array( 'simulator' =>
  NotificationTesterPage::TAB_ID )` consulted before the registry lookup —
  the tab-id-level counterpart of `LegacyUrlRedirector`'s existing
  page-slug-level alias table (ADR-0020's own "old identifiers preserved
  permanently" pattern), reusable for any future tab rename.
- `src/Core/Plugin.php` — replace `rule_simulator_page`/`RuleSimulator`
  wiring with `NotificationTesterPage`/`NotificationTester`; tab label
  `__( 'Test notifications', 'universal-telegram' )`; rename accessor to
  `notification_tester_page()`.
- `src/Administration/Hub/OverviewPage.php` — `'simulator'` row label →
  `'Test notifications'`.
- `src/Administration/Hub/LegacyUrlRedirector.php` — swap
  `RuleSimulatorPage::SLUG`/`TAB_ID` references for
  `NotificationTesterPage::SLUG`/`TAB_ID` (values otherwise unchanged).

## 5. Accessibility

- Mode switch: real `<fieldset><legend>` with radio `<input>`s, each with
  its own bound `<label>`.
- Every input has `<label for>`; progressive-disclosure sections are real
  `<fieldset>`s appearing via full page reload, never CSS-hidden DOM.
- Result region: single `aria-live="polite"` container; on the POST
  response, focus is moved to an `<h2 tabindex="-1">` result heading via
  the same minimal inline-script pattern `RuleBuilderPage` already uses
  for its error-summary focus handling.
- Status is never colour-only: `dashicons-yes`/`dashicons-no` plus the
  plain sentence; WP notice classes are a secondary cue only.
- Keyboard: native `<select>`, `<input>`, `<button>`, `<details>` only.

## 6. Security/privacy/no-side-effect contract

- Capability gate: `current_user_can( CapabilityRegistrar::
  MANAGE_AUTOMATIONS )` at the top of `render_tab_content()`, both the GET
  selection branch and the POST test branch.
- GET carries only catalog keys (`mode`, `rule_id`, `event_type`) — never
  administrator-entered values. POST carries example values and is nonce-
  verified (`check_admin_referer( NOTIFICATION_TEST_NONCE_ACTION )`)
  before any value is read.
- Result is rendered only in the POST response body; nothing is written
  to a transient, option, custom table, dispatch log, event-history table,
  queue job, or audit log at any point — `NotificationTester` structurally
  cannot reach any of those (§4, proven in WP4's structural test, not by
  a grep).
- Sample values sanitized per `FieldTypeCatalog::type()`: `absint()`/
  `floatval()` for number/money, `sanitize_text_field()` for text, a
  strict `'true'/'false'` check for boolean, an `in_array()` allowlist
  against `choice_options()` for choice — never `json_decode()` (closes
  today's `RuleSimulatorPage` JSON-textarea gap).
- WooCommerce-unavailable: Mode 2's family picker disables/explains
  WooCommerce-only families exactly like `RuleBuilderPage::
  render_event_picker()` (`WooCommerceSupport::is_active()`); Mode 1 needs
  no such gate (an existing WooCommerce rule could not exist without
  WooCommerce having been active at creation time).

## 7. Work packages

**WP1 — `RuleEvaluator` structured trace**
Files: `src/Automations/RuleEvaluator.php`, `ConditionClauseResult.php`,
`RuleMatchTrace.php`.
Tests: extend `RuleEvaluatorTest.php` — `evaluate_conditions()` returns a
full, non-short-circuited trace for all-mode match/fail, any-mode
match/fail (all clauses present in the trace even on early-true any-mode
matches), absent-field, invalid-field, invalid-operator; assert
`rejection_reason()`'s derived reason code still matches the trace for
every existing fixture (no behavior change to production `evaluate()`/
`dispatch()`).
Commit: `refactor(automations): add non-short-circuiting RuleEvaluator::evaluate_conditions() trace`

**WP2 — `EventFamilyCatalog` extraction** (unchanged from v1 draft)
Commit: `refactor(admin/automations): extract EventFamilyCatalog for reuse outside RuleBuilderPage`

**WP3 — `FailingConditionExplainer` over `RuleMatchTrace`**
Files: `src/Administration/Automations/FailingConditionExplainer.php`.
Tests: one case per `FieldTypeCatalog` type × {absent, present-non-
matching}; an any-mode "none matched" multi-clause case; an
invalid-field/operator clause is excluded from prose (caller shows the
legacy notice instead). Input is a `RuleMatchTrace`/`ConditionClauseResult`
built directly in the test — no evaluation re-implemented here.
Commit: `feat(admin/automations): add plain-language failing-condition explanations`

**WP4 — `NotificationTester` engine + structural no-side-effect proof**
Files: `src/Administration/Automations/NotificationTester.php`,
`NotificationTestResult.php`, `NotificationTestOutcome.php`.
Tests:
- `tests/integration/Administration/Automations/NotificationTesterTest.php`
  — scenarios in §8.
- `tests/unit/Administration/Automations/NotificationTesterStructuralTest.php`
  — a `ReflectionClass` over `NotificationTester::__construct()` asserting
  its parameter types are exactly the allowlisted set (`RuleEvaluator`,
  `NotificationRuleRepository`, `BotProfileRepository`,
  `DestinationRepository`, `Registry`, `PreviewRenderer`), i.e. an
  **allowlist** assertion (fails if any new constructor parameter is ever
  added, catalogued or not), plus explicit `assertNotContains()` checks
  against `NotificationDispatcher::class`, `DispatchLogRepository::class`,
  `MessageDispatcher::class`, `TelegramApiClient::class`,
  `EventHistoryRepository::class`, `AuditLogger::class`,
  `AuditLogRepository::class`, and every `UniversalTelegram\Queue\*`
  class present in the codebase at test time (enumerated via
  `get_declared_classes()` filtered by namespace prefix, not hand-typed,
  so a new Queue class is automatically covered).
Acceptance: structural test passes; scenario tests assert zero row-count
change in `notification_dispatch_log`, `event_history`, and the audit log
table across every scenario (runtime proof, complementing the structural
one).
Commit: `feat(admin/automations): add NotificationTester for safe, no-dispatch rule testing`

**WP5 — `NotificationTesterPage` UI (GET selection + POST test) + wiring**
Files: `NotificationTesterPage.php` (new), delete
`RuleSimulatorPage.php`/`RuleSimulator.php`, `Plugin.php`,
`OverviewPage.php`, `LegacyUrlRedirector.php`.
Tests: `NotificationTesterPageTest.php` — capability gate on both GET and
POST branches; GET selection round-trip for both modes; POST without a
valid nonce is rejected and performs no test; POST with a valid nonce
renders the result inline in the same response, with no `Location` header
issued; Mode 1's "About this notification" section never contains
`{{`/`}}` or the raw template string for any fixture rule (Correction 5);
empty-state message when `test_event()` returns `[]`.
Commit: `feat(admin/automations): replace developer Simulator tab with friendly Test notifications tab`

**WP6 — Direct-link compatibility + accessibility pass**
Files: `HubPage.php` (`LEGACY_TAB_ALIASES`), `NotificationTesterPage.php`
(styling/focus refinement, reusing `RuleBuilderPage`'s existing inline
`<style>`/focus block).
Tests: `HubPageTest.php` extended — `admin.php?page=universal-telegram&
tab=simulator` resolves to and renders the `test-notifications` tab's
content (not the default `overview` tab); `NotificationTesterPageAccessibilityTest.php`
mirroring `RuleBuilderPageAccessibilityTest.php`'s assertion shape.
Commit: `feat(admin): preserve legacy tab=simulator bookmarks and accessibility-pass the Test notifications page`

**WP7 — Combined validation gate + closure doc** (unchanged from v1 draft)
Commit: `docs: freeze M08.2 friendly notification tester closure record`

## 8. Automated scenarios

1. Enabled rule, `match_mode='all'`, all clauses match → `WOULD_SEND`,
   preview present.
2. Same rule, one clause broken → `NOT_MATCHED`, exactly that clause's
   sentence present.
3. `match_mode='any'`, one of two clauses matches → `WOULD_SEND`.
4. `match_mode='any'`, none match → `NOT_MATCHED`, both clauses' sentences
   present (trace was never short-circuited, so both are available).
5. A clause's field absent from sample values → `NOT_MATCHED` with the
   absent-field sentence, distinct from non-matching-value.
6. **Disabled rule** selected in Mode 1 → `DISABLED` immediately, exact
   text *"...because it is turned off."*, never `WOULD_SEND` regardless of
   whether its stored conditions would otherwise match.
7. Matched rule, bot not `BotStatus::ACTIVE` → `DESTINATION_INELIGIBLE`.
8. Matched rule, bot active, `destination->enabled() === false` →
   `DESTINATION_INELIGIBLE`.
9. Matched + eligible rule with an unrenderable template → `TEMPLATE_INVALID`, no preview text, no raw template echoed.
10. Legacy rule with an uncatalogued field → `has_unrepresentable_legacy_conditions === true`, evaluated via its real stored conditions and correctly lands on `WOULD_SEND` or `NOT_MATCHED` as appropriate (not forced to a separate outcome) — proves Correction 4.
11. WooCommerce event type, `WooCommerceSupport::is_active() === false` → not selectable in Mode 2 (page-layer test); `NotificationTester` itself has no WooCommerce concept.
12. Capability check fails on both the GET and POST branches → `wp_die()`.
13. **No-side-effect proof (structural + runtime)**: WP4's
    `NotificationTesterStructuralTest` (allowlist reflection) plus, for
    every scenario above, an assertion that `notification_dispatch_log`,
    `event_history`, and the audit-log table row counts are unchanged and
    no `TelegramApiClient`/`MessageDispatcher` mock was invoked.
14. `test_event()` for a zero-rule event type → `[]`, page renders the
    empty state.
15. POST without a valid `_wpnonce` → request rejected before any value is
    read or any test runs (assert `NotificationTester` was never
    constructed/called for that request).
16. `tab=simulator` direct link → renders the `test-notifications` tab's
    content, not the default tab (WP6).

## 9. Manual Product Owner checklist

1. Open "Test notifications" — intro sentence visible without scrolling.
2. Mode 1: pick a WooCommerce order rule; confirm the "About" section
   shows only friendly labels and a rendered example preview, never
   `{{order_total}}` or similar raw syntax; break one example value;
   confirm the plain-language reason reads naturally.
3. Mode 1: pick a rule whose bot is paused; confirm the distinct
   "destination disabled" message, not conflated with "would not be sent."
4. Mode 1: pick a disabled/draft rule (e.g. from the Store-essentials
   starter set); confirm the "turned off" message, not "would be sent."
5. Mode 2: WordPress-family event with WooCommerce active; every enabled
   rule for that event listed with its own outcome.
6. Mode 2: WooCommerce inactive — WooCommerce-only families visibly
   disabled with an explanation.
7. Select a rule with unrepresentable legacy conditions; confirm the
   compatibility notice appears *alongside* an accurate would-send/would-
   not-send statement, with no raw JSON anywhere.
8. Confirm via DB inspection that no test in this session wrote a
   `notification_dispatch_log`, `event_history`, audit-log, or queue-job
   row, and that submitting the "Example values" form does not add any
   parameter to the browser's address bar.
9. Bookmark `tab=simulator` from a pre-upgrade browser history entry (or
   type it manually); confirm it lands on Test notifications, not Overview.
10. Keyboard-only pass through one full Mode 1 test and one full Mode 2
    test; confirm the result is announced.

## 10. ADR/version/database recommendation

**No ADR.** UI-only reuse of M08.1/M02's frozen contracts, one pure
evaluator extension (`evaluate_conditions()` derives production's existing
reason codes — same algorithm, not a new policy), and one pure relocation
(`EventFamilyCatalog`). The GET/POST split (Correction 1) and the
dispatch-eligibility gate choice (§ seams table) are implementation
corrections, not new architectural decisions, and don't revisit ADR-0032
or ADR-0024.

**No migration.** No new table/column — every M08.2 result type is
request-local and never persisted, and the POST-not-persisted result
(Correction 1) reinforces that rather than changing it.

**Version bump**: normal minor bump at merge time, decided after M08.1's
own bump lands.

## Confirmation

No repository files, branches, commits, dependencies, tests, or external
configuration were modified during this planning task. Only this plan file
was written. No tests were executed. This revised draft is ready to freeze
pending one Master Architect review.
