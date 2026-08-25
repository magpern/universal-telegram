# ADR-0033 — Conversation Routing Policy Modes and Escalation-Gated Telegram

## Status

Superseded by ADR-0037

## Context

ADR-0021 established WordPress as the conversation system of record and created a Telegram
forum topic after the first accepted visitor message, then routed every subsequent visitor
message into that topic. That behaviour is correct for today's human-support chat, but it
conflicts with the approved long-term product decision that ordinary AI-only exchanges must
produce zero Telegram traffic, with Telegram reserved for human support and escalation.

M05–M09 are already implemented. Reopening those closed milestones is forbidden. The
architecture amendment therefore introduces follow-on milestone **M05.2**, which must land
routing policy and escalation primitives without creating a pre-M10 production "message void"
in which visitors write and no human ever sees the message.

## Decision

1. **WordPress remains the canonical conversation system of record.** Telegram is an operator
   channel, not a mirror of every exchange.
2. **Site routing policy has exactly two modes**, selected by site/admin policy (not a visitor
   checkbox):
   - **`human-first` (compatibility)** — **default while M10 direct AI is disabled.** Visitor
     messages preserve today's ADR-0021 behaviour: topic creation and Telegram outbound routing
     occur so human operators continue to receive support traffic. Escalation metadata and
     history-backfill machinery still exist so the site can switch modes later without a second
     redesign.
   - **`ai-first` (escalation)** — **enabled only when M10 direct AI is enabled.** Ordinary
     visitor/AI messages remain in WordPress and create **zero** Telegram API traffic. A
     Telegram topic is created and outbound routing begins only when the visitor explicitly
     requests human support or a defined escalation policy requires it.
3. **Escalation is a first-class, durable event.** When escalation occurs (in either mode when
   a human case is opened under `ai-first`, or when offline handoff rules apply under M07.2),
   the Conversations boundary records escalation metadata (timestamp, reason/policy code, actor
   class) without replacing the existing `ConversationStatus` transition map. Status vocabulary
   from ADR-0021 / ADR-0026 / ADR-0031 is extended with additive columns/flags only.
4. **Topic creation remains lazy, compare-and-set, and at-most-once per conversation**, reusing
   ADR-0021's `topic_creation_state` CAS. Under `ai-first`, the CAS runs on escalation, not on
   the first visitor message. Under `human-first`, behaviour matches today's first-message
   trigger.
5. **On escalation under `ai-first`, ordered history backfill** delivers prior WordPress-stored
   messages into the new topic in chronological order, retry-safe and idempotent, before
   continuing bidirectional routing for subsequent messages.
6. **No Telegram credentials, topic IDs, bot tokens, or internal operator identifiers** are
   ever exposed to visitors on any REST or widget surface.
7. **Do not ship `ai-first` silence as the live default before M10.** Releasing
   WP-only-until-Request-support as standalone production behaviour before direct AI exists is
   an accepted regression risk and is forbidden by this ADR.

## Alternatives

- *Always stop Telegram on first message immediately (pure escalation-gated default).* Rejected:
  creates a pre-M10 message void and regresses live human support.
- *Always escalate every message even after M10.* Rejected: defeats AI-first Telegram silence.
- *Replace the `ConversationStatus` map with `ai_active` / `waiting_for_support` /
  `open_with_operator`.* Rejected: breaks M07 / M07.1 transition and retention contracts; product
  vocabulary is mapped via additive escalation/support metadata instead.
- *Create topics at conversation `start`.* Rejected; already rejected by ADR-0021 and still
  wrong (empty-topic abuse).

## Consequences

M05.2 implements the policy resolver, escalation service, and mode-gated topic/outbound
triggers. M10 is the only milestone authorised to flip a site into `ai-first`. M06.4 may expose
a Request-support control for UX consistency; under `human-first` that control is not the sole
path to operators. Master-plan wording that every conversation always gets a Telegram topic is
superseded for `ai-first` mode by this ADR; `human-first` preserves the historical behaviour.

## Security and privacy impact

Reduces Telegram exposure of conversation content under `ai-first` until a human case exists.
Does not weaken visitor authentication (ADR-0021 / ADR-0025). Escalation audit context must not
include bearer secrets, ciphertext, or raw Telegram identifiers beyond existing INTERNAL
conversation ids already permitted by ADR-0009 patterns.

## Affected Documents/Milestones

ADR-0021 (topic/outbound trigger superseded only where this ADR's mode rules differ; persistence,
bearer auth, REST contract, inbound three-gate capture, and CAS mechanics remain governing).
M05.2 charter and plan. M07.2 (offline escalation still creates a topic). M09.1 (mirror only if
escalated topic exists). M10 (enables `ai-first`). `docs/master-plan.md` website-chat bullets.
`docs/ARCHITECTURE.md` Conversations boundary note.

## Compatibility/Migration Impact

Additive only when M05.2 is implemented: site routing-mode setting and escalation metadata
columns/indexes. No destructive change to existing conversations. Existing conversations that
already have topics continue to route under `human-first`. Exact `Migrator` step numbers are
chosen at M05.2 implementation time from freshly fetched `origin/main`'s then-current
`target_version()` — this documentation freeze does not advance `db_version`.
