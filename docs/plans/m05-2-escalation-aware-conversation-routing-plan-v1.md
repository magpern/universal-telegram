# M05.2 — Escalation-Aware Conversation Routing (Plan v1)

## 1. Charter and ADRs

- Charter: [`docs/milestones/m05-2-escalation-aware-conversation-routing.md`](../milestones/m05-2-escalation-aware-conversation-routing.md)
- Governing ADR: [`docs/adr/0033-conversation-routing-policy-modes-and-escalation-gated-telegram.md`](../adr/0033-conversation-routing-policy-modes-and-escalation-gated-telegram.md)
- Prior: ADR-0021 (persistence, CAS, REST), ADR-0025 (authenticated ownership)

## 2. Repository findings (freeze-time)

- Topic creation and outbound currently run on every accepted visitor message (`ConversationsController` → `TopicCreationDispatcher` + outbound route).
- `ConversationStatus` map is frozen (`new|open|waiting_*|resolved|archived`); M07.1 adds archive paths.
- `origin/main` at documentation freeze: do not treat historical M08.1 feature branches as baseline. Read `Migrator::target_version()` from fresh `origin/main` when implementation starts (freeze-time observation: `30`).

## 3. Assumptions and open questions

**Assumptions (decided):** dual modes per ADR-0033; do not replace status map; authenticated-only chat retained.

**Open until implementation:** exact column names for escalation metadata; whether routing mode is a Settings key or dedicated row — choose the plugin's existing settings/singleton pattern at implement time without new product decisions.

## 4. Architectural decisions

Implement ADR-0033 as written. Reject pure escalation-gated default before M10.

## 5. Directory / schema / API impact

- **Namespaces:** `UniversalTelegram\Conversations` (+ thin settings read).
- **Likely types:** `RoutingPolicy`, `EscalationService`, changes to topic/outbound dispatch call sites.
- **Schema (additive at implement time):** escalation timestamp/reason/policy code; durable routing-mode setting. Step numbers from then-current `origin/main`.
- **API:** optional authenticated escalation REST action for `ai-first`; no Telegram ids in responses. Existing start/post/poll contracts preserved under `human-first`.

## 6. Security and privacy

Uniform-404 auth; no Telegram secrets to visitors; audit escalation without bearer/ciphertext.

## 7. Test and CI impact

WordPress-only integration suite primary. Mode matrix unit tests. No WooCommerce-required coverage unless touching WC-only paths (none expected).

## 8. Work packages

| WP | Objective | Likely files | Acceptance |
|----|-----------|--------------|------------|
| WP0 | Freeze already done in architecture amendment | docs | n/a |
| WP1 | Persist routing mode + escalation metadata | Migrator, repositories, domain | migrations verify; defaults `human-first` |
| WP2 | RoutingPolicy resolver | Conversations | mode selection matches admin/M10 gate |
| WP3 | Gate topic/outbound by mode | ConversationsController, TopicCreation*, Outbound* | human-first = today; ai-first = no Telegram until escalate |
| WP4 | EscalationService + history backfill | new service, outbound | one topic; ordered idempotent backfill |
| WP5 | Tests + ARCHITECTURE closure notes | tests/, docs/closure | CI green for touched paths |

Validation commands (at implement time): unit + WP-only integration + phpcs/phpstan on touched PHP — not required for this docs freeze.

## 9. Risks and mitigations

| Risk | Mitigation |
|------|------------|
| Accidental `ai-first` default | Default `human-first`; M10-only enablement |
| Double topics | Existing CAS |
| Backfill duplicates | Idempotent outbound keys |

## 10. Out of scope

M06.4 visuals; M07.2 schedule/sweep; M09.1 send; M10 AI; M08.1/M08.2; ADR-0032.

## 11. Definition of done

Charter acceptance criteria met; ADR-0033 implemented; no pre-M10 message void; closure record filed; Product Owner acceptance.

## Regressions to prevent

- Message void under default mode.
- Telegram traffic for non-escalated `ai-first` conversations.
- Visitor exposure of Telegram internals.
- Breaking inbound topic reply capture after escalation.
