# ADR-0039 — Support Chat ADR-0008 Pin and Legacy Export Boundary Follow-up

## Status

Accepted.

> **Superseded by ADR-0044** (2026-08-28, Product Owner). Universal Telegram becomes transport/adapter only; its legacy website chat is retired and discarded, not migrated, so the SC-M03 migration/cutover track this ADR belongs to is closed. This Status note is the only change; the sections below are retained unedited as the historical record.

## Context

ADR-0038 pinned Support Chat ADR-0007 (Contract v1 mutual signed adapter authentication profile) and authorised this plugin's signed Contract client follow-up. That work is complete: work packages 1–5 of `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v2.md` (closure: `docs/closure/ut-adapter-m1-signed-contract-client-closure.md`) and work package 6, the joint interoperability gate against a live Support Chat authenticated Contract server (closure: `docs/closure/ut-adapter-m1-wp6-interop-gate-closure.md`), are both implemented, tested, and merged (PR #34, PR #35).

Support Chat's SC-M03 sequencing (`sc-m03-controlled-migration-and-cutover.md` §0, reproduced in ADR-0038 §5 step 4) gates its legacy migration engine (work packages 3–4: batch migrator/backfill and validators) behind exactly this completed interoperability gate — and behind one more thing: **Support Chat ADR-0008**, which fixes the mechanism by which its migration engine reads legacy Universal Telegram conversation data. Contract v1's operation allow-list (ADR-0007 §4, referenced unedited) does not, and per ADR-0008 will not, cover bulk legacy-data export — a distinct, narrower mechanism is required, and Support Chat's ADR-0008 defines it: a versioned, in-process PHP export interface, `LegacyExportServiceV1`, that Universal Telegram must implement on its own side.

`docs/governance.md`'s freeze model ("No implementation code may precede the ADRs it relies on") requires this plugin to pin that mechanism and define its own limited implementation obligation before writing any export-boundary code. This ADR does that. It amends no prior ADR's Decision text (ADR-0037 and ADR-0038 are not rewritten) and changes no runtime code.

## Decision

### 1. Pin (exact, immutable reference)

| Item | Value |
|---|---|
| Support Chat ADR-0008 | Commit SHA `7546d43be66f8e3b2f179f03a1c81c9aadef59db` — `https://github.com/magpern/universal-support-chat/blob/7546d43be66f8e3b2f179f03a1c81c9aadef59db/docs/adr/0008-legacy-export-boundary-and-migration-authority-model.md` |

Universal Telegram does not copy ADR-0008's text into this repository, mirroring the existing ADR-0037/ADR-0038 rule against duplicating Support Chat's ADRs.

### 2. Universal Telegram's obligation is limited to `LegacyExportServiceV1`, exactly as ADR-0008 defines it

This ADR authorises **only** the implementation of `LegacyExportServiceV1` in this repository's own codebase, to the exact contract Support Chat ADR-0008 §2–§5 specifies. It authorises nothing beyond that:

