# Closure Record — UT Adapter M1 Signed Contract Client (ADR-0007/ADR-0038 follow-up)

## Status

Implementation complete for work packages 1–5 of
`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md`.
Closed for implementation review (awaiting Product Owner acceptance / merge)
— per `docs/governance.md`, the Implementation Agent cannot self-certify
closure.

- Frozen plan commit SHA: `6846b80` (`docs(adapter-m1): amend charter and
  freeze plan v2 for signed client`)
- Authorising ADRs frozen in the same lineage: `docs/adr/0037-...md`
  (unchanged), `docs/adr/0038-support-chat-adr-0007-pin-and-signed-contract-client-follow-up.md`
- UT `main` branch point for this implementation: `51ab1aa` (merge of PR #33,
  which carried the ADR-0038 freeze and plan v2 onto `main`)
- Support Chat PR #6 merge SHA (SC-M03 work package 0, the authenticated
  Contract v1 server this client and verifier interoperate with by design):
  `2f748168f591bec551a740a5060d394bc6e29ba3`

## Accurate scope statement

**`SupportChatContractClient` no longer returns an unconditional stub: it
builds and Ed25519-signs the exact ADR-0007 §3 ten-line canonical request
and dispatches it, failing closed at every named gate. `OutboundContractController`
now requires a valid ADR-0007 signature from Support Chat's currently
paired, active peer key, in addition to (never instead of) the pre-existing
default-deny discovery/filter gate. Administrator pairing UX, key custody,
and the nonce-replay store are implemented. This closure record does not
claim live interoperability against a running Support Chat installation —
that is WP6, explicitly out of scope for this slice (see "Next" below).**

## Scope closed (work packages 1–5)

- **WP1 — key generation and vault storage.** `SupportChatAdapter\Auth\OwnKeyManager`
  generates this plugin's own Ed25519 key pair (`sodium_crypto_sign_keypair()`)
  on first use, idempotently. The public key and key ID are stored in a WP
  option (`universal_telegram_support_chat_contract_own_key`); the private
  key is encrypted through this plugin's existing `CredentialVault`
  (context `support_chat_adapter.contract_own_signing_key`) into a separate
  option — never a new secret-storage mechanism. The private key is never
  exposed by any diagnostics/status endpoint or the pairing UI.
- **WP2 — pairing subdomain.** `SupportChatAdapter\Auth\PeerRepository` /
  `PeerRecord` / `PairingService` / `PairingResult` implement idempotent
  pairing, confirm-before-replace on an active-key replacement, revoke,
  disable/enable, and expiry. `SupportChatAdapter\Pairing\PairingController`
  is a new Hub tab (`support-chat-pairing`) gated on
  `CapabilityRegistrar::MANAGE` for visibility, with every mutating action
  additionally requiring `ContractConstants::SUPPORT_CHAT_MANAGE_CAPABILITY`
  (`universal_support_chat_manage`) — both capabilities, never either alone.
  It shows this plugin's own public key/key ID (never the private key),
  accepts Support Chat's public key/key ID/permitted-operation set, and
  surfaces peer status/dates/diagnostics.
- **WP3 — outbound signing.** `SupportChatAdapter\Inbound\SupportChatContractClient`
  signs and dispatches (via in-process `rest_do_request()`, exactly as this
  plugin's own `DiscoveryClient` already reaches Support Chat) all 8
  adapter → Support Chat operations SC-M03 work package 0 actually verifies
  and dispatches: `ingest_operator_reply`, `claim`, `release`, `resolve`,
  `reopen`, `update_assignment`, `report_channel_unavailable`,
  `report_delivery_failure`. **`update_operator_presence` is deliberately
  not implemented** — it is a defined ADR-0005 §5 operation, but Support
  Chat's own `ContractOperations::ADAPTER_TO_SUPPORT_CHAT` allow-list
  (SC-M03 work package 0) omits it (no Availability-boundary storage until
  SC-M06), so Support Chat can never pair a peer to call it and its
  discovery never advertises it; signing a call for it would be
  meaningless. `ContractConstants::adapter_to_support_chat_operations()`
  and `required_operations()` were updated to match this reality exactly
  (previously listed 13 operations across both directions including
  `update_operator_presence`, none of which Support Chat's real discovery
  ever advertises as a set — see "Discovery correction" below).
  Every call fails closed (never signs/sends) if: the adapter is disabled,
  no active/usable Support Chat peer is paired, discovery is not
  Compatible, or this plugin's own signing key/vault is unavailable.
- **WP4 — inbound verification.** `SupportChatAdapter\Auth\SignatureVerifier`
  implements ADR-0007 §3–§4's full verification profile (no query string;
  exact Contract-version/auth-profile/audience match; paired active peer
  key; key-ID match; operation on the peer's allow-list; ±300s timestamp
  window; nonce-format and atomic replay-claim; exact raw-body SHA-256;
  Ed25519 signature). `OutboundContractController::authorize_operation()`
  requires this verification to pass **and** the pre-existing
  `universal_telegram_support_chat_adapter_rest_authorized` default-deny
  filter/Compatible-discovery gate to pass — both, independently, for
  every one of the four Support Chat → adapter operations
  (`ensure_channel_case`, `notify_operators`, `deliver_transcript_backfill`,
  `deliver_message`). Neither check was weakened; the filter still defaults
  false and is unchanged in shape.
