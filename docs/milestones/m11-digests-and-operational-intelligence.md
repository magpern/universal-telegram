# M11 — Digests and Operational Intelligence

## Status

Not Started

## Dependencies

M02, M03, M04, M09

## Dependency rationale

M11 remains a single milestone dependent on M09, because "AI-assisted internal summaries" is one of its deliverables. It is not split into an AI and a non-AI portion.

## Objective

Turn high-volume events into useful summaries.

## Product value

Prevents the automation engine from becoming noise at scale.

## Included scope

Scheduled summaries; threshold alerts; checkout-failure detection; funnel summaries; error clustering; AI-assisted internal summaries; destination-specific reporting.

## Explicit exclusions

Any new event source; relies entirely on M02 through M04's existing event data. Customer-facing AI.

## Architectural constraints

High event volumes must not flood Telegram; aggregates must reconcile with underlying retained events.

## Deliverables

Scheduled summaries; threshold alerts; checkout-failure detection; funnel summaries; error clustering; AI-assisted internal summaries; destination-specific reporting.

## Acceptance criteria

- High event volumes do not flood Telegram.
- Aggregates reconcile with retained events.
- Alert thresholds are deterministic.

## Vlad's independent test focus

Generate a high-volume event burst and confirm Telegram receives a digest, not a flood; manually reconcile a digest total against raw retained events.

## Required evidence

- Automated unit and integration test/CI results covering aggregation, thresholds, and reconciliation.
- A completed requirements-traceability instance for M11.
- Vlad's completed acceptance report for M11.
- The frozen M11 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), ADR-0004 (v1.0 release boundary and hardening sequence — M11 is post-v1.0), plus any ADR M11's own plan introduces.

## Entry criteria

- M02, M03, M04, and M09 all closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M11 implementation plan and every ADR it depends on reviewed, approved, and frozen.
- This milestone is post-v1.0 (docs/milestones/README.md, ADR-0004); its scheduling itself requires a future roadmap decision by the Product Owner before planning begins.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
