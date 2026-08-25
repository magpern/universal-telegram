# ADR-0035 — Offline Human Handoff and Waiting-Case Surfacing

## Status

Accepted

## Context

Product requires that a visitor requesting human support outside support hours always creates a
durable support case: truthful offline wording for the visitor, a Telegram topic for later
operator handling, and surfacing when support next becomes available. Claiming that a human has
already seen the conversation before an operator has acted is forbidden.

ADR-0033 defines routing modes and escalation-gated topics. ADR-0034 defines site effective
status and the mandatory availability transition sweep. This ADR freezes the offline-handoff and
waiting-queue behaviour those two ADRs enable.

## Decision

1. **A human-support request while effective site status is offline** (or otherwise not online):
   - creates a durable waiting conversation in WordPress;
   - **creates the Telegram topic immediately** (retry-safe, at-most-once CAS per ADR-0033 /
     ADR-0021 mechanics) so operators later have full context in Telegram;
   - records escalation/waiting metadata;
   - shows the visitor a **truthful** expected-response / offline message — never "a human has
     been notified and is reading now" or equivalent false immediacy.
2. **A human-support request while effective site status is online** routes into the normal
   operator workflow / Telegram topic path with online handoff wording.
3. **Waiting-case surfacing** runs when effective status transitions to online (via the shared
   transition handler in ADR-0034): Hub queue visibility and operator notification about waiting
   conversations. Surfacing is idempotent per conversation per transition event.
4. **Expected-response copy** for offline handoffs is administrator-configurable within M07.2
   bounds and must remain honest relative to schedule/override state.
5. **Operator replies** continue to use existing Telegram inbound capture (ADR-0021 / ADR-0026)
   once a topic exists; WordPress remains system of record.

## Alternatives

- *Defer Telegram topic creation until an operator is online.* Rejected by explicit Product Owner
  decision: offline requests still get a topic immediately for later handling.
- *Claim "support has been notified" while offline.* Rejected: misleading.
- *Separate notification channel (email) for waiting cases.* Deferred; out of this amendment's
  scope.

## Consequences

M07.2 implements offline copy, waiting filters, and notify-on-online behaviour on top of
ADR-0033 escalation and ADR-0034 sweep. M05.2 must not block immediate topic creation for offline
human requests. M06.4 must not show misleading online status before M07.2.

## Security and privacy impact

Same conversation isolation and uniform-404 visitor auth posture as ADR-0021 / ADR-0025. Waiting
notifications to Telegram must not include bearer secrets or unnecessary PII beyond what existing
conversation-topic disclosure rules already allow (ADR-0024).

## Affected Documents/Milestones

ADR-0033, ADR-0034, M05.2, M07.2, M06.4 (honest copy), M10 (online/offline handoff confirmation
wording when AI escalates).

## Compatibility/Migration Impact

Additive waiting/escalation fields and notification idempotency keys as needed by M07.2.
No destructive schema changes. Migration steps numbered at implementation time from
`origin/main`. This freeze does not advance `db_version`.
