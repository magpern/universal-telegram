# ADR-0038 — Support Chat ADR-0007 Pin and Signed Contract Client Follow-up

## Status

Accepted

## Context

ADR-0037 authorised UT Adapter M1 and pinned Support Chat Contract v1 (ADR-0005). UT Adapter M1 (`docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md`, plan v1, closure `docs/closure/ut-adapter-m1-universal-support-chat-adapter-closure.md`) implemented adapter persistence, discovery, binding storage, and fail-closed wiring, but ADR-0005 required adapter → Support Chat calls to be "authenticated, capability-checked" without specifying a mechanism. UT Adapter M1's own closure record recorded that gap precisely and ruled out inventing a mechanism on the consuming side: `SupportChatContractClient` (`src/SupportChatAdapter/Inbound/SupportChatContractClient.php`) unconditionally stubs every adapter → Support Chat call (`ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `report_channel_unavailable`, `report_delivery_failure`) to fail closed with reason `sc_authenticated_contract_unavailable`, explicitly documenting that a bare `rest_do_request()` current-user context is not that authentication boundary.

Support Chat has since frozen and merged the missing mechanism: **ADR-0007 — Contract v1 Mutual Signed Adapter Authentication Profile** (`magpern/universal-support-chat`, PR #5, merged to `main` at commit `8ee396d8b8edcbf526797c0a1f5741f3842df57a`). ADR-0007 specifies mutual Ed25519 request signing, administrator-authorized pairing, a precise canonical signed-request wire profile, and directional operation allow-lists — it does not implement any of it.

`docs/governance.md`'s freeze model ("No implementation code may precede the ADRs it relies on") requires this plugin to pin the mechanism and define its own follow-up scope before writing a signed client. This ADR does that. It amends no prior ADR's Decision text (ADR-0037 is not rewritten) and changes no runtime code.

## Decision

### 1. Pins (exact, immutable references)

| Item | Value |
|---|---|
| Support Chat Contract v1 (unchanged, ADR-0037 pin, reproduced for continuity) | SHA `dff2730e24b7d3f70f15f706305e12e14fdcc6c8` — `https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md` |
| Support Chat ADR-0007 (new) | SHA `8ee396d8b8edcbf526797c0a1f5741f3842df57a` — `https://github.com/magpern/universal-support-chat/blob/8ee396d8b8edcbf526797c0a1f5741f3842df57a/docs/adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md` |
| Auth profile identifier | `support-channel-contract-auth/v1` |

Universal Telegram does not copy ADR-0007's text into this repository, mirroring the existing ADR-0037 rule for ADR-0005.

### 2. Current stub behaviour is intentional, unchanged legacy

UT Adapter M1's persistence, binding table, settings/diagnostics tab, discovery client, and fail-closed wiring (`db_version` 30→31, `0.16.0`) remain valid and are **not** altered by this ADR. `SupportChatContractClient`'s unconditional fail-closed stubs are the deliberate, correct behaviour of a client written against an authentication mechanism that did not yet exist — they are legacy of the missing design, not a defect, and must be replaced **only** by an implementation of the ADR-0007 profile pinned above, never by a shared secret, a bare `rest_do_request()` context, or any other mechanism.

### 3. Signed-client follow-up scope (define now; implement later)

The follow-up slice authorised by this ADR — implemented under the existing UT Adapter M1 milestone, not a new milestone number — replaces `SupportChatContractClient`'s stubs and adds the outbound-acceptor verification side (`OutboundContractController`, `src/SupportChatAdapter/Outbound/`) with:

- **Key generation and custody.** Universal Telegram generates its own Ed25519 key pair locally and retains **only its own private key**, encrypted in this plugin's existing credential vault (`UniversalTelegram\Core\Security\CredentialVault`). The private key is never transmitted, logged, or exported.
- **Pairing.** Universal Telegram participates in ADR-0007's administrator-authorized mutual pairing flow: pairing is initiated only by a WordPress administrator holding both `universal_telegram_manage` (this plugin's existing capability constant) and Support Chat's `universal_support_chat_manage`. Universal Telegram records, for the Support Chat peer, only its public key, key ID, permitted-operation allow-list (§4 below), status, and non-sensitive pairing metadata — never a private key or shared secret. Idempotent re-pairing and confirm-before-replace on an active peer key follow ADR-0007 §2 exactly.
- **Outbound signing (adapter → Support Chat).** Universal Telegram signs every `SupportChatContractClient` call — `ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `update_assignment`, `update_operator_presence`, `report_channel_unavailable`, `report_delivery_failure` — using ADR-0007 §3's exact ten-line canonical string (auth-profile/domain-separation constant; Contract version; sender `universal-telegram`; audience `universal-support-chat`; sender key ID; timestamp; nonce; uppercase HTTP method; canonical route path; request-body SHA-256) and the `X-SC-*` header set it defines, signed with `sodium_crypto_sign_detached()` over the sender's private key.
- **Inbound verification (Support Chat → adapter).** `OutboundContractController`'s existing acceptors for `ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`, and `deliver_message` verify the incoming Support Chat signature — sender identity, audience, active key, per-operation allow-list membership, timestamp window, nonce uniqueness, and body hash — before dispatch, per ADR-0007 §3–§4. The existing `universal_telegram_support_chat_adapter_rest_authorized` filter (currently hard default-deny) is superseded as the authorization boundary for these routes by ADR-0007 signature verification; the filter's default-deny behaviour is retained as a defense-in-depth gate, not replaced by a weaker check.
- **Rotation, revocation, expiry, replay, and uniform denial.** Universal Telegram implements key rotation (new local key pair; re-pairing required on Support Chat's side before that key succeeds again), revocation (Support Chat's revoked key is rejected locally the moment the peer record shows it revoked), expiry per the recorded pairing policy, nonce-replay rejection, and the uniform fail-closed `401 {"ok": false, "reason": "contract_auth_failed"}` denial class — exactly as ADR-0007 §2–§3 and §5 specify, with no plugin-specific variation.
- **No runtime code in this task.** This ADR and its accompanying plan v2 define the follow-up scope; none of the above is implemented here.

### 4. Directional allow-lists (unchanged from Contract v1/ADR-0005, reproduced for the follow-up's scope clarity)

- **Adapter → Support Chat** (Universal Telegram signs, Support Chat verifies): `ingest_operator_reply`, `claim`, `release`, `resolve`, `reopen`, `update_assignment`, `update_operator_presence`, `report_channel_unavailable`, `report_delivery_failure`.
- **Support Chat → adapter** (Support Chat signs, Universal Telegram verifies): `ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`, `deliver_message`.

### 5. Implementation sequence (locked)

1. Support Chat SC-M03 work package 0: authenticated Contract server, pairing authority, peer-key/nonce-replay stores, and signature verification (Support Chat repository; not this repository).
2. **This ADR's follow-up:** Universal Telegram signed Contract client — replaces `SupportChatContractClient`'s stubs, adds pairing and inbound-acceptor verification (§3 above).
3. End-to-end authenticated interoperability tests across both plugins (pairing, rotation, revocation, replay rejection, uniform fail-closed denial, both call directions).
4. Only then, Support Chat SC-M03's one-shot legacy migration engine and controlled cutover.
5. Only after SC-M03 acceptance: this plugin's legacy Conversations tab, AI tab, chat widget, and chat settings decommission (a separate future task, out of scope here and for this ADR).

**No Universal Telegram signed-client implementation code may begin until this ADR is merged to `main`. No SC-M03 migration/cutover code may begin until both this ADR and Support Chat's SC-M03 work package 0 are merged.**

## Alternatives

- **Leave the stub as-is and implement an ad hoc authentication check when SC-M03 needs it** — rejected: `docs/governance.md`'s freeze model forbids implementation code preceding the ADR it relies on, and the UT Adapter M1 closure record already ruled out inventing a mechanism on this side.
- **Copy ADR-0007's full text into this repository** — rejected: mirrors the existing ADR-0037 rule against duplicating Contract v1; pin SHA/URL only, drift risk otherwise.
- **Treat this as a plan amendment only, with no new ADR** — rejected: pinning a new cross-repository security-boundary mechanism and authorising a specific follow-up implementation scope is exactly the class of change `docs/governance.md`'s "Changing a frozen milestone charter" rule requires a new ADR for.
- **Fold this into a rewritten ADR-0037** — rejected: ADR-0037 is Accepted and immutable (`docs/adr/README.md` Immutability rule); a changed decision is always a new ADR.

## Consequences

- Universal Telegram's signed-client follow-up (§3) now has a precise, pinned target to implement against, closing the gap the UT Adapter M1 closure record identified.
- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/adr/README.md`, the UT Adapter M1 charter, and a new UT Adapter M1 plan v2 are updated in this same freeze to reference this ADR.
- `docs/adr/0037-support-chat-extraction-supersession-and-optional-adapter-consumer.md` is unchanged; this ADR supplements it, it does not supersede it.
- No plugin version, `db_version`, release, tag, or deployment change.

