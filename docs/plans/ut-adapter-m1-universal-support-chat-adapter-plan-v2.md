# UT Adapter M1 — Universal Support Chat Adapter — Implementation Plan v2

Supersedes [ut-adapter-m1-universal-support-chat-adapter-plan-v1.md](ut-adapter-m1-universal-support-chat-adapter-plan-v1.md) (`docs/plans/README.md`; `docs/governance.md` freeze model: v1 is retained, unedited, permanently; this file is the frozen plan going forward for the signed-client follow-up slice).

## 1. References

- Charter: `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (§0 Signed Contract client follow-up)
- Authorising ADRs: `docs/adr/0037-support-chat-extraction-supersession-and-optional-adapter-consumer.md` (unchanged), `docs/adr/0038-support-chat-adr-0007-pin-and-signed-contract-client-follow-up.md` (new)
- **Canonical Contract v1 (do not duplicate in full):** SHA `dff2730e24b7d3f70f15f706305e12e14fdcc6c8` — `https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md`
- **Canonical authentication profile (do not duplicate in full):** Support Chat ADR-0007, SHA `8ee396d8b8edcbf526797c0a1f5741f3842df57a` — `https://github.com/magpern/universal-support-chat/blob/8ee396d8b8edcbf526797c0a1f5741f3842df57a/docs/adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md`

## 2. Repository findings (plan-freeze time)

