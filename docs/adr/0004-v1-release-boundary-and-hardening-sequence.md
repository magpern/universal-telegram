# ADR-0004 — v1.0 Release Boundary and Hardening Sequence

## Status

Accepted (effective upon Product Owner approval of the documentation baseline that includes this ADR)

## Context

master-plan.md's roadmap numbers milestones M0 through M12 sequentially and separately states that v1.0 should include M0 through M7. Read together, the sequential numbering could be misread as implying execution order M0 through M12 in sequence, which would place M8 through M11 (administrative bot, AI draft assistant, controlled AI responses, digests) before the v1.0 hardening milestone M12, contradicting the stated v1.0 boundary. This needed resolving explicitly so the milestone registry and every later milestone's dependency and entry-criteria fields are unambiguous about execution order versus identity numbering.

## Decision

- v1.0 functional scope is M00 through M07.
- M12 executes immediately after M07, as the mandatory v1.0 hardening and release gate. M12's v1.0 execution hardens and validates only M00 through M07, including the M01 Telegram-transport reliability mechanisms; it does not include M08 through M11.
- M08 through M11 are post-v1.0: they are not executed before the v1.0 release and do not block it.
- M12 closes as part of the v1.0 release and is never reopened.
- A future, newly chartered milestone will provide the release and hardening gate for M08 through M11, including validation of M09's AI-provider reliability mechanisms, when the Product Owner schedules that work. No milestone number is reserved for it now.

## Alternatives

- Execute strictly in numeric order, M0 through M12 sequentially with M12 last — rejected: this would require M08 through M11, including AI, to ship before any release, contradicting master-plan.md's explicit v1.0 boundary and unnecessarily coupling the v1.0 release to AI-safety work the master plan itself treats as following after the non-AI foundation is stable.
- Reopen M12 later to additionally cover M08 through M11 once they ship — rejected: reopening a closed milestone breaks the closure record's meaning as a point-in-time acceptance, and was explicitly ruled out this session.

## Consequences

- The milestone registry and every M08 through M11 charter must state their post-v1.0 status and the future, unnumbered release-gate decision explicitly.
- M12's charter and docs/testing/test-strategy.md's reliability-mechanism testing section scope failure-injection validation to M01's Telegram mechanisms only, for the v1.0 execution. M09's AI-provider reliability mechanisms are validated by the future hardening gate, not by this M12 execution.
- No milestone number is reserved for that future gate; it receives the next available milestone number when the Product Owner schedules it.

## Affected Documents/Milestones

docs/milestones/README.md, v1.0 boundary section; M12 (scope correction); M08, M09, M10, M11 (post-v1.0 status).

## Compatibility/Migration Impact

None — no code exists yet. A future decision to change the v1.0 boundary itself requires a new ADR superseding this one.
