# ADR-0037 — Support Chat Extraction Supersession and Optional Adapter Consumer Role

## Status

Accepted

## Context

PR #30 (`31f54fc`) froze a chat-experience architecture amendment inside Universal Telegram (ADR-0033 through ADR-0036; planned milestones M05.2, M06.4, M07.2, M09.1, and amended M10). That path deepened Conversations → Telegram coupling and authorised further chat product work inside this plugin.

Product direction has since extracted website support chat into the standalone **Universal Support Chat** plugin (`magpern/universal-support-chat`). Support Chat owns the website widget, conversations/tickets, Hub inbox/replies, availability, and future chat AI, and must work fully without Telegram. Universal Telegram becomes an **optional channel adapter** for escalated support traffic only.

Continuing to implement M05.2–M10 as Universal Telegram chat milestones would conflict with that boundary. A new ADR is required to supersede the chat-inside-Universal-Telegram **implementation direction** while preserving historical ADR and closure records, and to pin the canonical Support Chat Contract v1.

## Decision

### 1. Supersession of ADR-0033 through ADR-0036 (implementation direction only)

ADR-0033, ADR-0034, ADR-0035, and ADR-0036 remain in the repository as **historical Accepted records**, with Status updated to **Superseded by ADR-0037**. Their Context/Decision text is not rewritten.

They are **no longer authorised as the implementation direction** for new Universal Telegram chat-product code. The chat-inside-Universal-Telegram follow-on path they described is replaced by Support Chat milestones and this plugin’s optional adapter role.

### 2. Superseded Universal Telegram implementation paths

The following are **superseded for future implementation in Universal Telegram** (documents retained; not deleted; not rewritten as if never planned):

| Path | Status after this ADR |
|---|---|
| M05.2 — Escalation-aware conversation routing | Superseded for UT implementation; requirements rehomed to Support Chat + adapter |
| M06.4 — Professional chat-widget visual revamp | Superseded for UT implementation; rehomed to Support Chat SC-M05 |
| M07.2 — Site support availability and waiting queue | Superseded for UT implementation; rehomed to Support Chat SC-M06 |
| M09.1 — Operator-approved AI draft delivery | Superseded for UT implementation; rehomed to Support Chat SC-AI1 |
| M10 — Controlled AI responses | Superseded for UT implementation; rehomed to Support Chat SC-AI2 |

**No M05.2 code was ever implemented.** The empty local branch `feature/m05-2-escalation-aware-routing` contains no unique commits relative to `main` and may be deleted **only after** this documentation amendment merges.

### 3. Preserved closed / implemented chat history (not undone)

| Milestone | Treatment |
|---|---|
| M05, M06, M07 (and related closed chat follow-ons already on `main`) | Remain **legacy runtime** chat components in Universal Telegram until Support Chat **SC-M03** migration cutover. Not claimed extracted, deleted, or disabled by this ADR. |
| M09 (implemented) | Remains **historical/legacy** operator-draft behaviour in Universal Telegram until future Support Chat **SC-AI1** rehoming/migration. Not rewritten as undelivered. |
| Closure records for M05–M09 and the PR #30 docs freeze | **Immutable history** — not rewritten. |

### 4. Universal Telegram product role going forward

Universal Telegram **remains** responsible for:

- bots, destinations, webhook handling, and Telegram commands;
- event, WooCommerce, and error notifications;
- Telegram transport, credentials, topics, remote IDs, outbound queues and retries;
- an optional future **Universal Support Chat adapter** (UT Adapter M1).

Universal Telegram is **no longer the implementation home** for:

- website widget;
- conversations/messages/tickets as product SoR;
- visitor identity and retention for support chat;
- WordPress Hub inbox / direct visitor replies as the support product;
- support availability, waiting queue, or chat AI as future product work.

Non-chat event and notification functions are **unchanged** by this ADR.

### 5. Optional Support Chat adapter consumer

Universal Telegram is an optional **adapter consumer** of Support Chat Contract v1. It must not invent a parallel chat SoR.

### 6. Canonical Contract v1 pin (immutable)

Support Chat owns the canonical Contract v1. Universal Telegram **must not copy Contract v1 in full** into this repository.

**Exact pin for this freeze and for UT Adapter M1:**

| Field | Value |
|---|---|
| Repository | `magpern/universal-support-chat` |
| Commit SHA | `dff2730e24b7d3f70f15f706305e12e14fdcc6c8` |
| Canonical URL | `https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md` |

Any future contract change requires a coordinated versioned update and a new Universal Telegram ADR (or superseding pin ADR) citing a new Support Chat commit SHA/URL.

### 7. Failure and storage boundaries

- Channel/adapter failure or deactivation **fails closed for Telegram only**. Website chat and Hub remain operational in Support Chat.
- **No cross-plugin direct database table access.**
- The future adapter **owns** Telegram bindings, topics, native IDs, delivery queue, and retry state. Support Chat stores only opaque channel-case / binding references as defined by Contract v1.

### 8. Program sequence (cross-repo)

After this documentation amendment merges, executable order is:

`SC-M00–M02` → **UT Adapter M1** → `SC-M03` → `SC-M04` → later Support Chat feature milestones.

UT Adapter M1 must exist **before** SC-M03 because existing Telegram topics require bindings at cutover.

## Alternatives

- Continue implementing M05.2 inside Universal Telegram — rejected: deepens coupling against the extraction decision.
- Dual-write chat SoR during coexistence — rejected by Support Chat ADR-0004; not authorised here.
- Duplicate full Contract v1 text in this repository — rejected: drift risk; pin SHA/URL instead.
- Delete or rewrite ADR-0033–0036 bodies — rejected: immutability and historical evidence.

## Consequences

- Milestone registry marks M05.2 / M06.4 / M07.2 / M09.1 / M10 as superseded for UT implementation.
- UT Adapter M1 charter and plan become the authorised next Universal Telegram chat-adjacent work (adapter only).
- Legacy chat runtime continues until SC-M03; this ADR does not extract or disable it.
- M08.1, M08.2, Automations, and ADR-0032 remain untouched and in force for notification rules.

## Security and privacy impact

- Prevents accidental dual SoR and cross-plugin table access.
- Keeps Telegram credentials and topic IDs inside Universal Telegram.
- Fail-closed channel degradation avoids breaking Support Chat Hub confidentiality/availability.

## Affected Documents/Milestones

- ADR-0033, ADR-0034, ADR-0035, ADR-0036 (Status → Superseded by ADR-0037)
- M05.2, M06.4, M07.2, M09.1, M10 charters (additive supersession notes)
- New: UT Adapter M1 charter and plan
- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/milestones/README.md`, `docs/adr/README.md`, `docs/plans/README.md`
- Support Chat repository Contract v1 (external pin only)

## Compatibility/Migration Impact

- **No runtime code, schema, or version change** in this documentation freeze.
- Plugin version remains whatever is on `main` at freeze time (`0.16.0`); `universal_telegram_db_version` target remains `30`.
- Data migration is Support Chat SC-M03; adapter bindings are UT Adapter M1 then SC-M03.
