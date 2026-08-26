# Milestone Registry — Universal Telegram

Status values: Not Started, Planned, In Progress, Implemented, Verifying, Closed (PASS / PASS WITH LIMITATIONS / FAIL / DEFERRED), **Superseded for UT implementation**.

Statuses below reflect closure records and architecture documentation on `main` after the Support Chat extraction supersession (ADR-0037). Prefer individual closure files over this table when they disagree on fine detail (for example Product Owner acceptance still pending).

| # | Milestone | Charter | Status | Depends on |
|---|---|---|---|---|
| M00 | Product foundation | [m00-product-foundation.md](m00-product-foundation.md) | Closed (PASS) | none |
| M01 | Telegram connectivity | [m01-telegram-connectivity.md](m01-telegram-connectivity.md) | Closed (PASS) | M00 |
| M02 | Normalized events and notifications | [m02-normalized-events-and-notifications.md](m02-normalized-events-and-notifications.md) | Closed (PASS) | M00, M01 |
| M03 | WooCommerce event coverage | [m03-woocommerce-event-coverage.md](m03-woocommerce-event-coverage.md) | Closed (PASS) | M02 |
| M04 | Visitor and browser events | [m04-visitor-and-browser-events.md](m04-visitor-and-browser-events.md) | Closed (PASS) | M02 |
| M05 | Conversation backend | [m05-conversation-backend.md](m05-conversation-backend.md) | Closed (PASS) — **legacy runtime** until Support Chat SC-M03 | M00, M01 |
| M05.2 | Escalation-aware conversation routing | [m05-2-escalation-aware-conversation-routing.md](m05-2-escalation-aware-conversation-routing.md) | **Superseded for UT implementation** (ADR-0037); no code was implemented | historical: M05; ADR-0033 |
| M06 | Configurable chat widget | [m06-configurable-chat-widget.md](m06-configurable-chat-widget.md) | Closed (PASS) core + follow-ons — **legacy runtime** until SC-M03 | M05 |
| M06.4 | Professional chat-widget visual revamp | [m06-4-professional-chat-widget-visual-revamp.md](m06-4-professional-chat-widget-visual-revamp.md) | **Superseded for UT implementation** (ADR-0037); rehomed to Support Chat SC-M05 | historical: M06 |
| M07 | Operator workflow | [m07-operator-workflow.md](m07-operator-workflow.md) | Implemented (tech PASS; PO acceptance may remain pending) — **legacy runtime** until SC-M03 | M05, M06 |
| M07.2 | Site support availability and waiting queue | [m07-2-site-support-availability-and-waiting-queue.md](m07-2-site-support-availability-and-waiting-queue.md) | **Superseded for UT implementation** (ADR-0037); rehomed to Support Chat SC-M06 | historical: M05.2, M06.4; ADR-0034/0035 |
| M08 | Administrative bot | [m08-administrative-bot.md](m08-administrative-bot.md) | Implemented (tech PASS; PO acceptance may remain pending) | M01, M02, M03, M04, M05 |
| M08.1 | Friendly rule builder and notification presets | (plan) [m08-1-friendly-rule-builder-and-notification-presets-plan-v1.md](../plans/m08-1-friendly-rule-builder-and-notification-presets-plan-v1.md) | Closed — PO-approved and merged to `main` (ADR-0032). **Unchanged** by Support Chat extraction | M08 / M02 |
| M08.2 | Friendly notification tester and grouped Hub navigation | (plan) [m08-2-friendly-notification-tester-plan-v1.md](../plans/m08-2-friendly-notification-tester-plan-v1.md) | Closed (PASS) — see [closure](../closure/m08-2-friendly-notification-tester-closure.md). **Unchanged** by Support Chat extraction | M08.1 |
| M09 | AI draft assistant | [m09-ai-draft-assistant.md](m09-ai-draft-assistant.md) | Implemented (tech PASS; PO acceptance may remain pending) — **historical/legacy** until Support Chat SC-AI1 | M05, M07 |
| M09.1 | Operator-approved AI draft delivery | [m09-1-operator-approved-ai-draft-delivery.md](m09-1-operator-approved-ai-draft-delivery.md) | **Superseded for UT implementation** (ADR-0037); rehomed to Support Chat SC-AI1 | historical: M09; ADR-0036 |
| M10 | Controlled AI responses | [m10-controlled-ai-responses.md](m10-controlled-ai-responses.md) | **Superseded for UT implementation** (ADR-0037); rehomed to Support Chat SC-AI2 | historical: M09 / M07.2 / M09.1 |
| UT Adapter M1 | Universal Support Chat Adapter | [ut-adapter-m1-universal-support-chat-adapter.md](ut-adapter-m1-universal-support-chat-adapter.md) | Implemented and merged (PR #32); signed-client follow-up implemented and merged including the joint interoperability gate (PR #34, PR #35, ADR-0038); **legacy export boundary (`LegacyExportServiceV1`, ADR-0039 work package 8) implemented, PO acceptance pending** | ADR-0037; Contract v1 pin; ADR-0038; Support Chat ADR-0007 pin; ADR-0039; Support Chat ADR-0008 pin; SC-M01/SC-M02; closures `docs/closure/ut-adapter-m1-universal-support-chat-adapter-closure.md`, `docs/closure/ut-adapter-m1-signed-contract-client-closure.md`, `docs/closure/ut-adapter-m1-wp6-interop-gate-closure.md`, `docs/closure/ut-adapter-m1-legacy-export-service-closure.md` |
| M11 | Digests and operational intelligence | [m11-digests-and-operational-intelligence.md](m11-digests-and-operational-intelligence.md) | Split M11A/M11B — see charter | M02, M03, M04, M09 (M11B) |
| M12 | Hardening and release | [m12-hardening-and-release.md](m12-hardening-and-release.md) | Not Started | M00–M07 |

## Support Chat extraction (authoritative direction)

ADR-0037 supersedes the PR #30 chat-inside-Universal-Telegram follow-on **implementation** path (ADR-0033–0036; M05.2 / M06.4 / M07.2 / M09.1 / M10). Those documents remain historical.

**Authorised next Universal Telegram chat-adjacent work:** [UT Adapter M1](ut-adapter-m1-universal-support-chat-adapter.md).

**Cross-repo sequence:** `SC-M00–M02` → **UT Adapter M1** → **ADR-0038 signed-client follow-up (implemented)** → **ADR-0039 legacy export boundary follow-up (pinned, not yet implemented)** → `SC-M03` work packages 3–4 → `SC-M03` work packages 5–8 → `SC-M04`.

Legacy M05/M06/M07 chat runtime and implemented M09 remain in this plugin until Support Chat migration / SC-AI1 — not claimed extracted by the documentation amendment.

**Empty branch note:** `feature/m05-2-escalation-aware-routing` has no unique commits vs `main` (no M05.2 code). It may be deleted only after ADR-0037’s documentation amendment is merged.

M08.1 and M08.2 must not be modified by Support Chat extraction work.

## v1.0 boundary and execution sequence

v1.0 functional scope remains M00–M07 + M12 per ADR-0004 for the historical product boundary. Post-extraction, new website-chat product work belongs in Universal Support Chat; Universal Telegram continues non-chat Telegram operations and the optional Support Chat adapter.
