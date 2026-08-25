# M10 — Controlled AI Responses

## Status

Not Started

## Dependencies

- M09 closed specifically PASS or PASS WITH LIMITATIONS on safety and quality, acceptable to the Product Owner — DEFERRED or FAIL on M09 blocks M10 from starting.
- M07.2 closed (site support availability, waiting queue, and real online/offline visitor status).
- M09.1 closed (or Product-Owner-accepted sequencing) so Support team approved-draft delivery remains distinct from AI assistant replies.
- M05.2 closed (routing policy modes), so this milestone may flip the site to `ai-first` escalation mode.
- A **future M10-specific ADR** (not created in the chat-experience documentation freeze) must be reviewed, approved, and frozen with the M10 implementation plan before any M10 code. That ADR must cover visitor-facing AI delivery, admin enablement replacing `ai_ack`, escalation triggers, disclosure, and the structural relaxation of ADR-0028 isolation for AI replies only.

## Objective

Permit narrowly scoped AI-first chat: administrator policy enables direct AI as the default first-line responder; Telegram remains for human support and escalation only.

## Product value

The first autonomous customer-facing AI behaviour, deliberately gated behind M09's proven safety and quality record and behind human-support / offline foundations (M07.2).

## Included scope

- Administrator policy enables/disables direct AI (not a visitor checkbox).
- Flip site routing to **AI-first escalation mode** (ADR-0033) when direct AI is enabled.
- Replace visitor `ai_ack` checkbox eligibility with site policy + disclosure text (supersession to be recorded in the future M10 ADR).
- AI response pipeline with timeout, failure handling, rate limits, and audit records.
- Escalation triggers (human request; order/payment/refund/account/complaint/safety; AI uncertainty/failure; admin-defined rules).
- On escalation, truthful handoff copy based on M07.2 effective support status (online vs offline waiting).
- Visitor-facing attribution: **AI assistant** — never **Support team**, never a named human operator.
- Per-profile AI policies consuming M09 published policy versions; confidence and escalation rules; restricted read-only tools; human takeover; response limits; AI disclosure; evaluation suite.
- AI may continue answering general questions while a human request waits, without hiding or cancelling the request.

## Relationship to M09 / M09.1

M10 consumes M09 policy, knowledge, permission, and testing foundations. It does not create a separate uncontrolled policy system. AI-first operation may only use an explicitly published M09 policy version — never a draft, preview, or unpublished revision.

M09.1 Support team approve-and-send remains a distinct human-approved path. M10 must not blur AI assistant replies with Support team attribution.

## Explicit exclusions

- Any write-capable AI tool (docs/future-scope.md).
- Autonomous WordPress, WooCommerce, Telegram-admin, or other write-capable actions.
- The fully Automatic mode described in master-plan.md's operating-mode table, which is more permissive than what M10's own deliverables describe.
- Implementing M05.2/M06.4/M07.2/M09.1 inside this milestone.
- M08.1 / M08.2 work.

## Architectural constraints

- Adversarial-prompt resistance is acceptance-blocking.
- Human takeover must be immediate under all conditions.
- No unauthorized tool invocation.
- Ordinary AI-only conversations produce **zero** Telegram traffic (ADR-0033 `ai-first`).
- Future M10 ADR required before implementation (see Dependencies).

## Deliverables

Future M10 ADR + implementation plan (separate freeze); AI-first pipeline; routing-mode flip; disclosure without visitor checkbox; AI assistant attribution; escalation into M05.2/M07.2 paths; evaluation suite.

## Acceptance criteria

- Adversarial prompt tests pass.
- Unsupported health and policy questions escalate.
- No unauthorized tool invocation occurs.
- Human takeover is immediate.
- AI-only exchanges create no Telegram traffic.
- Visitor sees **AI assistant** labelling for direct AI replies.
- Online/offline escalation wording is truthful per M07.2.
- Enabling direct AI flips routing to `ai-first`; disabling restores `human-first` compatibility behaviour.

## Vlad's independent test focus

Run an adversarial-prompt test suite; ask a question deliberately outside approved content and confirm escalation, not fabrication; invoke human takeover mid-response and confirm immediacy; attempt to run AI-first operation against a draft or unpublished M09 policy version and confirm it is rejected; confirm Telegram silence for non-escalated AI chat; confirm attribution labels.

## Required evidence

- Automated unit and integration test/CI results, including the adversarial-prompt evaluation suite.
- A completed requirements-traceability instance for M10.
- Vlad's completed acceptance report for M10.
- The frozen M10 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001, ADR-0004, ADR-0033, ADR-0034/0035 as consumed, ADR-0036 boundary respect, plus the future M10 ADR.

## Entry criteria

- Dependencies above satisfied.
- Future M10 ADR and implementation plan reviewed, approved, and frozen (code-free).
- Branch from freshly fetched `origin/main`.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
