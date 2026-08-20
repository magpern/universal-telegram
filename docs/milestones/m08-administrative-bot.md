# M08 — Administrative Bot

## Status

Not Started

## Dependencies

M01, M02, M03, M04, M05

## Dependency rationale

Order, stock, and sales commands require WooCommerce domain data (M03). The `/visitors 30m` command requires M04's visitor-event data, and the `/conversations` command requires M05's conversation backend. All five are therefore formal dependencies.

## Objective

Provide secure operational queries from Telegram.

## Product value

Lets an administrator check site and shop status without opening WordPress.

## Included scope

Command registry; read-only status, order, stock, error, sales, visitor, and conversation commands; capability mapping; confirmation framework; auditing; extension API.

## Explicit exclusions

Controlled write commands, listed in docs/future-scope.md; M08 includes only the read-only command set.

## Architectural constraints

No arbitrary WordPress, SQL, shell, or PHP execution ever, under any future scope; Telegram identity allowlist and WordPress user association required even for read-only commands.

## Deliverables

Command registry; read-only status, order, stock, error, sales, visitor, and conversation commands; capability mapping; confirmation framework; auditing; extension API.

## Acceptance criteria

- A permission matrix is demonstrated.
- Invalid and replayed commands are rejected.
- Sensitive fields are redacted in command output.

## Vlad's independent test focus

Attempt commands from an unlisted Telegram identity; replay a valid command; inspect command output for redaction gaps; exercise every listed command, including WooCommerce, visitor, and conversation queries.

## Required evidence

- Automated unit and integration test/CI results covering the permission matrix, replay rejection, and redaction.
- A completed requirements-traceability instance for M08.
- Vlad's completed acceptance report for M08.
- The frozen M08 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), ADR-0003 (optional WooCommerce integration), ADR-0004 (v1.0 release boundary and hardening sequence — M08 is post-v1.0), plus any ADR M08's own plan introduces.

## Entry criteria

- M01, M02, M03, M04, and M05 all closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M08 implementation plan and every ADR it depends on reviewed, approved, and frozen.
- This milestone is post-v1.0 (docs/milestones/README.md, ADR-0004); its scheduling itself requires a future roadmap decision by the Product Owner before planning begins.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
- Its release gate remains a future, unnumbered roadmap decision (ADR-0004); closing M08 does not itself trigger a release.
