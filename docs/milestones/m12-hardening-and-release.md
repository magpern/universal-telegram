# M12 — Hardening and Release

## Status

Not Started

## Dependencies

M00, M01, M02, M03, M04, M05, M06, M07

## Objective

Prepare M00 through M07 for dependable production use as the v1.0 release gate, per ADR-0004.

## Product value

The gate between "v1.0 milestones implemented" and "safe to run in production."

## Included scope

Load and concurrency testing; migration and rollback testing; security review; privacy review; accessibility review; multisite assessment; import and export; support diagnostics; documentation; release packaging; production-representative failure-injection validation of the circuit-breaking, dead-letter, and queue-health mechanisms introduced in M01 for the Telegram transport.

## Explicit exclusions

New product features; M12 hardens what M00 through M07 already deliver, it does not add scope. M08 through M11 are entirely outside this milestone, per ADR-0004; if they later require hardening, that is a separately chartered, newly numbered milestone, not a reopening of M12. M09's AI-provider reliability mechanisms are explicitly not validated by this M12 execution.

## Architectural constraints

Every constraint from M00 through M07 is re-verified under production-representative load and failure injection, not just unit and integration conditions. M12 closes as part of the v1.0 release and is never reopened.

## Deliverables

Load and concurrency testing; migration and rollback testing; security review; privacy review; accessibility review; multisite assessment; import and export; support diagnostics; developer and administrator documentation; release packaging.

## Acceptance criteria

- Production-scale queue tests pass.
- Upgrade from every supported schema version succeeds.
- Telegram failure injection validates M01's circuit-breaker, dead-letter, and queue-health mechanisms specifically.
- Clean uninstall with configurable retention is demonstrated.
- The final acceptance matrix for M00 through M07 passes.

## Vlad's independent test focus

Full final acceptance matrix pass for M00 through M07; deliberate Telegram-provider failure injection under load; upgrade path from the oldest supported schema version; multisite behaviour if in scope.

## Required evidence

- Automated unit, integration, load, and failure-injection test/CI results for M00 through M07.
- A completed requirements-traceability instance for M12.
- Vlad's completed acceptance report for M12.
- The frozen M12 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), ADR-0004 (v1.0 release boundary and hardening sequence), and every ADR accepted across M00 through M07, plus any ADR M12's own plan introduces.

## Entry criteria

- M00, M01, M02, M03, M04, M05, M06, and M07 all closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner, and collectively eligible for release hardening.
- The M12 implementation plan and every ADR it depends on reviewed, approved, and frozen.
- M12's scope (M00 through M07) reconfirmed by the Product Owner before its plan is drafted, since it is the release gate for v1.0.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status, closing M12 as the v1.0 release gate.
- M12 is never reopened; a future, separately chartered milestone will gate M08 through M11.
