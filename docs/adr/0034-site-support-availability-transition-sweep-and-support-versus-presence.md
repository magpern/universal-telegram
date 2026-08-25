# ADR-0034 — Site Support Availability, Transition Sweep, and `/support` versus `/presence`

## Status

Superseded by ADR-0037

## Context

ADR-0026 introduced per-operator availability (`available|busy|offline`) for assignment UX and
M08's `/presence` command. That is an individual operator working state, not site-wide support
hours. M06's charter deferred business hours. Product now requires a site-level effective support
status composed from a WordPress-site-timezone weekly schedule, closed dates/exceptions, and a
manual override (`automatic|online|offline`), plus authorised Telegram commands
`/support auto|online|offline`, full audit history, and reliable waiting-queue surfacing when
support becomes available.

Calculating status only when a visitor opens the widget or an operator acts is insufficient: a
quiet 09:00 schedule reopen would never notify operators about waiting conversations.

## Decision

1. **Site support availability is a new Conversations-domain concern** (M07.2), orthogonal to
   ADR-0026 per-operator presence. Both coexist:
   - **`/support`** — site-wide support availability override commands (`auto`, `online`,
     `offline`), authorised and audited under the ADR-0027 two-factor command pattern.
   - **`/presence`** — unchanged individual operator working state (`available|busy|offline`).
2. **Effective status** is derived as:
   - if manual override is `online` or `offline`, that value wins (until cleared/expired as
     configured);
   - if override is `automatic`, effective status is computed from the weekly schedule in the
     WordPress site timezone, minus closed dates and temporary exceptions.
3. **Every effective-status change is audited** with source (`schedule_sweep`, `manual_override`,
   `telegram_command`, `admin_ui`), actor (where applicable), timestamp, prior value, new value,
   and expiry when applicable.
4. **Availability transition sweep (mandatory):** a scheduled, **idempotent** Action Scheduler
   (or equivalent existing recurring-action) job reconciles effective status on a fixed cadence.
   It must detect schedule-boundary transitions even with **no** visitor or operator request
   traffic. When the newly computed effective status differs from the durably stored last
   effective status, it persists the change once, writes the audit record once, and invokes the
   shared waiting-queue surfacing handler once. Duplicate ticks that recompute the same effective
   value are no-ops.
5. **One shared transition handler** is used by the sweep, admin override UI, and `/support`
   commands so notify/audit behaviour cannot drift across entry points.
6. **Visitor-facing online/offline copy and indicators are authorised only after M07.2 supplies
   real effective status.** M06.4 may reserve status chrome but must not claim live/offline
   earlier (see M06.4 charter).

## Alternatives

- *Derive site status solely from whether any operator is `available`.* Rejected: office hours and
  intentional site offline overrides are independent of any one operator's presence.
- *Replace `/presence` with `/support`.* Rejected: different semantics; both are required.
- *Request-time-only status calculation.* Rejected: misses quiet schedule-boundary reopenings and
  waiting-queue notifications.
- *Push/WebSocket presence fabric.* Rejected: out of scope; reuse existing Action Scheduler
  recurring-action patterns already used by digests and AI lease sweeps.

## Consequences

M07.2 owns schedule storage, override storage, audit log, `/support` catalogue entries, the
sweep, Hub configuration UI, and activation of the widget's real status display. Assignment rules
that consult operator presence (ADR-0026) remain unchanged. CommandCatalogue grows by the
`/support` family without removing `/presence`.

## Security and privacy impact

`/support` inherits ADR-0027 two-factor authorization (operator identity mapping +
`MANAGE_CONVERSATIONS`). Override and schedule data are administrative configuration, not visitor
PII. Audit contexts must not include conversation bearer secrets or message ciphertext.

## Affected Documents/Milestones

ADR-0026 (per-operator presence retained). ADR-0027 (command catalogue extended by M07.2 plan).
ADR-0035 (offline handoff / waiting surfacing consumes this ADR's transition handler). M06.4
(status chrome gating). M07.2 charter and plan.

## Compatibility/Migration Impact

Additive tables/options for schedule, exceptions, site override, last-effective-status, and
status-audit history when M07.2 is implemented. No change to `universal_telegram_operator_availability`.
Exact migration step numbers are chosen at M07.2 implementation from freshly fetched
`origin/main`. This documentation freeze does not advance `db_version`.
