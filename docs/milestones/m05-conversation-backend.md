# M05 — Conversation Backend

## Status

Not Started

## Dependencies

M00, M01

## Objective

Establish secure persistent conversations.

## Product value

The backend precondition for customer chat; without it, no chat widget or operator workflow can exist.

## Included scope

Conversation and message models; secure visitor tokens; Telegram topic creation; bidirectional routing; status and assignment; reconnection; retention controls.

## Explicit exclusions

Any frontend widget UI (M06); operator-facing workflow tooling beyond raw routing (M07); attachments (docs/future-scope.md); AI participation in conversations (M09 onward).

## Architectural constraints

No Telegram credentials or internal identifiers exposed to visitors; conversation isolation between parallel visitors is a hard security requirement; secure token generation and storage reuse M00's secret-handling policy.

## Deliverables

Conversation and message models; secure visitor token issuance; Telegram topic creation; bidirectional routing; status and assignment tracking; reconnection support; retention controls.

## Acceptance criteria

- Parallel conversations remain isolated.
- Navigation does not lose a conversation.
- Unauthorized visitors cannot read another conversation.
- Telegram replies reach the correct visitor.

## Vlad's independent test focus

Run two simultaneous conversations and attempt to cross-read them; navigate away and back mid-conversation; attempt to guess or forge a conversation token.

## Required evidence

- Automated unit and integration test/CI results covering conversation isolation, token security, and reconnection.
- A completed requirements-traceability instance for M05.
- Vlad's completed acceptance report for M05.
- The frozen M05 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), and any ADR M05's own plan introduces for the conversation model or token security.

## Entry criteria

- M00 and M01 both closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M05 implementation plan and every ADR it depends on reviewed, approved, and frozen.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
