# M09.1 — Operator-Approved AI Draft Delivery (Plan v1)

## 1. Charter and ADRs

- Charter: [`docs/milestones/m09-1-operator-approved-ai-draft-delivery.md`](../milestones/m09-1-operator-approved-ai-draft-delivery.md)
- Governing ADR: [`docs/adr/0036-m09-1-version-bound-approve-and-send-support-team-attribution-and-conditional-telegram-mirror.md`](../adr/0036-m09-1-version-bound-approve-and-send-support-team-attribution-and-conditional-telegram-mirror.md)
- Prior: ADR-0028 (unchanged except narrow allow-list exception), ADR-0033 (mirror gate)

## 2. Repository findings

- `ConversationDraftPanel` Approve is audit-only ("NOT SENT").
- `AiDraftRepository` six-class allow-list enforced by structural tests.
- No Hub→visitor operator message path today (Telegram inbound only).
- M09 `ai_ack` remains required for draft eligibility until M10.

## 3. Assumptions

M09 generation/request/review unchanged. Delivery is Conversations-owned. Attribution label fixed string **Support team**.

## 4. Architectural decisions

Implement ADR-0036. Reject auto-send on `approved`. Reject topic creation for mirror alone.

## 5. Directory / schema / API impact

- `Administration\AI\ConversationDraftPanel` gains Approve-and-send.
- New `Conversations\OperatorOutboundMessageService` (name finalised at implement time).
- Draft columns: content version/hash, delivery state, delivered message id.
- Message attribution for visitor poll/UI: Support team.
- Mirror uses existing conversation outbound only when `topic_creation_state=created` and escalation recorded.
- Allow-list test updated for the minimum new referencing class(es).

## 6. Security and privacy

Capability + nonce; never visitor-callable; no operator display name to visitor on this path; audit operator id INTERNAL.

## 7. Test and CI impact

Integration: version mismatch reject; double-submit idempotent; no mirror without escalated topic; structural allow-list; attribution label; no send from generate/lease-sweep.

## 8. Work packages

| WP | Objective | Acceptance |
|----|-----------|------------|
| WP1 | Version fingerprint + invalidate-on-edit | Stale approve rejected |
| WP2 | OperatorOutboundMessageService | Visitor poll shows message as Support team |
| WP3 | Approve-and-send admin action + audit | Explicit click only |
| WP4 | Conditional Telegram mirror | Mirror iff escalated topic; never create topic |
| WP5 | Allow-list + tests + Hub copy update | Structural test green; no "NOT SENT only" dead-end for this action |

## 9. Risks

Accidental allow-list creep — keep named classes minimal. Confusing Support team with M10 AI assistant — distinct labels enforced in tests.

## 10. Out of scope

M10 direct AI; removing `ai_ack`; autonomous send; M08.1/M08.2; changing M09 source-only grounding.

## 11. Definition of done

Charter acceptance met; ADR-0036 implemented; closure filed.

## Regressions to prevent

- Auto-send from any non-explicit path.
- Named operator or AI label on approved drafts.
- Telegram topic created solely for mirror.
- Mirror without escalated topic.
- Weakening ADR-0028 for generation/provider paths.
