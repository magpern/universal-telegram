# Milestone Registry — Universal Telegram

Status values: Not Started, Planned, In Progress, Implemented, Verifying, Closed (PASS / PASS WITH LIMITATIONS / FAIL / DEFERRED).

| # | Milestone | Charter | Status | Depends on |
|---|---|---|---|---|
| M00 | Product foundation | [m00-product-foundation.md](m00-product-foundation.md) | Planned | none |
| M01 | Telegram connectivity | [m01-telegram-connectivity.md](m01-telegram-connectivity.md) | Not Started | M00 |
| M02 | Normalized events and notifications | [m02-normalized-events-and-notifications.md](m02-normalized-events-and-notifications.md) | Not Started | M00, M01 |
| M03 | WooCommerce event coverage | [m03-woocommerce-event-coverage.md](m03-woocommerce-event-coverage.md) | Not Started | M02 |
| M04 | Visitor and browser events | [m04-visitor-and-browser-events.md](m04-visitor-and-browser-events.md) | Not Started | M02 |
| M05 | Conversation backend | [m05-conversation-backend.md](m05-conversation-backend.md) | Not Started | M00, M01 |
| M06 | Configurable chat widget | [m06-configurable-chat-widget.md](m06-configurable-chat-widget.md) | Not Started | M05 |
| M07 | Operator workflow | [m07-operator-workflow.md](m07-operator-workflow.md) | Not Started | M05, M06 |
| M08 | Administrative bot | [m08-administrative-bot.md](m08-administrative-bot.md) | Not Started | M01, M02, M03, M04, M05 |
| M09 | AI draft assistant | [m09-ai-draft-assistant.md](m09-ai-draft-assistant.md) | Not Started | M05, M07 |
| M10 | Controlled AI responses | [m10-controlled-ai-responses.md](m10-controlled-ai-responses.md) | Not Started | M09 (must close PASS or PASS WITH LIMITATIONS on safety/quality) |
| M11 | Digests and operational intelligence | [m11-digests-and-operational-intelligence.md](m11-digests-and-operational-intelligence.md) | Not Started | M02, M03, M04, M09 |
| M12 | Hardening and release | [m12-hardening-and-release.md](m12-hardening-and-release.md) | Not Started | M00, M01, M02, M03, M04, M05, M06, M07 |

## v1.0 boundary and execution sequence

v1.0 functional scope is M00–M07. Execution order for v1.0 is M00, M01, M02, M03, M04, M05, M06, M07, then M12 as the mandatory hardening and release gate. M12 closes as part of the v1.0 release and is never reopened.

M08–M11 are post-v1.0. They are not executed before the v1.0 release and do not block it. Their eventual release/hardening gate is provided by a future, newly chartered milestone when the Product Owner schedules that work — no milestone number is reserved for it now. See docs/adr/0004-v1-release-boundary-and-hardening-sequence.md.