- **WP5 — nonce replay store and housekeeping.** `SupportChatAdapter\Auth\NonceReplayRepository`
  holds only `(sender, key_id, nonce, recorded_at)`, enforced race-free by
  a database UNIQUE key, with a 600-second retention window.
  `SupportChatAdapter\Auth\NonceReplaySweep` purges expired rows every 300
  seconds via this plugin's existing Action Scheduler cleanup pattern
  (mirroring `Automations\Intelligence\SummaryAiLeaseSweep`).
- **Discovery/status truthfulness (plan v2 §5's "no false compatibility
  claim").** `OutboundContractController::handle_status()` now reports
  `operational: true` only when Compatible discovery **and** a usable
  paired Support Chat peer both hold, plus a `pairing_state` field and a
  `waiting_for` value that distinguishes "needs pairing" from "needs
  Support Chat discovery compatibility" — replacing the old blanket
  `sc_m03_authenticated_contract_server` placeholder and the stale
  "SC-M02 discovery is inert" Hub notice text.

## Discovery correction (documented deviation from plan v2 §5's illustrative schema)

Plan v2 left the exact operation-list shape open at code-freeze. Reading
Support Chat's real SC-M03 work package 0 (`ContractDiscovery::handle_discover()`)
showed that its `channel-contract` discovery route only ever advertises the
subset of `ContractOperations::ADAPTER_TO_SUPPORT_CHAT` (the 8
adapter → Support Chat operations) that the currently paired peer is
permitted to call — it never advertises the 4 Support Chat → adapter
operations (those describe what Support Chat sends, not what it accepts).
`ContractConstants::required_operations()` was therefore corrected from a
13-item cross-direction superset to exactly the 8 adapter → Support Chat
operations, so `AdapterAvailability::Compatible` reflects what real SC-M03
discovery can actually report, instead of a combination that could never be
satisfied.

## Schema (new, this slice)

- `universal_telegram_support_chat_peers` — peer identity, public key
  (base64), key ID, allowed-operations JSON, required peer capability,
  status, created/rotated/used/expires/revoked timestamps. Never a private
  key.
- `universal_telegram_support_chat_contract_nonces` — `(sender, key_id,
  nonce, recorded_at)` with a `UNIQUE KEY (sender, key_id, nonce)`. Never a
  request body or Contract payload field.
- `db_version` 31 → 32 (migration step 32, `Migrator::step_32_create_support_chat_contract_auth_tables()`
  / `verify_step_32()`), following the exact numbered-step,
  postcondition-verified pattern every prior step uses. Both tables and the
  own-key options are dropped by `Uninstaller` on full uninstall.

## Explicit non-goals (unchanged from plan v2 §10, and confirmed still true of this implementation)

- No changes to the Support Chat repository (`universal-support-chat`) —
  read-only reference throughout.
- No legacy migration, quiescence, cutover, rollback, soak, or backfill
  execution.
- No removal of UT Conversations, AI, widget, or legacy chat settings.
- No support hours, tickets, visual redesign, or AI work.
- No changes to unrelated UT features (M08.1/M08.2/Automations/digests
  unaffected; verified by the full pre-existing test suite staying green).
- No release, tag, deployment, or real-site pairing performed.
- No interoperability claim against a live Support Chat installation is
  made by this closure — see "Next".

## Verification

- Unit (`vendor/bin/phpunit -c phpunit.xml.dist`, 377 tests, all green):
  key-ID derivation and format validation; nonce format/generation; exact
  ten-line canonical-string construction and signing
  (`SignatureSigner`, verified against `sodium_crypto_sign_verify_detached()`
  over the reconstructed canonical string); the full `SignatureVerifier`
  matrix (valid request; missing/malformed header; wrong contract-version/
  auth-profile/sender/audience; unknown/revoked/disabled/expired peer key;
  unsupported operation; operation off the peer's allow-list; invalid/
  wrong-key signature; body tamper; stale/future/malformed timestamp;
  nonce replay; query string present; mismatched route/method); the
  client's fail-closed gates reachable without a WordPress bootstrap
  (unwired, disabled adapter, unpaired/revoked/disabled peer, discovery
  incompatible, unsupported operation, `update_operator_presence` absent as
  a method); `ContractConstants` operation allow-lists; a structural check
  that this plugin's `SupportChatAdapter` code never references Support
  Chat's own tables or a literal SQL table name.
- Integration (`vendor/bin/phpunit -c phpunit-integration.xml.dist` against
  WordPress 6.9, 1030 tests, all green): key generation/custody (idempotent,
  private key never in the public option or in plaintext in the encrypted
  option); pairing requires BOTH `universal_telegram_manage` and
  `universal_support_chat_manage` (denied with either alone); idempotent
  pairing; confirm-before-replace; rotate; revoke; disable/enable; expiry;
  a full signed outbound dispatch through a local fixture Contract server
  standing in for SC-M03 (asserting the exact headers/canonical-string/
  signature sent and that they verify against this plugin's own public
  key); fixture failure responses surfaced as not-ok; unpaired clients
  never reaching the fixture; `OutboundContractController` requiring BOTH
  gates (signature alone without the filter rejected; filter alone without
  a valid signature rejected; both together reach the acceptor; an
  operation outside the peer's allow-list rejected even with the filter
  true; body tamper rejected; nonce replay on redelivery rejected); every
  pre-existing Migrator/Hub-navigation/binding test updated for
  `db_version` 32 and the new pairing tab, confirming no regression to
  unrelated M01–M11 functionality.
- `phpcs` clean across the whole repository; `phpstan analyse
  --memory-limit=1G` clean (no errors).
- `bin/docker/composer.sh run-script check-doc-links` run as part of this
  closure (see repository CI evidence in the PR).

## Pins recorded

| Item | Value |
|---|---|
| Support Chat ADR-0005 (Contract v1) SHA | `dff2730e24b7d3f70f15f706305e12e14fdcc6c8` |
| Support Chat ADR-0007 (auth profile) SHA | `8ee396d8b8edcbf526797c0a1f5741f3842df57a` |
| Support Chat PR #6 merge SHA (SC-M03 work package 0) | `2f748168f591bec551a740a5060d394bc6e29ba3` |
| UT `main` branch point | `51ab1aa` |
| UT `db_version` target | `32` (was `31`) |
| Plugin SemVer | unchanged |

## Unresolved limitations

- WP6 (joint interoperability tests against a live Support Chat
  authenticated Contract server) has not run — no live Support Chat
  installation was paired or exercised in this closure. This is an
  explicit hard gate on Support Chat's own SC-M03 migration/cutover work,
  per plan v2 §8 WP6 and §11.
- The `universal_telegram_support_chat_adapter_rest_authorized` filter's
  production wiring to a real trust source beyond this plugin's own tests
  (i.e. what legitimately sets it `true` outside a test's `add_filter`) is
  intentionally left to the WP6 interoperability work — this slice proves
  both gates are independently enforced, not how a production deployment
  populates the second one operationally.

## Next

Joint authenticated interoperability tests across both merged plugins (UT
`feature/ut-adapter-m1-signed-contract-client` and Support Chat PR #6 /
SC-M03 work package 0, both now containing real ADR-0007 implementations)
— plan v2 work package 6, a hard gate before Support Chat's own SC-M03
migration/cutover work proceeds past its own work package 1 gate.