- **Universal Telegram owns all legacy-source reads and decryption.** Only this plugin's own existing repository classes (`Conversations\MessageRepository`, `Conversations\ConversationNoteRepository`, and the equivalent for `conversations` rows, already the sole authorized readers of this plugin's own `Core\Security\CredentialVault`-encrypted columns) ever decrypt legacy `conversations`/`conversation_messages`/`conversation_notes` ciphertext for this boundary. Support Chat never queries this plugin's database tables directly, and is never given this plugin's vault key material, an encrypted secret, or any derivative of it. This is the same "each plugin holds only its own key material" posture ADR-0007/ADR-0038 already establish for Contract v1 signing keys, applied here to vault encryption keys instead.
- **Versioned, in-process, WP-CLI-context-only.** `LegacyExportServiceV1` is a plain PHP class with a stable, explicitly versioned method contract (`export_batch(): array`, carrying `"export_schema_version": 1` in its return shape) — never a WordPress REST route, never an Ajax handler, never a cron-invoked path, and never an addition to Contract v1's operation allow-list (ADR-0007 §4, unmodified by this ADR). It is called in-process, within the same PHP request running Support Chat's migration WP-CLI command, because both plugins already run in the same WordPress install (ADR-0002's non-goals, unchanged). **The service itself checks `defined( 'WP_CLI' ) && WP_CLI` and rejects every invocation attempted outside a WP-CLI process — a web request, an Ajax handler, a REST callback, a cron job — unconditionally, regardless of capability or authentication state.** This is a real, self-enforced gate, not a caller convention.
- **The actual security boundary is host authority to execute WP-CLI against this WordPress install** — the same shell-access boundary this plugin's own existing WP-CLI conventions already rely on. `LegacyExportServiceV1`'s `WP_CLI`-context check closes off every externally reachable path (web, Ajax, REST, cron); it cannot, and does not claim to, distinguish Support Chat's migration command from any other code already executing inside that same authorized WP-CLI process. Support Chat's `--assume-migration-authority` flag (documented in Support Chat's own repository) is that plugin's own command-level operator-confirmation guard against *accidentally* triggering a real migration run — it is not Universal Telegram authentication, not a credential this plugin verifies, and not an independently enforceable cross-plugin authentication mechanism. This plugin's obligation under this ADR is exactly and only the `WP_CLI`-context check described above; it implements no corresponding flag or credential of its own, because none would add real security beyond what host-level WP-CLI access already requires.
- **No REST route, Ajax handler, cron path, Contract v1 operation, shared secret, bearer token, application password, or permanent cross-plugin SQL access is authorised by this ADR, under any circumstance.** Universal Telegram's existing REST surface (`SupportChatAdapter\Outbound\OutboundContractController`) and its ADR-0007/ADR-0038 signed-request handling are entirely unaffected — `LegacyExportServiceV1` is a new, separate, non-REST code path, not an extension of the existing Contract v1 acceptors.
- **Redaction happens at this plugin's source, not at Support Chat's receiving end.** `LegacyExportServiceV1::export_batch()` returns only the fields Support Chat ADR-0008 §5 lists as part of the export shape (the legacy numeric `id`, `conversation_uuid`, `bot_id`, `destination_id`, `status`, `assigned_operator_id`, `owner_user_id`, `topic_creation_state`, `telegram_topic_id`, `topic_lifecycle_state`, `start_idempotency_key`, the four timestamp fields, `assignee_last_seen_message_id`, and each conversation's ordered messages/notes with decrypted body plaintext). Fields ADR-0008 does not list — `secret_hash`, `chat_profile`, `session_ref`, `consent_state`, `ai_participation_state`, `telegram_sender_user_id`, `outbound_message_uuid`, `telegram_message_id`, and any AI-drafts/operator-identity/operator-availability table — are never emitted by this service at all. A field never emitted cannot be logged, cached, or migrated by mistake on Support Chat's side.
- **Batch limits and error behaviour follow ADR-0008 §5 exactly**: a server-side cap of 100 conversations per call regardless of what the caller requests; a per-conversation read failure (decrypt failure, malformed row) returned as a typed error entry within the batch result, never a thrown exception aborting the whole batch.
- **Plaintext exists only in memory for the duration of this plugin's own decryption within a single `export_batch()` call.** It is returned to the caller across the in-process function-call boundary and is Support Chat's responsibility to re-encrypt immediately (ADR-0008 §3) — this plugin never logs it, never persists it outside its own existing `conversations`/`conversation_messages`/`conversation_notes` ciphertext columns, and never writes it to any diagnostics, audit record, or WP-CLI output of its own.

### 3. `QuiescenceStateProvider` — cross-referenced, not owned by this repository

Support Chat ADR-0008 §6 freezes a `QuiescenceStateProvider` interface (`is_quiescent(): bool`, `since(): ?DateTimeImmutable`) that Support Chat's migration engine (work packages 3–4, external repository) consumes as Phase B's sole precondition gate, shipped there only as a default-deny stub and a test seam until a real implementation exists. **This interface is defined and owned entirely in the Support Chat repository; nothing in this ADR creates, modifies, or is required to implement any part of it.** This repository's own future quiescence work — pausing this plugin's legacy retention cleanup, topic-creation/deletion, and outbound-routing Action Scheduler jobs, and blocking new legacy conversation writes during a migration window (Support Chat plan v2 §8 work package 2, unstarted, unscoped by this ADR) — is confirmed here only as a forward commitment: **when this repository eventually builds that quiescence mechanism, its provider must satisfy Support Chat's exact frozen `QuiescenceStateProvider` interface, exposed in whatever form Support Chat's work package 2 design requires to consume it — no later Universal Telegram milestone may redefine what "quiescent" means from this repository's side, or expect Support Chat's Phase B to accept a different signal shape or a bypass.** This ADR does not schedule, scope, or authorise that future work package; it only binds this plugin, in advance, to the interface Support Chat has already frozen, exactly as ADR-0008 §6 requires of "WP2's eventual real provider."

### 4. Explicit scope boundary — what this ADR does not authorise

This ADR authorises `LegacyExportServiceV1` and its tests. It authorises **nothing else**. In particular, this repository does not implement, and this ADR does not permit:

- Legacy migration *orchestration* (batching, checkpoints, resumability, the migration WP-CLI command itself) — that is Support Chat's own work packages 3–4, entirely in the Support Chat repository.
- Writing to Support Chat's target `conversations`/`conversation_messages`/`conversation_notes` tables, or any other Support Chat-owned schema — this repository never writes to Support Chat's database, mirroring the "no plugin reads or writes another plugin's database tables directly" rule ADR-0007 §1 already establishes.
- Quiescence switches, drains, or any pause mechanism (Support Chat plan v2 §8 work package 2) — deferred per §3 above.
- Binding creation for existing Telegram topics (work package 5) — a separate, later, unstarted unit of work in the Support Chat repository, which per that plan's own design calls this plugin's *existing* `ensure_channel_case`/pairing infrastructure (already implemented under ADR-0038) rather than requiring new code here.
- Atomic route switch, cutover orchestration, soak, or rollback (work packages 6–7) — entirely future, entirely out of scope.
- Any AI-related migration or rehoming — the SC-M03 charter's own "no AI migration" exclusion, unaffected by this ADR.
- This plugin's legacy Conversations tab, AI tab, chat widget, or chat settings decommission — a separate future task gated on SC-M03 acceptance (ADR-0038 §5 step 5, unchanged), not touched here.

### 5. Implementation sequence (locked)

1. **Implemented.** Support Chat SC-M03 work packages 0–1 (authenticated Contract server; this plugin's signed Contract client and joint interoperability gate) — both repositories, merged.
2. **This ADR's follow-up:** Universal Telegram's `LegacyExportServiceV1` and its own boundary tests (§2 above) — this repository, not yet implemented.
3. **Support Chat's SC-M03 work packages 3–4:** the legacy migration engine (batch migrator/backfill, validators), consuming `LegacyExportServiceV1` — Support Chat repository, gated on step 2 above merging here.
4. Only after SC-M03 acceptance: this plugin's legacy Conversations tab, AI tab, chat widget, and chat settings decommission (unchanged, ADR-0038 §5 step 5).

**No `LegacyExportServiceV1` implementation code may begin until this ADR is merged to `main`. Support Chat's SC-M03 work packages 3–4 implementation code may not begin until both this ADR and Support Chat's own ADR-0008 are merged to their respective `main` branches** — the identical two-repository gate ADR-0007/ADR-0038 already established for the Contract server/signed-client pair, and that Support Chat's ADR-0008 already states from its own side.

## Alternatives

- **Extend Contract v1's operation allow-list with a bulk-export operation** — rejected: ADR-0007 §4's allow-lists are fixed and closed; a bulk historical read is a different security shape than Contract v1's real-time, per-conversation, signed mutation calls, and Support Chat's own ADR-0008 already rejected this for the same reason.
- **A new authenticated REST route in this plugin dedicated to migration export** — rejected: introduces new public-network attack surface for a same-host, operator-invoked, one-time administrative operation; the in-process call (§2) achieves the same result with strictly less exposed surface.
- **Allowing Support Chat direct `$wpdb` access to this plugin's tables** — rejected outright: violates this plugin's existing plugin-ownership and encryption boundaries (ADR-0002, ADR-0007 §1) and bypasses this plugin's own vault-decryption authority, which this ADR requires stay exclusively on this plugin's side.
- **A shared migration secret/token authorising the export call** — rejected: same reasoning ADR-0007/ADR-0038 already applied to Contract v1 signing; relying on the existing WP-CLI/host-authority boundary closes the same externally reachable paths without introducing a value both plugins must keep confidential, with the same stated limitation that no credential can restrict what already-authorized code on the same host can do.
- **Implementing this plugin's own quiescence pause mechanism now, bundled with the export boundary** — rejected: quiescence is Support Chat plan v2 work package 2, unscoped and unstarted; bundling it here would exceed this ADR's narrow, limited obligation (§4) and pre-empt a design this repository hasn't yet reviewed.

## Consequences

- `LegacyExportServiceV1` now has a precise, pinned target to implement against in this repository's own follow-up slice, closing the mechanism gap Support Chat's ADR-0008 identified from its side.
- This repository gains a forward commitment (§3) to Support Chat's frozen `QuiescenceStateProvider` interface without gaining any new implementation obligation today.
- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/adr/README.md`, `docs/milestones/README.md`, the UT Adapter M1 charter, and a new UT Adapter M1 plan v3 are updated in this same freeze to reference this ADR.
- `docs/adr/0037-support-chat-extraction-supersession-and-optional-adapter-consumer.md` and `docs/adr/0038-support-chat-adr-0007-pin-and-signed-contract-client-follow-up.md` are unchanged; this ADR supplements them, it does not supersede or rewrite either.
- No plugin version, `db_version`, release, tag, or deployment change.

## Security and privacy impact

- Legacy vault key material never crosses the plugin boundary in either direction; Support Chat's migration engine can only ever obtain plaintext momentarily, through this plugin's own authorized decryption path, never this plugin's key.
- Redaction happens at this plugin's source (§2): fields not required for migration are never emitted by the export boundary at all, rather than relying on Support Chat to discard them correctly after receiving them.
- The real security boundary — host authority to execute WP-CLI against this install — is stated precisely, without overstating what `LegacyExportServiceV1`'s in-process `WP_CLI`-context check or Support Chat's `--assume-migration-authority` flag can independently guarantee (§2).
- No new capability is granted to any WordPress user role in this plugin, and no new network-reachable endpoint is created.

## Affected Documents/Milestones

- `docs/adr/README.md` (reserved-number table and index updated for ADR-0039).
- `docs/ARCHITECTURE.md`, `docs/master-plan.md`, `docs/milestones/README.md` (cross-repo sequence and pin references updated, additive).
- `docs/milestones/ut-adapter-m1-universal-support-chat-adapter.md` (additive amendment recording this ADR and the new implementation gate).
- `docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md` (new — supersedes plan v2 only to add the `LegacyExportServiceV1` work package; v1 and v2 retained unedited).
- `docs/closure/ut-support-chat-adr-0008-legacy-export-boundary-pin-closure.md` (new — this documentation freeze's own closure record).
- ADR-0002, ADR-0007, ADR-0037, ADR-0038 (referenced, unedited).
- Support Chat repository: ADR-0008 (pinned, external, unedited by this ADR); SC-M03 charter §0b and the SC-M03 work packages 3–4 plan (external, unedited by this ADR).

## Compatibility/Migration Impact

- No runtime code, schema, plugin version (`0.16.0` unchanged), `db_version` (`32` unchanged), release, tag, or deployment change in this freeze.
- This repository's `LegacyExportServiceV1` implementation, and Support Chat's SC-M03 work packages 3–4, remain unimplemented until the sequence in §5 is followed. This ADR does not authorize, schedule, or execute any production legacy-data migration.
