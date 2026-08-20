# M02 — Normalized Events and Notifications

## Status

Not Started

## Dependencies

M00, M01

## Objective

Replace fixed notification checkboxes with configurable rules.

## Product value

The core automation value proposition: administrators define precisely when Telegram notifications fire.

## Included scope

Event model and registry; core WordPress events; rule engine; AND condition groups; Telegram action; message templates; deduplication and cooldown; event history; rule simulation.

## Explicit exclusions

WooCommerce-specific events (M03); visitor and browser events (M04); nested OR condition groups, deferred in docs/future-scope.md — including them in M02 requires Master Architect review, Product Owner approval, and a charter and future-scope update, not a unilateral implementation-plan choice; the generic webhook action (docs/future-scope.md).

## Architectural constraints

Events are structured data, never preformatted Telegram messages; privacy classification from M00 applies to every event; deduplication must prevent duplicate delivery across retries.

## Deliverables

Event model and registry; core WordPress event coverage; rule engine with AND condition groups; Telegram notification action; message templates; deduplication and cooldown; event history; rule simulation tooling.

## Acceptance criteria

- Rule evaluation is deterministic.
- No duplicate delivery occurs across retries.
- The system provides a clear explanation of matched and rejected rules.

## Vlad's independent test focus

Configure a rule, trigger it, confirm exactly one notification; retry a failed delivery and confirm no duplicate; use rule simulation and confirm it matches real evaluation.

## Required evidence

- Automated unit and integration test/CI results covering rule evaluation, deduplication, and event history.
- A completed requirements-traceability instance for M02.
- Vlad's completed acceptance report for M02.
- The frozen M02 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), and any ADR M02's own plan introduces for the event model or rule engine.

## Entry criteria

- M00 and M01 both closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M02 implementation plan and every ADR it depends on reviewed, approved, and frozen.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
