# Architecture Decision Records — Conventions

## Numbering and status

- Sequential, never reused: docs/adr/NNNN-kebab-slug.md, four digits, starting at 0001.
- Status values: Proposed, Accepted, Deprecated, Superseded by ADR-XXXX.
- Reserved numbers: 0001 project governance, 0002 plugin identity and naming, 0003 optional WooCommerce integration, 0004 v1.0 release boundary and hardening sequence, 0005 composition root and product module boundaries, 0006 queue implementation and failure semantics, 0007 persistence and migration framework, 0008 secret storage and fail-closed key handling, 0009 privacy classification and redaction model, 0010 capability model, 0011 deferred formal acceptance testing until M10, 0012 Telegram bot cardinality, webhook routing, and outbound delivery architecture, 0013 Telegram webhook authenticity, replay protection, and inbound handling, 0014 Telegram provider reliability policy (rate limiting, circuit breaking, dead-letter, queue-health alerting), 0015 event identity, envelope, registry, and safety-wrapped emission, 0016 notification rule engine storage, evaluation, and delivery idempotency, 0017 event history as a PUBLIC-only redacted projection, 0018 WooCommerce event catalog and hook binding for M03, 0019 visitor event source, threading, and browser ingestion boundary, 0020 administration hub navigation and legacy URL compatibility, 0021 conversation persistence, bearer-secret visitor authentication, and topic-scoped inbound message capture, 0022 chat widget client-side state and cache-safe configuration, 0023 expedited interactive queue dispatch and bounded synchronous diagnostic send, 0024 visitor display-name persistence, Telegram-disclosure boundary, and chat widget presentation preset system, 0025 authenticated chat access, persistent ownership, and concurrency-safe conversation continuity, 0026 operator workflow identity-gated inbound authorization, availability/unread state, and concurrency-safe assignment, 0027 administrative bot commands entity-based recognition, two-factor authorization, bounded read-only WooCommerce queries, and confirmation-gated lifecycle transitions, 0028 AI draft assistant explicit acknowledgement gate, source-only grounding, provider abstraction, lifecycle/concurrency control, and structural delivery prohibition, 0029 M11A non-AI visitor-activity digests, 0030 M11B operational summaries threshold alerts and operator-reviewed AI summarization, 0031 conversation topic lifecycle archive remote forum-topic deletion and topic-unavailable repair, 0032 M08.1 any-match condition mode and three additional fixed operators, 0033 conversation routing policy modes and escalation-gated Telegram (Superseded by ADR-0037), 0034 site support availability transition sweep and `/support` versus `/presence` (Superseded by ADR-0037), 0035 offline human handoff and waiting-case surfacing (Superseded by ADR-0037), 0036 M09.1 version-bound approve-and-send Support team attribution and conditional Telegram mirror (Superseded by ADR-0037), 0037 Support Chat extraction supersession and optional adapter consumer role, 0038 Support Chat ADR-0007 pin and signed Contract client follow-up, 0039 Support Chat ADR-0008 pin and legacy export boundary follow-up, 0040 legacy-chat quiescence write-blocking and drain (SC-M03 Work Package 2), 0041 Support Chat ADR-0009 pin and legacy binding preparation follow-up (SC-M03 Work Package 5), 0042 Support Chat ADR-0010 pin and final-cutover state machine, activation, and incident ownership (SC-M03 final cutover, documentation freeze only), 0043 Support Chat ADR-0011 pin and `channel_case_ref` = conversation UUID adapter correction (SC-M03 final-cutover disposable DEV rehearsal finding F1; Accepted 2026-08-27 — F1 implementation authorized, no operational action), 0044 Universal Telegram transport-only — retire legacy website chat and the SC-M03 migration/cutover track (supersedes ADR-0040/0042/0043; marks ADR-0039/0041 superseded; Accepted 2026-08-28), 0045 interactive-priority transport for Support Chat chat delivery (fixed `delivery_class`, `interactive_chat` queue placement ahead of `standard` with FIFO within class, ADR-0023 trigger wired into the adapter path; extends ADR-0012/0014/0023 within ADR-0044; Proposed). None of these forty-five numbers is available for any other decision. The next available number for any future ADR is 0046.

## Immutability

Once an ADR is Accepted, its Context, Decision, Alternatives, Consequences, Security and privacy impact, Affected Documents/Milestones, and Compatibility/Migration Impact sections are never edited. Only the Status field may later change, to Deprecated or Superseded by ADR-XXXX. A changed decision is always recorded as a new ADR that supersedes the old one — an accepted ADR is never described as amended.

## Required sections

1. Status
2. Context
3. Decision
4. Alternatives
5. Consequences
6. Security and privacy impact
7. Affected Documents/Milestones
8. Compatibility/Migration Impact

ADR-0001 through ADR-0004 predate the eighth section and are not retroactively edited to add it, per the Immutability rule above; every ADR from ADR-0005 onward includes it.

## When an ADR is required

Architecture or composition pattern; a security boundary; a persistence model; a public contract; a milestone boundary; significant product behaviour with no prior precedent; a previously accepted decision that must change.

## When an ADR is not required

Ordinary defect fixes and refactors that preserve existing contracts, unless the fix itself alters one of the categories above.
