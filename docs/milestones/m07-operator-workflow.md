# M07 — Operator Workflow

## Status

Technical verification PASS, merged to `main` (`c0b45b0`). Product Owner acceptance pending manual
Telegram/topic operator-workflow testing — see `docs/closure/m07-operator-workflow-closure.md`.

## Dependencies

M05, M06

## Objective

Make Telegram practical as a support console.

## Product value

Turns the conversation backend and widget into something operators can work from inside Telegram day to day.

## Included scope

Operator identity mapping; assignment; conversation status controls; online, busy, and offline state; notifications and unread state; internal notes; resolution and reopening; conversation search in WordPress.

## Explicit exclusions

AI drafting and response (M09 onward); administrative bot commands beyond conversation-state controls already in scope here, since M08 owns broader status, stock, and order queries.

## Architectural constraints

Unauthorized Telegram users must not be able to act; all operator actions audited via M00's audit model; concurrent-operator state changes must not silently overwrite each other.

## Deliverables

Operator identity mapping; conversation assignment; conversation status controls; online, busy, and offline state; notification and unread-state handling; internal notes; resolution and reopening; conversation search in WordPress.

## Acceptance criteria

- Unauthorized Telegram users cannot act.
- Operator actions are audited.
- Multiple operators cannot silently overwrite state.

## Vlad's independent test focus

Attempt operator actions from an unmapped or unauthorized Telegram identity; have two operators act on the same conversation concurrently and inspect for silent overwrite; verify audit log completeness for a full operator session.

## Required evidence

- Automated unit and integration test/CI results covering identity mapping, concurrency handling, and audit logging.
- A completed requirements-traceability instance for M07.
- Vlad's completed acceptance report for M07.
- The frozen M07 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), and any ADR M07's own plan introduces for operator identity or concurrency control.

## Entry criteria

- M05 and M06 both closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M07 implementation plan and every ADR it depends on reviewed, approved, and frozen.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
