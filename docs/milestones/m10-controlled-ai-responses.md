# M10 — Controlled AI Responses

## Status

Not Started

## Dependencies

M09

## Objective

Permit narrowly scoped AI-first chat.

## Product value

The first autonomous customer-facing AI behaviour, deliberately gated behind M09's proven safety and quality record.

## Included scope

Per-profile AI policies; confidence and escalation rules; restricted read-only tools; human takeover; response limits; AI disclosure; evaluation suite.

## Relationship to M09

M10 consumes the policy, knowledge, permission, and testing foundations delivered by M09; it does not create a separate policy system. AI-first operation may only use an explicitly published M09 policy version — never a draft, preview, or unpublished revision.

## Explicit exclusions

Any write-capable AI tool, listed in docs/future-scope.md; the fully Automatic mode described in master-plan.md's operating-mode table, which is more permissive than what M10's own deliverables describe.

## Architectural constraints

Adversarial-prompt resistance is acceptance-blocking, not best-effort; human takeover must be immediate under all conditions; no unauthorized tool invocation, ever.

## Deliverables

Per-profile AI policies consuming M09's published policy versions; confidence and escalation rules; restricted read-only tools; human takeover; response limits; AI disclosure; evaluation suite.

## Acceptance criteria

- Adversarial prompt tests pass.
- Unsupported health and policy questions escalate.
- No unauthorized tool invocation occurs.
- Human takeover is immediate.

## Vlad's independent test focus

Run an adversarial-prompt test suite; ask a question deliberately outside approved content and confirm escalation, not fabrication; invoke human takeover mid-response and confirm immediacy; attempt to run AI-first operation against a draft or unpublished M09 policy version and confirm it is rejected.

## Required evidence

- Automated unit and integration test/CI results, including the adversarial-prompt evaluation suite.
- A completed requirements-traceability instance for M10.
- Vlad's completed acceptance report for M10.
- The frozen M10 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), ADR-0004 (v1.0 release boundary and hardening sequence — M10 is post-v1.0), plus any ADR M10's own plan introduces.

## Entry criteria

- M09 closed specifically PASS or PASS WITH LIMITATIONS on safety and quality, acceptable to the Product Owner — DEFERRED or FAIL on M09 blocks M10 from starting.
- The M10 implementation plan and every ADR it depends on reviewed, approved, and frozen.
- This milestone is post-v1.0 (docs/milestones/README.md, ADR-0004); its scheduling itself requires a future roadmap decision by the Product Owner before planning begins.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
