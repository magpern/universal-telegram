# ADR-0036 — M09.1 Version-Bound Approve-and-Send, Support Team Attribution, and Conditional Telegram Mirror

## Status

Accepted

## Context

ADR-0028 decision 6 structurally prohibits any automatic or repository-reachable path that sends
an AI draft to a visitor or Telegram. M09's `Approve` action is audit-trail only ("NOT SENT");
operators copy text into Telegram manually. Product now authorises a **narrow, explicit**
follow-on milestone **M09.1**: an authorised operator may edit a generated draft and select
**Approve and send to chat**, delivering the exact approved version to the visitor through the
WordPress conversation backend. This must not reopen M09's autonomous-send prohibition, must not
require Telegram, and must not present the message as AI or as a named individual operator.

## Decision

1. **M09 itself is unchanged.** Drafts remain operator-requested; no provider-triggered or
   automatic customer/Telegram/email delivery. Visitor `ai_ack` remains required for M09 draft
   eligibility until M10 replaces that mechanism.
2. **M09.1 adds one explicit delivery action:** after review/edit, an authorised operator selects
   **Approve and send to chat**. No other status transition sends.
3. **Approval is version-bound.** The approved content is fingerprinted (content hash / version
   token). Any edit after approval invalidates the approval. Delivery sends only the exact
   approved version.
4. **Delivery uses the Conversations boundary**, not the AI boundary's outbound paths: a new
   Conversations-owned operator-message service writes an encrypted visitor-visible message via
   the normal secure, idempotent, retry-safe path the widget already polls. Delivery attempts and
   outcomes are audited (operator id, draft id, content version/hash, timestamps, result).
5. **Visitor-facing attribution is `Support team`.** The visitor must not see a named individual
   operator and must not see AI labelling for this path. Professionally anonymous human-support
   presentation is mandatory.
6. **Telegram mirror is conditional only:** if — and only if — the conversation **already has an
   escalated Telegram topic**, mirror the same plaintext into that topic so the operator
   transcript stays coherent. **Never** create a Telegram topic solely to perform this mirror.
   Under ADR-0033 `ai-first` non-escalated conversations, approve-and-send stays WordPress-only.
7. **Structural allow-list:** ADR-0028's six-class `AiDraftRepository` allow-list may gain the
   minimum named M09.1 administration/delivery collaborator class(es) required to read the
   approved draft for this explicit action — still never referenced from visitor REST, chat-widget
   assets, webhook handlers, or generic Telegram outbound except the Conversations mirror path
   after Conversations has accepted the operator message. Automatic send from generation,
   lease-sweep, or provider callbacks remains forbidden.
8. **This is not autonomous sending** under any circumstance.

## Alternatives

- *Keep copy-paste forever.* Rejected by Product Owner: operators need Approve-and-send without
  Telegram paste.
- *Auto-send on `approved` status.* Rejected: reopens ADR-0028's core safety decision.
- *Attribute as the reviewing operator's WordPress display name.* Rejected: Product requires
  anonymous **Support team** labelling.
- *Always create/mirror to Telegram.* Rejected: would create Telegram traffic for AI-only /
  non-escalated conversations.

## Consequences

M09.1 depends on M05.2's Conversations operator-message foundation (and escalation topic state
for the mirror gate). M10's visitor-facing AI replies use distinct **AI assistant** attribution
(chartered separately; requires its own future ADR) and must never reuse Support team labelling.
Hub UI copy that today says "send manually via Telegram" is updated only in M09.1.

## Security and privacy impact

Capability- and nonce-gated admin action only. Visitor cannot invoke delivery. Delivery must not
leak draft internals, provider errors, or operator identity to the visitor. Audit may record
operator user id as INTERNAL. Message bodies remain CredentialVault-encrypted at rest per
existing conversation message rules.

## Affected Documents/Milestones

ADR-0028 (decision 6 narrowly extended for explicit Approve-and-send only; all other structural
prohibitions remain). M09 charter (unchanged behaviour). M09.1 charter and plan. ADR-0033
(mirror gate). M10 (distinct AI assistant attribution; future ADR required).

## Compatibility/Migration Impact

Additive draft columns (content version/hash, delivery status, delivered message id) when M09.1
is implemented. Possible additive message attribution field for Support team vs future AI
assistant. Exact migration steps from `origin/main` at implementation time. This freeze does not
advance `db_version` or relax allow-lists in code.
