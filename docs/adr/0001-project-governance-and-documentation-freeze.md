# ADR-0001 — Project Governance and Documentation Freeze

## Status

Accepted (effective upon Product Owner approval of the documentation baseline that includes this ADR)

## Context

universal-telegram involves four distinct roles with different authority collaborating across separate tools/threads. Without a recorded governance model, milestone scope, architectural decisions, and acceptance can drift or be marked done before independent verification occurs.

## Decision

- The four roles and their authority are as defined in docs/governance.md and are binding on all future work in this repository.
- No milestone's implementation begins before its plan and every ADR it depends on are committed as a code-free freeze package (docs/governance.md, Freeze model).
- Milestone charters (docs/milestones/mNN-*.md) define scope boundaries and are the reference against which implementation plans are reviewed; they are not implementation plans themselves.
- ADR numbering starts at 0001 with this governance ADR; 0002 is reserved for plugin identity and naming, 0003 for optional WooCommerce integration, and 0004 for the v1.0 release boundary and hardening sequence. The next available number for any future ADR is 0005.
- Closure of any milestone requires Product Owner acceptance informed by the Master Architect's recommendation and Vlad's independent acceptance testing; the Implementation Agent cannot self-certify closure.

## Alternatives

- No formal governance document, relying on conversational agreement each session — rejected: does not survive context resets between separate agent threads and tools.
- Single-approver model (Product Owner only, no separate architectural review) — rejected: conflates product scope authority with architectural soundness review, which this project explicitly separates.

## Consequences

- Every future milestone incurs a fixed process overhead (draft, review, approval, freeze, implement, verify, accept, close) before code is written, accepted as the cost of preventing unverified or unapproved work from being treated as final.
- Documentation volume grows with every milestone, accepted, kept lean by making charters boundary-defining rather than implementation-detailed.

## Affected Documents/Milestones

All milestones (M00–M12) and every future ADR; establishes docs/governance.md, docs/plans/README.md, docs/adr/README.md, docs/testing files, and docs/closure/milestone-closure-template.md as binding process documents.

## Compatibility/Migration Impact

None — no code or schema exists yet. This ADR governs process only.
