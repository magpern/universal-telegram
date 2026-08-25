# UT Adapter M1 — Universal Support Chat Adapter

## Status

Implemented (closure record: `docs/closure/ut-adapter-m1-universal-support-chat-adapter-closure.md`; awaiting Product Owner acceptance / merge)

## Dependencies

- This documentation amendment merged to `main` (ADR-0037).
- Support Chat **Contract v1** pinned exactly to:
  - Commit SHA: `dff2730e24b7d3f70f15f706305e12e14fdcc6c8`
  - Canonical URL: `https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md`
- Support Chat **SC-M01** and **SC-M02** surfaces available for Contract callbacks and Hub/conversation identity (implemented in `universal-support-chat`, not in this repository).

## Objective

Implement Universal Telegram as an optional Support Chat channel adapter: Contract discovery, Telegram-owned binding table, inbound operator reply/lifecycle callbacks into Support Chat, and outbound ensure/backfill/deliver/notify paths — without owning the support conversation SoR.

## Product value

Operators who already use Telegram can continue escalated support workflows after chat SoR moves to Support Chat. Sites without Telegram continue to use Support Chat Hub alone.

## Included scope (define now; implement later)

- Contract discovery / capability negotiation against pinned Contract v1.
- Telegram-owned binding table fields (logical):
  - opaque binding UUID (`channel_case_ref` as seen by Support Chat);
  - Support Chat conversation UUID;
  - bot / destination / topic identity;
  - lifecycle / CAS fields;
  - remote delivery cursors / remote message IDs as needed.
- Support Chat → Telegram (adapter consumer of SC delivery calls):
  - ensure escalated channel case;
  - authorised transcript backfill (format/send/retry; SC exports plaintext);
  - subsequent message delivery to an existing case;
  - operator notification.
- Telegram → Support Chat (authenticated Contract calls):
  - operator reply ingest;
  - claim / release;
  - resolve / reopen;
  - assignment updates;
  - operator presence updates;
  - channel-unavailable and delivery-failure reporting.
- Fail-closed Telegram-only behaviour on adapter failure/deactivation.
- Ordering constraint: **this milestone precedes Support Chat SC-M03** because existing Telegram topics require bindings before cutover.

## Explicit exclusions

- Implementing M05.2 / M06.4 / M07.2 / M09.1 / M10 as Universal Telegram chat product milestones (superseded by ADR-0037).
- Website widget, Support Chat Hub, availability SoR, or chat AI inside this plugin.
- Storing Telegram-native IDs inside Support Chat.
- Direct SQL into Support Chat tables.
- Dual-write of conversation SoR.
- Extracting/deleting/disabling legacy UT chat runtime (remains until SC-M03).
- Modifying M08.1, M08.2, Automations, digests, or ADR-0032.
- Changing plugin version or `db_version` in the documentation freeze (implementation may add adapter schema later under this milestone’s own code freeze rules).

## Architectural constraints

- ADR-0037 and Support Chat ADR-0005 (Contract v1) govern; do not duplicate Contract v1 text in full here.
- Escalated/support-channel traffic only — never ordinary AI-only chat (when Support Chat AI exists).
- Idempotency and retry per Contract v1 boundaries.
- Visitors never receive binding refs, remote IDs, credentials, or operator internals.

## Deliverables

Adapter module; binding schema/migrations (at implementation); Contract client/server callback wiring; topic ensure/backfill/deliver/notify; inbound webhook mapping to Support Chat operations; automated contract/idempotency/negative tests; documentation updates at closure.

## Acceptance criteria

- Handshake succeeds only against compatible Contract v1; mismatch disables channel features without breaking non-chat Telegram functions.
- Ensure/backfill/deliver/notify honour idempotency keys; duplicate Telegram updates do not duplicate Support Chat messages.
- No Support Chat table reads/writes via SQL from this plugin.
- Deactivating the adapter mid-conversation does not take down non-adapter Telegram bots/notifications; Support Chat Hub remains authoritative for tickets.
- Binding table can represent pre-existing topics for SC-M03 cutover tooling.

## Entry criteria

- ADR-0037 merged; Contract v1 pin reachable.
- Support Chat SC-M01/SC-M02 available for integration testing.
- Frozen plan committed; branch from freshly fetched `origin/main`.

## Exit criteria

- Acceptance criteria met; automated verification green; closure record committed; Product Owner acceptance.
- Ready for Support Chat SC-M03 migration to create bindings for existing topics.

## Frozen plan

[ut-adapter-m1-universal-support-chat-adapter-plan-v1.md](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md)
