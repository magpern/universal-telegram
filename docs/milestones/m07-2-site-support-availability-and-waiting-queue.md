# M07.2 — Site Support Availability and Waiting Queue

## Status

Planned (documentation frozen; implementation not started)

## Dependencies

M05.2 (escalation/topic primitives), M06.4 (status chrome to activate). Builds on M07 / ADR-0026 and M08 / ADR-0027 without replacing them.

## Objective

Provide site-wide support availability (schedule, exceptions, override), `/support` commands, offline durable waiting with immediate Telegram topic, and idempotent transition sweep that surfaces waiting cases when support becomes online.

## Product value

Truthful online/offline visitor experience and reliable operator pickup of after-hours human requests.

## Included scope

- Weekly schedule in WordPress site timezone; closed dates/temporary exceptions.
- Manual override: `automatic`, `online`, `offline`; full audit history.
- Authorised Telegram `/support auto|online|offline` (site-wide); keep `/presence` for individual operators.
- Offline human request → durable waiting + immediate Telegram topic + honest visitor copy (ADR-0035).
- Idempotent availability transition sweep (ADR-0034).
- Waiting queue Hub filters; notify operators on offline→online.
- Configurable expected-response message for offline handoffs.
- Enable M06.4 real online/offline status UI.

## Explicit exclusions

Direct AI (M10); M09.1 approve-and-send; replacing per-operator presence; M08.1/M08.2 Automations.

## Architectural constraints

ADR-0034, ADR-0035, ADR-0033. Shared transition handler for sweep, UI, and `/support`.

## Deliverables

Persistence, admin UI, commands, sweep job, waiting surfacing, widget status wiring, tests.

## Acceptance criteria

- 22:00 human request → truthful offline + waiting + topic.
- Quiet 09:00 schedule reopen (no visitor traffic) → sweep detects online and surfaces waiting once.
- Duplicate sweep ticks do not duplicate notifications.
- `/support` authorised and audited; `/presence` unchanged in role.
- Widget online/offline matches effective status after this milestone.

## Entry criteria

M05.2 closed or Product-Owner-accepted parallelisation rules satisfied; M06.4 status chrome present; plans/ADRs frozen; branch from `origin/main`.

## Exit criteria

Acceptance met; verification complete; closure with Product Owner acceptance.
