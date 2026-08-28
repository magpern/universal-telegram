# UT Adapter M1 — Universal Support Chat Adapter

## Status

Implemented with fail-closed Contract boundary (closure: `docs/closure/ut-adapter-m1-universal-support-chat-adapter-closure.md`). **Signed Contract client follow-up (ADR-0038, §0 below):** work packages 1–5 of plan v2 implemented — real ADR-0007 mutual Ed25519 signing/verification, administrator pairing, and nonce-replay housekeeping (closure: `docs/closure/ut-adapter-m1-signed-contract-client-closure.md`). **Work package 6 (joint interoperability tests) is also implemented** — joint authenticated interoperability proven against a live Support Chat authenticated Contract server (closure: `docs/closure/ut-adapter-m1-wp6-interop-gate-closure.md`). **Legacy export boundary follow-up (ADR-0039, §0b below):** work package 8 (`LegacyExportServiceV1`) implemented (closure: `docs/closure/ut-adapter-m1-legacy-export-service-closure.md`; Product Owner acceptance pending) — which Support Chat's SC-M03 legacy migration engine (work packages 3–4) depends on. **Legacy binding preparation follow-up (ADR-0041, §0c below):** work package 9 (`LegacyBindingImportServiceV1`, the `prepared` status, and the lock-scoped quiescence assertion) implemented and Product Owner accepted (closure: `docs/closure/ut-adapter-m1-legacy-binding-import-service-closure.md`). **Final-cutover follow-up (ADR-0042, §0d below): documentation frozen and Product Owner accepted** — design for work package 10 (cutover state machine, activation/compensation saga, cohort-aware replay, incident record, lifecycle cross-talk fix) is frozen; implementation authorized within ADR-0042/Support Chat ADR-0010's stated scope; still no production quiescence, cutover, route switch, soak, rollback, or deletion.

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

**Implemented**: `src/SupportChatAdapter/Migration/LegacyExportServiceV1.php`, per this plan's work package 8 — see `docs/closure/ut-adapter-m1-legacy-export-service-closure.md` for the full accounting. Product Owner acceptance and merge are the only remaining steps before Support Chat's SC-M03 work packages 3–4 may begin.

This milestone's frozen plan is extended by [plan v3](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md), superseding [plan v2](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md) (retained unedited) only to add the `LegacyExportServiceV1` work package.

**No `LegacyExportServiceV1` implementation code may begin until ADR-0039 is merged. Support Chat's SC-M03 work packages 3–4 migration-engine code may not begin until both ADR-0039 and Support Chat's own ADR-0008 are merged to their respective `main` branches.**

## 0c. Legacy binding preparation follow-up (ADR-0041)

Added by the `docs/support-chat-adr-0009-legacy-binding-preparation-pin` documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Included scope, §Explicit exclusions, or the acceptance/entry/exit criteria below.

Support Chat's SC-M03 work packages 2–4 (§0b) are complete and Product Owner accepted. Support Chat's SC-M03 work package 5 (binding preparation for existing Telegram topics) now depends on three new capabilities this plugin must supply: a new, non-routing `prepared` binding status; a narrow, versioned, in-process, WP-CLI-only binding-write interface, `LegacyBindingImportServiceV1`; and a lock-scoped quiescence assertion usable from a second caller besides the existing webhook buffer-vs-process and replay-to-idle paths — pinned and scoped by [ADR-0041](../adr/0041-support-chat-adr-0009-pin-and-legacy-binding-preparation-follow-up.md), which pins Support Chat's own ADR-0009:

- Commit SHA: `590b53ba898aa4054ec65c65965c152a3612149b`
- Canonical URL: `https://github.com/magpern/universal-support-chat/blob/590b53ba898aa4054ec65c65965c152a3612149b/docs/adr/0009-legacy-binding-preparation-boundary-and-non-routing-prepared-status.md`

This plugin's obligation is limited to `LegacyBindingImportServiceV1`, the `prepared` status, the lock-scoped quiescence assertion, and their tests (ADR-0041 §2, §3, §6) — activation (`prepared → active`), any modification to `BindingImportCommand`, Support Chat-side candidate identification/schema/CLI, and cutover/soak/rollback are all explicitly out of scope for this follow-up. ADR-0041 §4 also records that this follow-up supersedes, as a factual matter, ADR-0039 §4's earlier speculation that work package 5 would reuse the existing `ensure_channel_case`/pairing infrastructure — direct source verification found that path shares `BindingImportCommand`'s unconditional `status = 'active'` write, which ADR-0009/ADR-0041 exist to avoid.

This milestone's frozen plan is extended by [plan v4](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v4.md), superseding [plan v3](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md) (retained unedited) only to add the `LegacyBindingImportServiceV1` work package (WP9).