## Security and privacy impact

- Fixes the previously-open question of how UT Adapter M1's Contract calls will be authenticated, closing the "public REST bypass / generic capability alone / bare `rest_do_request()` context" gaps the UT Adapter M1 closure record identified.
- Universal Telegram's private signing key never leaves this plugin; only Support Chat's public key and non-sensitive pairing metadata are ever stored here.
- No Telegram-native IDs, credentials, bot/topic identifiers, or delivery-queue state are affected by this ADR; ADR-0002/ADR-0037's storage boundaries are unchanged.
- Preserves the existing rule that plaintext exists only in memory during an authorised delivery/backfill call; this ADR governs authenticating the call, not the payload's eligibility or handling.

## Affected Documents/Milestones

- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/adr/README.md`
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (additive amendment), `docs/milestones/README.md`
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v1.md` (superseded by plan v2; v1 retained unedited), `docs/plans/README.md`
- ADR-0037 (referenced, unedited)
- Support Chat repository: ADR-0005 (referenced, unedited), ADR-0007 (pinned), SC-M03 charter and plan v2 (external; unedited by this ADR)

## Compatibility/Migration Impact

- No runtime code, schema, plugin version, release, tag, or deployment change in this freeze.
- UT Adapter M1's signed-client implementation (§3), and SC-M03 migration/cutover, remain unimplemented until the sequence in §5 is followed.
