# ADR-0032 — Any-Match Condition Mode and Three Additional Fixed Operators

## Status

Accepted

## Context

M08.1 requires a plain-language rule builder offering "all conditions must match" and "any
condition may match" modes, plus is/is-not/at-least/at-most/does-not-contain operators that map
cleanly onto typed fields. ADR-0016 fixed evaluation as AND-only and named a closed six-operator
enum. Both were deliberate M02 decisions, not oversights, but neither constraint is required by
this milestone's actual architectural concerns: dispatch idempotency (event_id + rule_id
uniqueness), the seven-state dispatch log, and the claim/reject mechanism are all independent of
how a rule's own conditions combine or how many comparison operators exist.

## Decision

`notification_rules` gains a `match_mode` ENUM('all','any') NOT NULL DEFAULT 'all' column
(migration step 30). Every existing row defaults to 'all', preserving current AND-only behavior
exactly; RuleEvaluator's rejection logic is unchanged for match_mode='all'. For 'any',
`RuleEvaluator::rejection_reason()` returns null (match) as soon as one clause matches, and
'condition_not_matched' only if none do — still short-circuiting, still no nesting, still a flat
clause array. `ConditionOperator` gains three cases — `NOT_CONTAINS`, `AT_LEAST` (`>=`),
`AT_MOST` (`<=`) — evaluated with the same never-throws, false-on-incomparable semantics as the
existing six. The enum remains closed; no expression syntax, scripting, or per-field custom
comparator is introduced.

A clause whose field is absent from the event (`EventEnvelope::value_at()` returns `null`) never
matches, for every operator without exception — including `not_equals` and the new
`not_contains`, where absence must not be conflated with "differs from." `match_mode='any'`
matches only if at least one clause with a present field evaluates true; a rule whose every clause
targets an absent field does not match under `any` either. An empty condition list continues to
always match, unchanged.

## Alternatives

- Nested condition groups (AND-of-ORs) — rejected; the flat single-mode toggle covers every
  preset and manual case this milestone requires, and nesting reopens exactly the complexity
  ADR-0016 correctly avoided.
- A separate `notification_rules_v2` table — rejected; an additive nullable-defaulted column on
  the existing table is sufficient and keeps one source of truth.
- Mapping "does not contain" / "at least" / "at most" onto existing operators client-side only
  (e.g. `at_least` as `greater_than` with value-1) — rejected; it silently breaks for non-integer
  money/decimal fields and produces a stored condition that doesn't say what it means.

## Consequences

Legacy rules (no explicit match_mode at write time) evaluate identically to before this change.
DispatchLogRepository and the seven dispatch-log states are untouched. RuleSimulator inherits the
absent-field and any-mode semantics automatically, since it evaluates through the same
`RuleEvaluator` code path (`RuleSimulator.php:94` subclasses it, not reimplements it); its own
test gains scenarios simulating an event that omits an allowed field, confirming the simulated
preview correctly shows non-match rather than a false positive. `ConditionOperatorTest` gains
cases for the three new operators and for absence-with-`not_equals`/absence-with-`not_contains`;
`RuleEvaluatorTest` gains match_mode='any' coverage including the all-clauses-absent case.

## Security and privacy impact

None: `match_mode` is not a data field and carries no classification; the three new operators
operate only on fields already permitted by the existing per-event-type allowlist enforced by
`Registry::allowed_variable_fields_for()`, unchanged.

## Affected Documents/Milestones

`docs/adr/0016-notification-rule-engine-storage-evaluation-and-delivery-idempotency.md` — this ADR
supersedes only its "AND-only conditions" and "fixed six-operator enum" clauses; the seven-state
dispatch model, claim/reject mechanism, and idempotency guarantees it documents are unaffected and
remain governing. `docs/plans/m08-1-friendly-rule-builder-and-notification-presets-plan-v1.md` is
the frozen implementation plan this ADR was drafted alongside.

## Compatibility/Migration Impact

Migration step 30 (see `docs/adr/0007`'s framework): `ALTER TABLE ... ADD COLUMN match_mode ...
DEFAULT 'all'`, additive only, no backfill needed beyond the column default. No existing table
column removed or retyped.