**No `LegacyBindingImportServiceV1` implementation code may begin until ADR-0041 is merged. Support Chat's SC-M03 work package 5 code may not begin until both ADR-0041 and Support Chat's own ADR-0009 are merged to their respective `main` branches.**

## 0d. Final-cutover state machine, activation, and incident ownership (ADR-0042)

Added by the `docs/support-chat-adr-0010-final-cutover-freeze` documentation freeze, additive to this charter (`docs/governance.md` "Changing a frozen milestone charter"). Does not alter §Included scope, §Explicit exclusions, or the acceptance/entry/exit criteria below.

Support Chat's SC-M03 work packages 2–5 (§0b, §0c) are complete and Product Owner accepted. Support Chat's SC-M03 final-cutover package now depends on capabilities this plugin must supply: a new cutover-orchestration state machine layered above (not replacing) the existing ADR-0040 quiescence machine; a corrected, monotonic-CAS `activate_prepared()`/`revert_activation()` saga for `prepared → active`, including whole-cohort preflight and automatic in-run compensation; a cohort-aware amendment to the existing deferred-update replay loop, unifying scan-and-drain into one authoritative barrier; this plugin's own incident record for pre-dispatch failures (decrypt/parse/unsupported-command/unmapped-sender/provenance-conflict), including the narrowly-scoped Product-Owner-approved terminal-acknowledgement exception; and a resolution of the `maybe_mark_topic_unavailable()` live-webhook cross-talk risk against the adapter bridge — pinned and scoped by [ADR-0042](../adr/0042-support-chat-adr-0010-pin-and-final-cutover-state-machine.md), which pins Support Chat's own ADR-0010:

