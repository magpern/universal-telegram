# Milestone Registry — Universal Telegram

Status values: Not Started, Planned, In Progress, Implemented, Verifying, Closed (PASS / PASS WITH LIMITATIONS / FAIL / DEFERRED).

Statuses below reflect closure records and architecture documentation on `main` as of the chat-experience architecture amendment. Prefer individual closure files over this table when they disagree on fine detail (for example Product Owner acceptance still pending).

| # | Milestone | Charter | Status | Depends on |
|---|---|---|---|---|
| M00 | Product foundation | [m00-product-foundation.md](m00-product-foundation.md) | Closed (PASS) | none |
| M01 | Telegram connectivity | [m01-telegram-connectivity.md](m01-telegram-connectivity.md) | Closed (PASS) | M00 |
| M02 | Normalized events and notifications | [m02-normalized-events-and-notifications.md](m02-normalized-events-and-notifications.md) | Closed (PASS) | M00, M01 |
| M03 | WooCommerce event coverage | [m03-woocommerce-event-coverage.md](m03-woocommerce-event-coverage.md) | Closed (PASS) | M02 |
| M04 | Visitor and browser events | [m04-visitor-and-browser-events.md](m04-visitor-and-browser-events.md) | Closed (PASS) | M02 |
| M05 | Conversation backend | [m05-conversation-backend.md](m05-conversation-backend.md) | Closed (PASS) | M00, M01 |
| M05.2 | Escalation-aware conversation routing | [m05-2-escalation-aware-conversation-routing.md](m05-2-escalation-aware-conversation-routing.md) | Planned | M05; ADR-0033 |
| M06 | Configurable chat widget | [m06-configurable-chat-widget.md](m06-configurable-chat-widget.md) | Closed (PASS) core + follow-ons; deferred charter items remain | M05 |
| M06.4 | Professional chat-widget visual revamp | [m06-4-professional-chat-widget-visual-revamp.md](m06-4-professional-chat-widget-visual-revamp.md) | Planned | M06; soft M05.2; live/offline gated on M07.2 |
| M07 | Operator workflow | [m07-operator-workflow.md](m07-operator-workflow.md) | Implemented (tech PASS; PO acceptance may remain pending) | M05, M06 |
| M07.2 | Site support availability and waiting queue | [m07-2-site-support-availability-and-waiting-queue.md](m07-2-site-support-availability-and-waiting-queue.md) | Planned | M05.2, M06.4; ADR-0034, ADR-0035 |
| M08 | Administrative bot | [m08-administrative-bot.md](m08-administrative-bot.md) | Implemented (tech PASS; PO acceptance may remain pending) | M01, M02, M03, M04, M05 |
| M08.1 | Friendly rule builder and notification presets | (plan) [m08-1-friendly-rule-builder-and-notification-presets-plan-v1.md](../plans/m08-1-friendly-rule-builder-and-notification-presets-plan-v1.md) | Closed — PO-approved and merged to `main` (ADR-0032). **Out of scope** for the chat-experience amendment | M08 / M02 |
| M08.2 | Friendly notification tester and grouped Hub navigation | (plan) [m08-2-friendly-notification-tester-plan-v1.md](../plans/m08-2-friendly-notification-tester-plan-v1.md) | Closed (PASS) — see [closure](../closure/m08-2-friendly-notification-tester-closure.md). **Out of scope** for the chat-experience amendment | M08.1 |
| M09 | AI draft assistant | [m09-ai-draft-assistant.md](m09-ai-draft-assistant.md) | Implemented (tech PASS; PO acceptance may remain pending) | M05, M07 |
| M09.1 | Operator-approved AI draft delivery | [m09-1-operator-approved-ai-draft-delivery.md](m09-1-operator-approved-ai-draft-delivery.md) | Planned | M09; M05.2 (escalation metadata + escalated-topic mirror gate only); ADR-0036. M09.1 itself owns the Conversations operator outbound-message service |
| M10 | Controlled AI responses | [m10-controlled-ai-responses.md](m10-controlled-ai-responses.md) | Not Started | M09 (PASS/LIMITATIONS on safety/quality); M07.2; M09.1; future M10 ADR required |
| M11 | Digests and operational intelligence | [m11-digests-and-operational-intelligence.md](m11-digests-and-operational-intelligence.md) | Split M11A/M11B — see charter | M02, M03, M04, M09 (M11B) |
| M12 | Hardening and release | [m12-hardening-and-release.md](m12-hardening-and-release.md) | Not Started | M00–M07 |

## Chat-experience follow-on sequence

Documentation freeze: ADR-0033–0036 and plans for M05.2, M06.4, M07.2, M09.1. Recommended implementation order:

1. M05.2 → 2. M06.4 → 3. M07.2 → 4. M09.1 → 5. M10 (blocked on M09 PO acceptance and M07.2).

M08.1 and M08.2 must not be modified by chat-experience work.

## v1.0 boundary and execution sequence

v1.0 functional scope remains M00–M07 + M12 per ADR-0004. Post-v1.0 follow-ons (including M05.2, M06.4, M07.2, M08.x, M09.x, M10, M11) are scheduled by Product Owner decision and do not rewrite closed v1.0 closure history.
