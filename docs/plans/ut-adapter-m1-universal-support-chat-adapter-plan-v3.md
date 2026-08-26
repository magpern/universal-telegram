# UT Adapter M1 — Universal Support Chat Adapter — Implementation Plan v3

Supersedes [plan v2](ut-adapter-m1-universal-support-chat-adapter-plan-v2.md) (`docs/plans/README.md`: v2 is retained, unedited, permanently; this file is the frozen plan going forward) only to add work package 8 below. Plan v2's work packages 1–7 (signed Contract client, pairing, nonce-replay housekeeping, joint interoperability gate) are complete and closed — see `docs/closure/ut-adapter-m1-signed-contract-client-closure.md` and `docs/closure/ut-adapter-m1-wp6-interop-gate-closure.md` — and are not reproduced or re-scoped here.

## 1. References

- Charter: `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (§0b legacy export boundary follow-up amendment)
- ADRs: ADR-0002, ADR-0007, ADR-0037, ADR-0038 (unedited), **ADR-0039** (new — Support Chat ADR-0008 pin and legacy export boundary follow-up)

## 2. Repository findings (plan-freeze time)

Plan v2's work packages 1–7 are implemented and merged (PR #34, PR #35): real ADR-0007 mutual Ed25519 signing/verification, administrator pairing, nonce-replay housekeeping, and a proven joint interoperability gate against a live Support Chat authenticated Contract server. Support Chat's SC-M03 legacy migration engine (work packages 3–4, external repository) is architecturally authorised by Support Chat's own ADR-0008 but blocked on this repository supplying `LegacyExportServiceV1`, which Contract v1's closed operation allow-list (ADR-0007 §4) deliberately does not and will not cover. `db_version` is `32`; plugin version `0.16.0`; both unchanged by this plan.

## 3. Assumptions and open questions

**Assumptions:**
- This repository's existing `Conversations\MessageRepository`, `Conversations\ConversationNoteRepository`, and the conversations-table equivalent remain the sole authorized decryptors of this plugin's own `Core\Security\CredentialVault`-encrypted columns (unchanged by this plan; `LegacyExportServiceV1` calls them, it does not duplicate or bypass their decryption logic).
- Both plugins continue to run in the same WordPress install for the duration of this work package (unchanged assumption from plan v2 §3).
- Support Chat implements its own SC-M03 work packages 3–4 (migration orchestration, target writes, re-encryption, validators) entirely in its own repository; this plan does not implement or specify Support Chat-side code.

**Open questions — none remaining as unresolved architecture.** ADR-0039 fixes the export boundary's shape, authority model, and scope precisely; nothing is deferred to implementation-time invention.

## 4. Architectural decisions

- Follow ADR-0039 §2 exactly for `LegacyExportServiceV1`: versioned (`export_schema_version: 1`), in-process, `WP_CLI`-context-only (self-enforced, unconditional rejection outside WP-CLI), redaction-at-source (only the fields ADR-0008 §5 lists are ever emitted), a 100-conversation-per-call batch ceiling enforced inside the service regardless of caller request, typed per-conversation error entries rather than batch-aborting exceptions.
- Follow ADR-0039 §2 exactly for the security-boundary framing: the real boundary is host authority to execute WP-CLI against this install; the service's own `WP_CLI`-context check closes every externally reachable path (web/Ajax/REST/cron); nothing in this plan claims to authenticate "which WP-CLI invocation" beyond that.
- No Contract v1 operation-allow-list change (ADR-0007 §4 remains closed and unmodified); no new REST route, Ajax handler, or cron-invoked path.
- Follow ADR-0039 §3 exactly for `QuiescenceStateProvider`: this plan implements no part of it — it is defined and owned entirely by Support Chat's ADR-0008, consumed by Support Chat's own future work package 2 obligation, not this repository's work package 8.

## 5. Directory, namespace, schema, and API impact

- New class: `SupportChatAdapter\Migration\LegacyExportServiceV1` (namespace illustrative, final naming at implementation time), implementing `export_batch( int $after_source_id, int $limit ): array` per ADR-0039 §2.
- No new database table, no schema/migration change, no `db_version` bump — this service only reads existing `conversations`/`conversation_messages`/`conversation_notes` tables through this plugin's existing repository classes.
- No new REST route registered. No change to `SupportChatAdapter\Outbound\OutboundContractController` or any existing Contract v1 acceptor.
- No new WP-CLI command in this repository — invocation is driven entirely by Support Chat's own migration WP-CLI command calling this class in-process; this plugin exposes the class, it does not register a CLI entry point of its own.

## 6. Security and privacy impact

Per ADR-0039 in full: legacy vault key material never crosses the plugin boundary; redaction happens at this plugin's source per the export shape ADR-0008 §5 fixes; the `WP_CLI`-context check is real and closes external paths without overstating what it can guarantee against code already running inside an authorized WP-CLI process; plaintext exists only in memory for the duration of a single `export_batch()` call and is never logged or persisted by this plugin outside its own existing ciphertext columns.

## 7. Test and CI impact

- Unit tests: `WP_CLI`-context rejection (web/Ajax/REST/cron-simulated invocation contexts all rejected); batch-limit enforcement (a request for more than 100 conversations returns at most 100); export-shape completeness (every ADR-0008 §5-listed field present, every non-listed field absent from the output); per-conversation error entries for a forced decrypt failure, without aborting the batch.
- Integration tests: `export_batch()` against real seeded `conversations`/`conversation_messages`/`conversation_notes` fixtures, verifying decrypted plaintext is returned correctly and that this plugin's own `CredentialVault` is the only decryption path exercised.
- No integration test in this repository writes to any Support Chat table or asserts anything about Support Chat's migration engine's behavior — that verification belongs to Support Chat's own dual-plugin interop-style test suite (its work packages 3–4 plan), consistent with the existing `tests/integration/Interop/` pattern this repository already established for the WP6 gate, which this plan does not replicate for the export boundary (no live dual-plugin proof is required for a same-process, unit/integration-testable PHP interface with no wire format to interoperate over).
- Full CI matrix (unit, integration, phpcs, phpstan, package-acceptance) per this repository's existing `.github/workflows/ci.yml` — no new job required; no new WP/PHP/WooCommerce matrix variant introduced.

## 8. Work packages (execution order)

### WP8 — Legacy export boundary (`LegacyExportServiceV1`)

- Implement `LegacyExportServiceV1::export_batch()` per ADR-0039 §2: `WP_CLI`-context self-check, versioned return shape, redaction-at-source, batch-size ceiling, typed per-row error entries.
- Unit and integration test coverage per §7 above.
- Documentation: closure record for this work package, citing this plan's freeze SHA.
- **Explicitly excluded from this work package** (ADR-0039 §4, restated for execution clarity): any Support Chat-side code, any migration orchestration, any quiescence mechanism, any binding creation, any cutover/soak/rollback code, any AI-related change, any legacy-UI removal.
- *Gate: ADR-0039 merged before this work package's implementation begins.*

## 9. Risks and mitigations

- Implementing against an unmerged ADR-0039 — mitigated by the explicit gate stated in §8 and in the milestone charter's §0b.
- Scope creep into migration orchestration or Support Chat-side concerns during implementation — mitigated by ADR-0039 §4's explicit, itemized exclusion list, restated in this plan's WP8 scope.
- A future Universal Telegram quiescence work package attempting to define its own incompatible "quiescent" signal — mitigated by ADR-0039 §3's forward commitment to Support Chat's frozen `QuiescenceStateProvider` interface; not this plan's work package, but bound in advance.

## 10. Explicit out-of-scope list

Support Chat's SC-M03 migration orchestration, target writes, re-encryption, and validators (work packages 3–4, Support Chat repository); quiescence switches/drains (Support Chat plan v2 work package 2); binding creation for existing Telegram topics (work package 5); atomic route switch, soak, rollback (work packages 6–7); any production migration execution; any AI-drafts/AI-config migration; this plugin's legacy Conversations tab, AI tab, chat widget, and chat settings decommission (separate future task, gated on SC-M03 acceptance); any Contract v1 operation-allow-list change; any new REST/Ajax/cron path.

## 11. Definition of done

1. ADR-0039 merged to `main`.
2. `LegacyExportServiceV1` implemented and passing the full test suite (§7) in this repository's CI.
3. Closure record committed; Product Owner acceptance recorded.
4. Only then does Support Chat's SC-M03 work packages 3–4 implementation proceed past its own two-repository merge gate (ADR-0008 Compatibility/Migration Impact; this plan's §8 gate, mirrored from Support Chat's side).