- Commit SHA: `be7461544a39c7ad074164d21e3c1b04c71f2fc2` (Support Chat PR #16, merged)
- Canonical URL: `https://github.com/magpern/universal-support-chat/blob/be7461544a39c7ad074164d21e3c1b04c71f2fc2/docs/adr/0010-final-cutover-handoff-contract-and-cohort-activation.md`

This plugin's obligation is limited to the cutover state machine, the activation/compensation saga, the cohort-aware replay amendment, the incident record and its CLI, and the `maybe_mark_topic_unavailable()` reordering, and their tests (ADR-0042 §1–§5) — `InboundAdapterBridge`, `DeliverMessageService`, and `try_handle()`'s own routing gate require **no code change**, confirmed as a pre-existing, tested code property, not an assumption (ADR-0042 §"Required source verification" item 1). Support Chat-side candidate identification, the handoff-map schema, and the Contract v1 payload extension are Support Chat's own obligation under its ADR-0010, not this plugin's.

**No production quiescence, cutover, route switch, soak, rollback, or deletion is authorized by ADR-0042 or this amendment.**

This milestone's frozen plan is extended by [plan v5](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v5.md), superseding [plan v4](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v4.md) (retained unedited) only to add the final-cutover work package (WP10).

**No final-cutover implementation code may begin until ADR-0042 is merged. Support Chat's SC-M03 final-cutover implementation may not begin until both ADR-0042 and Support Chat's own ADR-0010 are merged to their respective `main` branches.** **Both conditions are now satisfied** — ADR-0042 merged `15067671c5234cce975e939150a631bb8f9e56c8`; Support Chat ADR-0010 merged `be7461544a39c7ad074164d21e3c1b04c71f2fc2` — **and Product Owner acceptance of the freeze is recorded** (Support Chat's `docs/decisions/sc-m03-final-cutover-po-decisions.md`, "Documentation-freeze acceptance"). Implementation is authorized only within those two documents' own stated scope and safety boundaries; production quiescence, cutover, route switch, soak, rollback, and deletion each remain separately gated.

**WP10 implementation and Product Owner acceptance are complete** (closure: `docs/closure/ut-adapter-m1-final-cutover-closure.md`, including the mutual-pairing interop-suite correction addendum and "Product Owner acceptance (final)"). That acceptance explicitly states it "does not authorize a DEV or production quiescence window, migration, cohort activation, route switch, cutover, deployment, soak, rollback, deletion, release, or tag" and names "a separately planned, disposable DEV rehearsal" as the next possible activity. **Planning-only:** that rehearsal is now specified — primary operator runbook [`docs/plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md`](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v1.md); Support Chat companion plan and Product Owner decision record in the Support Chat repository. Tier 1 (a required disposable automated operational-sequence validation in the container/PHPUnit harness) is **authorized** — Product Owner Approval A is recorded (`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval.md`); it exercises only fresh throwaway checkouts and the disposable harness with synthetic fixtures and zero Telegram traffic. Tier 2 (the actual disposable DEV rehearsal) is blocked on an isolated full-WordPress instance (B1) and dedicated non-production Telegram bot/supergroup/topics (B2), and pending Approval B, which cannot take effect until Tier 1 passes and B1/B2 are proven resolved.

**Tier 1 execution was attempted (2026-08-27) and HALTED at the UT→SC deferred-update handoff phase by finding F1** — a production-behaviour gap (`CutoverReplayDispatcher`/`InboundAdapterBridge` send `$binding->binding_uuid()` as `channel_case_ref`, but Support Chat's `ContractOperationDispatcher::resolve_conversation()` resolves `channel_case_ref` only as its own `conversation_uuid`, and every real binding-creation path mints an independent `binding_uuid`). The disposable harness is validated (baseline interop 42 tests / 580 assertions; new characterization test 2 tests / 41 assertions), **no production code was changed, and no bypass or fixture shortcut was used** — see closure [`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-closure.md). F1 is raised for separate ADR-level review (it touches the Contract v1 wire contract and ADR-0042/ADR-0010 §4). **Tier 2 is now blocked on B1, B2, and F1.**

**F1 resolution — implementation ACCEPTED 2026-08-27** (`docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-approval.md`; Support Chat companion in that repo's decision record item 7): [ADR-0043](../adr/0043-support-chat-adr-0011-pin-and-channel-case-ref-conversation-uuid-correction.md) (**Accepted**; pins Support Chat ADR-0011; amends ADR-0042 §3 and §4/§5) and [`docs/plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md`](../plans/sc-m03-final-cutover-f1-channel-case-ref-remediation-plan-v1.md) freeze the correction: `channel_case_ref` denotes the Support Chat conversation/case UUID; the adapter sends `ChannelBinding::support_conversation_uuid()` (existing `NOT NULL`/`UNIQUE` column) and keeps `binding_uuid` UT-local, off the wire; a `404 not_found` after an active binding is selected becomes a new closed incident `unresolved_case_reference` (fail-closed, no infinite retry). No schema/`db_version` change, no new Contract operation, no SC-side binding→conversation resolver. Identifier collapse (option (c)) rejected by Product Owner direction.

**F1 implementation and closure MERGED 2026-08-27** — UT #53 `7d4cc4fecb97f862721cea0fec427ade26b46ea7` / closure #54 `32f17ea904a33cdd1f9b0225ba9638f95a09d883`; Support Chat #26 `9144cb1e2362c2be8d4c74f1461bba7ffe236575` (comment corrections only) / closure #27 `5d81b5b7795ee50f3a79e535a483d7677b36d1c0`. Closure: [`docs/closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md`](../closure/sc-m03-final-cutover-f1-identity-correction-implementation-closure.md). The real dual-plugin interop suite (merged UT `main` + SC branch, fresh disposable DB) passed **OK (47 tests, 722 assertions)** on both supported WP/PHP variants, including the inverted F1 characterization test and the new `unresolved_case_reference` / `handoff_rejected` / wire-identity cases. No schema/`target_version()`/`db_version`/plugin-version change; no new Contract operation; no SC-side resolver.

**Planning-only:** [DEV rehearsal runbook **v2**](../plans/sc-m03-final-cutover-dev-rehearsal-plan-v2.md) supersedes v1, pins the immutable Product-Owner-approved Tier 1 execution baselines UT `6eed0228286e84b4e56e0119f242b483f138a58e` / SC `4f833c3344c3cff2adcc0227f93832c0c3a4427a` (operators fetch origin, verify these exact commits exist, and check them out before execution; runtime trees byte-identical to the F1 implementation commits `7d4cc4f` / `9144cb1`, documentation only added since; future documentation merges must not alter the baseline without a new PO approval), and revises only the F1-invalidated portions (wire identity, Run 1 handoff fixture/assertions, the exhaustive fail-closed classifier referenced by Runs 2 and 3, a new Run 1 F1-correction gate, new Run 3 fail-closed incident scenarios); all v1 safety boundaries, evidence/redaction/teardown requirements, the Tier 1/Tier 2 distinction, and blockers B1–B5 are carried forward. F1 is **no longer a code blocker**. **No rehearsal has run under v2.** A Tier 1 re-attempt requires the **proposed, unsigned** Approval A addendum ([`docs/closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md`](../closure/sc-m03-final-cutover-dev-rehearsal-tier1-approval-addendum.md)) — the original Approval A was consumed by the halted first attempt. Tier 2 stays blocked on B1 and B2 and pending Approval B.

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

[ut-adapter-m1-universal-support-chat-adapter-plan-v4.md](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v4.md) (supersedes [v3](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md), which supersedes [v2](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md), which supersedes [v1](../plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md); v1, v2, and v3 retained unedited per `docs/plans/README.md`/`docs/governance.md`)
