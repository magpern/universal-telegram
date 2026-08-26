# UT Adapter M1 — Universal Support Chat Adapter

## Status

Implemented with fail-closed Contract boundary (closure: `docs/closure/ut-adapter-m1-universal-support-chat-adapter-closure.md`). **Signed Contract client follow-up (ADR-0038, §0 below):** work packages 1–5 of plan v2 implemented — real ADR-0007 mutual Ed25519 signing/verification, administrator pairing, and nonce-replay housekeeping (closure: `docs/closure/ut-adapter-m1-signed-contract-client-closure.md`). **Work package 6 (joint interoperability tests) is also implemented** — joint authenticated interoperability proven against a live Support Chat authenticated Contract server (closure: `docs/closure/ut-adapter-m1-wp6-interop-gate-closure.md`). **Legacy export boundary follow-up (ADR-0039, §0b below):** pinned, not yet implemented — authorises this plugin's `LegacyExportServiceV1`, which Support Chat's SC-M03 legacy migration engine (work packages 3–4) depends on.

## Dependencies

- This documentation amendment merged to `main` (ADR-0037).
- Support Chat **Contract v1** pinned exactly to:
  - Commit SHA: `dff2730e24b7d3f70f15f706305e12e14fdcc6c8`
  - Canonical URL: `https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md`
- Support Chat **SC-M01** and **SC-M02** surfaces available for Contract callbacks and Hub/conversation identity (implemented in `universal-support-chat`, not in this repository).
- Signed-client follow-up additionally depends on Support Chat **ADR-0007** (pinned in §0 below).
- Legacy export boundary follow-up additionally depends on Support Chat **ADR-0008** (pinned in §0b below).

## 0. Signed Contract client follow-up (ADR-0038)

Added by the `docs/ut-contract-v1-auth-profile-pin` documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Included scope, §Explicit exclusions, or the acceptance/entry/exit criteria below.

Support Chat ADR-0007 fixes the authentication mechanism Contract v1 (ADR-0005) required but never specified — mutual Ed25519 request signing, administrator-authorized pairing, auth profile `support-channel-contract-auth/v1`. Pin (Support Chat `main`, merged PR #5):

- Commit SHA: `8ee396d8b8edcbf526797c0a1f5741f3842df57a`
- Canonical URL: `https://github.com/magpern/universal-support-chat/blob/8ee396d8b8edcbf526797c0a1f5741f3842df57a/docs/adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md`

`SupportChatContractClient`'s current unconditional fail-closed stubs are deliberate legacy of the missing mechanism, not a defect. The authorised follow-up — replacing those stubs, adding pairing, and verifying inbound Support Chat signatures at `OutboundContractController` — is scoped in full in ADR-0038 (`docs/adr/0038-support-chat-adr-0007-pin-and-signed-contract-client-follow-up.md`) and this milestone's frozen plan: [v2](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md), superseding [v1](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md).

**No signed-client implementation code may begin until ADR-0038 is merged. No SC-M03 migration/cutover code may begin until both ADR-0038 and Support Chat's SC-M03 work package 0 (authenticated Contract server) are merged.**

## 0b. Legacy export boundary follow-up (ADR-0039)

Added by the `docs/support-chat-adr-0008-legacy-export-boundary-pin` documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Included scope, §Explicit exclusions, or the acceptance/entry/exit criteria below.

The signed Contract client follow-up (§0 above) is complete, including its joint interoperability gate (work package 6). Support Chat's SC-M03 legacy migration engine (work packages 3–4: batch migrator/backfill and validators) now depends on one more mechanism this plugin must supply: a narrow, versioned, in-process, WP-CLI-only legacy-data export interface, `LegacyExportServiceV1`, pinned and scoped by [ADR-0039](../adr/0039-support-chat-adr-0008-pin-and-legacy-export-boundary-follow-up.md), which pins Support Chat's own ADR-0008:

- Commit SHA: `7546d43be66f8e3b2f179f03a1c81c9aadef59db`
- Canonical URL: `https://github.com/magpern/universal-support-chat/blob/7546d43be66f8e3b2f179f03a1c81c9aadef59db/docs/adr/0008-legacy-export-boundary-and-migration-authority-model.md`

This plugin's obligation is limited to `LegacyExportServiceV1` and its own tests (ADR-0039 §2, §4) — legacy migration orchestration, Support Chat target writes, quiescence, binding creation, cutover, soak/rollback, AI migration, and this plugin's own legacy-UI decommission are all explicitly out of scope for this follow-up. This plugin also carries a forward commitment (ADR-0039 §3) to Support Chat's frozen `QuiescenceStateProvider` interface for whenever this plugin's own future quiescence work package is scoped — not implemented here.

This milestone's frozen plan is extended by [plan v3](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md), superseding [plan v2](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md) (retained unedited) only to add the `LegacyExportServiceV1` work package.

**No `LegacyExportServiceV1` implementation code may begin until ADR-0039 is merged. Support Chat's SC-M03 work packages 3–4 migration-engine code may not begin until both ADR-0039 and Support Chat's own ADR-0008 are merged to their respective `main` branches.**

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

[ut-adapter-m1-universal-support-chat-adapter-plan-v3.md](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md) (supersedes [v2](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md), which supersedes [v1](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md); v1 and v2 retained unedited per `docs/plans/README.md`/`docs/governance.md`)
