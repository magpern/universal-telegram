# M09 — AI Draft Assistant

## Status

Not Started

## Dependencies

M05, M07

## Objective

Assist operators without autonomous customer responses.

## Product value

Introduces AI narrowly and safely: operator-reviewed drafts only.

## Included scope

- Provider abstraction.
- Model configuration.
- Approved-content retrieval.
- Conversation summaries.
- Draft answers.
- Source traceability.
- Human approval workflow.
- Cost and error reporting.
- Prompt-injection defences.
- Circuit breaking, dead-letter handling, and queue-health alerting for AI-provider calls, equivalent to M01's Telegram-transport mechanisms.
- Versioned AI profiles.
- Approved knowledge-source selection and management.
- Allowed and prohibited topic policies.
- Answer, refusal, and uncertainty policies.
- Per-profile read-only tool permissions.
- Escalation and human-handoff rules.
- Tone, language, and response-length configuration.
- AI policy preview and test workspace.
- Visibility into sources, tools, refusals, and escalation reasons.
- Policy revision, rollback, and audit history.

## Explicit exclusions

Any AI-first or automatic customer-facing response (M10, gated on M09's own safety and quality record); write-capable AI tools, listed in docs/future-scope.md.

## Architectural constraints

- Browser code never calls an AI provider directly.
- Visitor input may enqueue a background AI-draft workflow through M00's queue abstraction; it never triggers a synchronous provider call.
- No unapproved M09 draft may reach the visitor, under any code path.
- Credentials for AI providers reuse M00's secret-handling policy, fail-closed, no exposure to logs or frontend.
- Every draft and response records provider, model, sources, prompt-policy version, tools invoked, and approval status, a persistence-model decision requiring its own ADR.
- Policy authority order, highest to lowest:
  1. Non-overridable plugin security and privacy rules.
  2. Administrator-configured AI policy.
  3. Approved knowledge sources.
  4. Conversation context.
  5. Visitor message.
- Administrator prompts cannot grant tools or permissions beyond what the plugin's own capability and tool-registration model allows.
- Visitor input and retrieved content cannot override higher-level policy.
- When approved information is insufficient or contradictory, the AI must refuse or escalate rather than guess.

## Deliverables

Provider abstraction; model configuration; approved-content retrieval; conversation summaries; draft answers; source traceability; human approval workflow; cost and error reporting; prompt-injection defences; circuit breaking, dead-letter handling, and queue-health alerting for AI-provider calls; versioned AI profiles; approved knowledge-source selection and management; allowed and prohibited topic policies; answer, refusal, and uncertainty policies; per-profile read-only tool permissions; escalation and human-handoff rules; tone, language, and response-length configuration; an AI policy preview and test workspace; visibility into sources, tools, refusals, and escalation reasons; policy revision, rollback, and audit history.

## Acceptance criteria

- No customer receives unapproved drafts.
- Unsupported questions escalate.
- Sources and provider calls are traceable.
- Sensitive data is excluded according to policy.
- The circuit breaker activates under sustained simulated AI-provider failure.
- Dead-lettered AI jobs are retained and inspectable.
- A queue-health alert fires on a stalled AI job queue.
- Prohibited questions are refused or escalated.
- Unsupported questions are not answered from unapproved general knowledge when the profile is source-restricted.
- Tool permissions cannot be expanded through prompts.
- Policy revisions can be previewed and rolled back.
- Sources and escalation reasons are visible.
- A non-overridable security rule cannot be overridden by administrator or visitor instructions.

## Vlad's independent test focus

- Attempt to trigger an AI call as an anonymous visitor and confirm only enqueueing is possible, never a direct provider call.
- Attempt a prompt-injection payload via site content or visitor input.
- Verify an unapproved draft never reaches the customer-facing side under any code path.
- Force sustained AI-provider failure and confirm circuit-breaker and dead-letter behaviour.
- Ask a prohibited-topic question and confirm refusal or escalation, not an answer.
- Ask a question outside approved sources on a source-restricted profile and confirm it is not answered from general model knowledge.
- Attempt to expand tool permissions via an administrator-authored prompt and confirm it has no effect.
- Preview a policy revision, roll it back, and confirm the prior behaviour is restored.
- Inspect a response for visible sources, tools invoked, and escalation reasoning.
- Attempt to override a non-overridable security rule via both an administrator-configured prompt and a visitor message, and confirm neither succeeds.

## Required evidence

- Automated unit and integration test/CI results covering provider abstraction, policy enforcement, tool-permission boundaries, and reliability mechanisms.
- A completed requirements-traceability instance for M09.
- Vlad's completed acceptance report for M09.
- The frozen M09 plan's commit SHA, and any superseding plan SHAs.
- ADR-0001 (governance), ADR-0004 (v1.0 release boundary and hardening sequence — M09 is post-v1.0), plus any ADR M09's own plan introduces for the AI provider abstraction, policy authority model, or draft traceability record.

## Entry criteria

- M05 and M07 both closed PASS or PASS WITH LIMITATIONS acceptable to the Product Owner.
- The M09 implementation plan and every ADR it depends on reviewed, approved, and frozen.
- This milestone is post-v1.0 (docs/milestones/README.md, ADR-0004); its scheduling itself requires a future roadmap decision by the Product Owner before planning begins.

## Exit criteria

- All acceptance criteria met or explicitly accepted as limitations.
- Automated verification complete.
- Vlad acceptance obtained.
- Requirements traceability complete.
- Closure record committed with a Product Owner-accepted status.
- M09's AI-provider reliability mechanisms are validated by the future post-v1.0 hardening gate, not by M12's v1.0 execution.