- UT Adapter M1 v1 is implemented and merged (PR #32): binding storage, discovery, settings/diagnostics, and outbound acceptors (`OutboundContractController`) exist; every acceptor route requires Compatible discovery **and** an explicit authenticated Contract assertion filter (`universal_telegram_support_chat_adapter_rest_authorized`, default deny) before any mutation.
- `SupportChatContractClient` (`src/SupportChatAdapter/Inbound/SupportChatContractClient.php`) unconditionally returns `503 sc_authenticated_contract_unavailable` for every adapter → Support Chat call; this was deliberate, not a defect, pending exactly the mechanism ADR-0007 now specifies.
- `UniversalTelegram\Core\Security\CredentialVault` already exists and is the correct place to store this plugin's own Ed25519 private key (new use of an existing mechanism, not a new one).
- Support Chat's authenticated Contract server (SC-M03 work package 0) does not exist yet; this plan's client cannot be exercised against a live Support Chat server until that server exists. Development and testing may proceed with fixtures/mocks implementing ADR-0007's wire profile.

## 3. Assumptions and open questions

### Assumptions

- Support Chat implements SC-M03 work package 0 (the authenticated Contract server) before joint end-to-end interoperability tests can run; this plan's own automated tests use local fixtures/mocks of that server in the meantime.
- `universal_telegram_manage` remains this plugin's manage capability constant; `universal_support_chat_manage` remains Support Chat's, per ADR-0007 §2's both-capabilities pairing rule.
- No plugin version or `db_version` bump is required solely for key/peer storage if it fits within schema already reserved for Adapter M1's own tables at implementation time; a schema addition (peer-key table, nonce-replay table) is expected and will be scoped precisely in a future v3 revision of this plan at code-freeze time if it changes shape from what §5 describes here.

### Open questions (resolve at implementation without changing ADR-0007's design)

- Exact WordPress admin screen/REST endpoints for the pairing UI (follow ADR-0007 §2's requirements; specific route names are an implementation detail, not an architecture decision).
- Whether the nonce-replay store reuses an existing UT table pattern (e.g. the outbound delivery-idempotency table's structure) or is a new dedicated table — a schema decision for the implementation plan revision at code-freeze, not this documentation freeze.

## 4. Architectural decisions

| Decision | Choice | Alternative rejected |
|---|---|---|
| Authentication mechanism | ADR-0007 mutual Ed25519 signing, pinned exactly | Inventing a mechanism in this plugin (ruled out by UT Adapter M1 closure record and ADR-0038) |
| Private key storage | This plugin's own `CredentialVault`, new context/entry | A new bespoke secret-storage mechanism |
| Peer public key storage | New non-secret peer-record table/option, this plugin's own schema | Reusing binding table columns (different lifecycle: pairing is per-plugin-pair, bindings are per-conversation) |
| `universal_telegram_support_chat_adapter_rest_authorized` filter | Retained as defense-in-depth default-deny gate alongside ADR-0007 signature verification | Replacing it outright with signature verification alone |
| Rotation/revocation propagation | Never silent; peer must re-pair after the other side rotates (ADR-0007 §2) | Auto-trust a newly-presented key without re-pairing |

## 5. Directory, namespace, schema, and API impact

### Planned modules (names illustrative until code freeze)

- `src/SupportChatAdapter/Inbound/SupportChatContractClient.php` — replace the unconditional-`unavailable()` bodies with ADR-0007 §3 request signing and dispatch; add signature-response handling.
- `src/SupportChatAdapter/Outbound/OutboundContractController.php` (and its acceptors) — add ADR-0007 §3 signature verification ahead of the existing default-deny filter check, for `ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`, `deliver_message`.
- New: a pairing subdomain under `SupportChatAdapter` (illustrative — e.g. `SupportChatAdapter/Pairing/`) — key generation, peer-record storage, pairing admin screen/endpoints, rotation/revocation actions.
- New: a nonce-replay store, scoped to Contract authentication only (illustrative — e.g. `SupportChatAdapter/Auth/NonceReplayRepository`).

### Peer-record schema (logical, not yet a migration)

Minimum columns/concepts, this plugin's own table:

- peer identity (`universal-support-chat`)
- peer public key (raw 32 bytes) and key ID
- permitted-operation allow-list (Support Chat → adapter operations only, §1 Contract v1 allow-list)
- status (`unpaired|paired_disabled|active|degraded|revoked|expired|incompatible`, ADR-0007 §2)
- created time, last-rotated time, expiry policy, last-successful-call time
- audit event trail (paired/replaced/revoked/rotated/expired) — never key material

### Nonce-replay schema (logical, not yet a migration)

- `(sender, key_id, nonce)` tuple, recorded time, purge-eligible after the ADR-0007 §3 retention window (600 seconds) — never a request body or Contract payload field.

### API surface

- Outbound (this plugin → Support Chat): `SupportChatContractClient` methods gain ADR-0007 headers/signature; no new public routes.
- Inbound (Support Chat → this plugin): existing `OutboundContractController` routes gain signature verification; no new routes, no relaxation of the existing default-deny filter.
- New admin-only pairing routes/screen (both-capability-gated, per ADR-0007 §2) — exact shape deferred to code-freeze plan revision.

## 6. Security and privacy impact

- Private key never leaves this plugin; stored only in `CredentialVault`.
- Peer public keys and pairing metadata are non-secret but must never be colocated with, or logged alongside, vault material.
- Nonce-replay and pairing-audit records never contain message bodies, notes, credentials, or Telegram-native secrets.
- Signature verification failure and the existing default-deny filter both apply; a call must pass both, not either.
- No change to existing rules: no Telegram tokens/topic IDs in Support Chat; no Support Chat SQL from this plugin; visitors never see binding refs, remote IDs, or operator internals.

## 7. Test and CI impact

- **New:** signed-request unit tests — valid signature accepted; unsigned/forged/replayed/expired/wrong-audience/wrong-operation/revoked-key/unpaired-key requests rejected with the uniform ADR-0007 §3 denial, no domain mutation attempted.
- **New:** pairing tests — idempotent re-pair; confirm-before-replace on active-key replacement; rotation invalidates the prior key ID; revocation takes effect immediately; audit trail never contains key material.
- **New:** local fixture/mock Support Chat Contract server exercising both call directions, standing in for SC-M03 work package 0 until it exists.
- **Deferred (external gate):** true end-to-end interoperability tests against a live Support Chat authenticated Contract server — cannot run until SC-M03 work package 0 is implemented in the Support Chat repository; tracked as this plan's own definition-of-done blocker, not simulated as passing here.
- `bin/docker/composer.sh run-script check-doc-links` remains green.
- No requirement to modify Automations/M08.x tests or non-chat Telegram test coverage.

## 8. Work packages (execution order)

### WP1 — Key generation and vault storage

- Files: Ed25519 key-pair generation on first activation/settings save; storage via `CredentialVault`.
- Validation: key persists across requests; never appears in diagnostics/logs.
- Acceptance: this plugin has a stable key ID derivable per ADR-0007 §3's key-ID format.

### WP2 — Pairing subdomain

- Files: peer-record storage; admin pairing screen/endpoints gated on both `universal_telegram_manage` and Support Chat's capability per ADR-0007 §2; idempotent pair, confirm-before-replace, revoke, rotate actions.
- Validation: idempotency tests; replace requires explicit confirmation; revoke takes effect immediately.
- Acceptance: peer states (`unpaired|paired_disabled|active|degraded|revoked|expired|incompatible`) are all reachable and surfaced in the existing adapter settings/diagnostics tab.

### WP3 — Outbound signing (`SupportChatContractClient`)

- Files: replace stub bodies with ADR-0007 §3 canonical-string construction, signing, header assembly, and response handling for all nine adapter → Support Chat operations.
- Validation: canonical string byte-for-byte matches ADR-0007 §3 against fixture vectors; signature verifies against the plugin's own public key.
- Acceptance: every `SupportChatContractClient` method sends a correctly-signed request instead of returning the unconditional stub.

### WP4 — Inbound signature verification (`OutboundContractController`)

- Files: signature/nonce/timestamp/allow-list verification ahead of existing acceptors and the existing default-deny filter, for `ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`, `deliver_message`.
- Validation: forged/replayed/stale/wrong-audience/wrong-operation requests rejected uniformly; valid signed requests reach the existing acceptor logic unchanged.
- Acceptance: no acceptor is reachable without both a valid ADR-0007 signature and the existing filter's approval.

### WP5 — Nonce replay store and housekeeping

- Files: replay-store repository; scheduled cleanup reusing this plugin's existing cron/cleanup pattern.
- Validation: replayed nonce within the retention window rejected; expired entries purged without affecting still-valid ones.
- Acceptance: replay store holds only the fields ADR-0007 §3 permits.

### WP6 — Joint interoperability tests (external gate)

- Files: coordinated test suite exercised against a live Support Chat authenticated Contract server (SC-M03 work package 0), once it exists.
- Validation: full ADR-0007 matrix — both call directions, pairing, rotation, revocation, replay rejection, uniform denial.
- Acceptance: this work package's evidence is a **hard gate** for SC-M03 migration/cutover work packages, per Support Chat plan v2 §8 work package 1.

### WP7 — Docs and closure

- Files: closure record for this follow-up slice; architecture/master-plan touch-ups if implementation diverges from this plan's illustrative names.
- Validation: `check-doc-links` green.
- Acceptance: Product Owner closure of the follow-up slice.

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Implementing against a moving authentication design | ADR-0007 is Accepted and immutable; any gap found during implementation returns to architecture review for a new ADR, not an ad hoc code decision |
| Testing only against local fixtures, missing a real interop bug | WP6 is an explicit hard gate before SC-M03 migration work; not treated as optional or deferred indefinitely |
| Weakening the existing default-deny filter while adding signature verification | WP4 explicitly requires both checks to pass, not either |
| Private key exposure via logs/diagnostics | ADR-0007 §2's "never surfaces" rule carried into WP1/WP2 acceptance criteria |

## 10. Explicit out-of-scope list

- Changing ADR-0005 or ADR-0007's design (this plan implements them, does not redesign them)
- SC-M03 migration/cutover implementation (Support Chat repository)
- Support Chat runtime code in this repository
- M05.2 / M06.4 / M07.2 / M09.1 / M10 implementation in this plugin (unchanged, ADR-0037)
- Changing M08.1 / M08.2 / ADR-0032 / Automations / digests product behaviour
- Removing the legacy Conversations tab, AI tab, chat widget, or chat settings (post-SC-M03-acceptance decommission, separate future task)
- Plugin release/tag/deployment
- Any runtime code in this documentation-freeze task itself (this plan is the freeze; WP1–WP7 above are future work)

## 11. Definition of done

1. WP1–WP5 implemented and unit/integration-tested against local fixtures of the ADR-0007 wire profile.
2. WP6 joint interoperability tests pass against a live Support Chat authenticated Contract server.
3. `SupportChatContractClient` no longer returns unconditional stubs; every call is genuinely signed and dispatched.
4. `OutboundContractController` acceptors require both a valid ADR-0007 signature and the existing default-deny filter.
5. No plaintext key material in diagnostics, logs, audit records, or REST responses.
6. Closure record committed; Product Owner acceptance recorded.
7. Only then does Support Chat's SC-M03 migration/cutover work proceed past its own work package 1 gate.
