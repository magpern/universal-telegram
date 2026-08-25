# M07.2 — Site Support Availability and Waiting Queue (Plan v1)

## 1. Charter and ADRs

- Charter: [`docs/milestones/m07-2-site-support-availability-and-waiting-queue.md`](../milestones/m07-2-site-support-availability-and-waiting-queue.md)
- ADRs: [0034](../adr/0034-site-support-availability-transition-sweep-and-support-versus-presence.md), [0035](../adr/0035-offline-human-handoff-and-waiting-case-surfacing.md), [0033](../adr/0033-conversation-routing-policy-modes-and-escalation-gated-telegram.md)
- Prior: ADR-0026, ADR-0027

## 2. Repository findings

- Per-operator availability table and `/presence` exist.
- No site schedule, no `/support`, no availability sweep.
- Action Scheduler recurring actions already used (digests, AI lease sweep) — reuse that pattern.

## 3. Assumptions

Site timezone = WordPress site timezone. `/support` and `/presence` coexist. Offline human request creates Telegram topic immediately.

## 4. Architectural decisions

Implement ADR-0034 and ADR-0035. Shared transition handler mandatory. Sweep mandatory.

## 5. Directory / schema / API impact

- New repositories for schedule, exceptions, site override, last effective status, status audit.
- Extend `CommandCatalogue` / `BotCommandDispatcher` with `/support`.
- Hub admin UI for hours/override/copy.
- Widget config gains real `support_status` after this milestone.
- Migration steps additive from then-current `origin/main` `target_version()`.

## 6. Security and privacy

`/support` two-factor auth (ADR-0027). Capability-gated admin. Audit without secrets/ciphertext.

## 7. Test and CI impact

WP-only integration: schedule TZ boundaries, override precedence, sweep idempotency, quiet reopen without HTTP visitor traffic, command auth. Extend command catalogue tests.

## 8. Work packages

| WP | Objective | Acceptance |
|----|-----------|------------|
| WP1 | Schedule + exceptions + override persistence | CRUD; site TZ |
| WP2 | EffectiveStatus calculator + audit | Precedence correct |
| WP3 | Shared transition handler + waiting surfacing | Online transition notifies once |
| WP4 | Idempotent availability sweep | Quiet 09:00 reopen works; double tick no-op |
| WP5 | `/support` commands | Auth + audit; `/presence` untouched |
| WP6 | Hub UI + expected-response copy | Configurable offline message |
| WP7 | Enable widget online/offline | Matches effective status |
| WP8 | Tests + closure | CI green |

## 9. Risks

Missed boundary if sweep interval too coarse — choose cadence comparable to existing 60s sweeps or document maximum lag. Notification storms — idempotent per transition.

## 10. Out of scope

M10 AI; M09.1; replacing operator presence; M08.1/M08.2.

## 11. Definition of done

Charter acceptance met including quiet reopen; closure filed.

## Regressions to prevent

- Request-only status (no sweep).
- Duplicate waiting notifications.
- False "human notified" offline copy.
- Breaking `/presence`.
- Showing online when override is offline.
