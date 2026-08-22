# Architecture Decision Records — Conventions

## Numbering and status

- Sequential, never reused: docs/adr/NNNN-kebab-slug.md, four digits, starting at 0001.
- Status values: Proposed, Accepted, Deprecated, Superseded by ADR-XXXX.
- Reserved numbers: 0001 project governance, 0002 plugin identity and naming, 0003 optional WooCommerce integration, 0004 v1.0 release boundary and hardening sequence, 0005 composition root and product module boundaries, 0006 queue implementation and failure semantics, 0007 persistence and migration framework, 0008 secret storage and fail-closed key handling, 0009 privacy classification and redaction model, 0010 capability model, 0011 deferred formal acceptance testing until M10, 0012 Telegram bot cardinality, webhook routing, and outbound delivery architecture, 0013 Telegram webhook authenticity, replay protection, and inbound handling, 0014 Telegram provider reliability policy (rate limiting, circuit breaking, dead-letter, queue-health alerting), 0015 event identity, envelope, registry, and safety-wrapped emission, 0016 notification rule engine storage, evaluation, and delivery idempotency, 0017 event history as a PUBLIC-only redacted projection, 0018 WooCommerce event catalog and hook binding for M03, 0019 visitor event source, threading, and browser ingestion boundary, 0020 administration hub navigation and legacy URL compatibility, 0021 conversation persistence, bearer-secret visitor authentication, and topic-scoped inbound message capture, 0022 chat widget client-side state and cache-safe configuration, 0023 expedited interactive queue dispatch and bounded synchronous diagnostic send, 0024 visitor display-name persistence, Telegram-disclosure boundary, and chat widget presentation preset system. None of these twenty-four numbers is available for any other decision. The next available number for any future ADR is 0025.

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
