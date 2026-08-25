# M09.1 — Operator-Approved AI Draft Delivery

## Status

Planned (documentation frozen; implementation not started)

## Dependencies

M09 (implemented; Product Owner safety acceptance still gates M10, not necessarily M09.1 docs). M05.2 for Conversations operator-message path and escalated-topic mirror gate. ADR-0036.

## Objective

Allow an authorised operator to approve a version-bound AI draft and send it to the visitor chat as **Support team**, with optional Telegram mirror only when an escalated topic already exists.

## Product value

Removes manual copy/paste into Telegram for reviewed drafts without enabling autonomous AI sending.

## Included scope

- Edit draft → **Approve and send to chat**.
- Version-bound approval; edit invalidates.
- Delivery via Conversations boundary (idempotent, encrypted, retry-safe).
- Visitor attribution: **Support team** (not named operator, not AI).
- Conditional Telegram mirror only if escalated topic exists; never create topic for mirror alone.
- Audit of operator, content version, approval time, delivery attempt/outcome.
- Narrow structural allow-list extension per ADR-0036.

## Explicit exclusions

- Any automatic send; changing M09 generation/request/`ai_ack` rules.
- Direct AI visitor replies (M10).
- Creating Telegram topics for non-escalated conversations.
- M08.1/M08.2.

## Architectural constraints

ADR-0028 remains the default structural prohibition; ADR-0036 is the sole narrow exception for this explicit action.

## Deliverables

Hub UI action, Conversations operator-message service, draft version/delivery fields, tests including allow-list and attribution.

## Acceptance criteria

- Exact approved text delivered; edit after approve blocks stale send.
- Visitor sees Support team only.
- No Telegram traffic from this path without a pre-existing escalated topic.
- No auto-send from generate/lease-sweep/provider.

## Entry criteria

ADR-0036 and plan frozen; M05.2 operator-message foundation available (or sequenced); branch from `origin/main`.

## Exit criteria

Acceptance met; verification complete; closure with Product Owner acceptance.
