# Closure Record — UT Adapter M1 Legacy Export Boundary (ADR-0039 work package 8)

## Status

Implementation complete for work package 8 of
`docs/plans/ut-adapter-m1-universal-support-chat-adapter-plan-v3.md`.
Closed for implementation review (awaiting Product Owner acceptance / merge)
— per `docs/governance.md`, the Implementation Agent cannot self-certify
closure.

- Frozen plan commit SHA: `6632d8c` (merge of PR #36, which carried the
  ADR-0039 freeze and plan v3 onto `main`)
- Authorising ADR: `docs/adr/0039-support-chat-adr-0008-pin-and-legacy-export-boundary-follow-up.md`
  (unchanged by this implementation)
- Support Chat ADR-0008 pin (unchanged): SHA `7546d43be66f8e3b2f179f03a1c81c9aadef59db`
- UT `main` branch point for this implementation: `6632d8c` (merge of PR #36)

## Accurate scope statement

**`LegacyExportServiceV1::export_batch()` is implemented exactly to
ADR-0039 §2 / Support Chat ADR-0008 §2–§5: a plain PHP class, reachable
only from within a WP-CLI process, that reads legacy `conversations`,
`conversation_messages`, and `conversation_notes` rows exclusively through
this plugin's own existing `ConversationRepository`, `MessageRepository`,
and `ConversationNoteRepository` — the sole authorized decryptors of this
plugin's `CredentialVault`-encrypted columns — and returns the ADR-0008 §5
allow-listed export shape. This closure does not implement, and does not
claim, any Support Chat-side migration orchestration, target writes, or
production migration execution; those remain entirely out of scope for
this work package (ADR-0039 §4).**

## Scope closed (work package 8)

- `src/SupportChatAdapter/Migration/LegacyExportServiceV1.php` (new) —
  `export_batch( int $after_source_id, int $limit ): array`. Rejects every
  invocation outside a WP-CLI process by throwing
  `LegacyExportContextRejectedException` (new,
  `src/SupportChatAdapter/Migration/LegacyExportContextRejectedException.php`)
  — a hard refusal, never a silently empty result. Enforces the 100-conversation
  batch ceiling server-side regardless of the caller's requested `$limit`.
  Returns `export_schema_version: 1` and, per conversation, either the full
  ADR-0008 §5 allow-listed field set (plus ordered `messages`/`notes` with
  decrypted plaintext) or a typed `{"id": ..., "error": "decrypt_failed"|"export_failed"}`
  entry — never a thrown exception that aborts the rest of the batch. A
  message whose `body_ciphertext` is legitimately `null` (retention-nulled)
  exports with a `null` body; that is not treated as a decrypt failure.
- `src/Conversations/ConversationRepository.php` (amended) — new method
  `after_id( int $after_id, int $limit ): array`, the keyset-cursor read
  `LegacyExportServiceV1` uses to page conversations, mirroring
  `MessageRepository::messages_since()`'s existing cursor idiom. No other
  method on this class, or on `MessageRepository`/`ConversationNoteRepository`,
  was added, changed, or removed.
- `src/Core/Plugin.php` (amended) — wires `LegacyExportServiceV1` into the
  composition root and exposes it via `Plugin::instance()->legacy_export_service()`,
  the same access pattern this plugin's other in-process cross-plugin
  surfaces already use. No new WP-CLI command is registered by this
  repository (ADR-0039 §5) — invocation is Support Chat's own future
  migration command's responsibility, calling this accessor in-process.
- Unit tests: `tests/unit/SupportChatAdapter/Migration/LegacyExportServiceV1Test.php`
  (12 tests) — WP-CLI-context rejection (web/Ajax/REST/cron-representative
  cases, all sharing the single underlying `defined('WP_CLI') && WP_CLI`
  precondition this service can observe), the 100-row batch ceiling, cursor
  pass-through, schema-unavailable typed refusal, the exact export-shape
  allow-list (and confirmed absence of every ADR-0008-excluded field's
  value), message/note ordering, per-conversation typed-error isolation
  (one failing conversation does not abort the batch), the retention-nulled-body
  distinction, and an explicit "no write method is ever called" guarantee
  against each repository collaborator.
- Integration tests: `tests/integration/SupportChatAdapter/Migration/LegacyExportServiceV1Test.php`
  (5 tests) — `export_batch()` against real seeded fixtures through this
  plugin's real `CredentialVault`, confirming decrypted plaintext round-trips
  correctly; confirming every ADR-0008-excluded field (`secret_hash`,
  `chat_profile`, `session_ref`, `consent_state`, `ai_participation_state`,
  `ai_ack_policy_version`, `display_name_ciphertext`, the topic-claim/lifecycle-code
  fields, `outbound_message_uuid`, `telegram_message_id`, `telegram_sender_user_id`,
  `delivery_state`) is genuinely absent from a real export, not merely
  absent from a mocked one; cursor repeatability (a second pass picks up
  only conversations created after the first pass's high-water mark);
  no mutation of the legacy source conversation or message rows across an
  export call; the retention-nulled-body case against a real database row.

## Explicit non-implementation confirmation (ADR-0039 §4)

- No Support Chat repository file was read, written, or referenced by path.
- No write to any Support Chat `conversations`/`conversation_messages`/`conversation_notes`
  table, and no migration orchestration, batching, checkpointing, or
  WP-CLI migration command of any kind — those remain entirely Support
  Chat's own future SC-M03 work packages 3–4.
- No new REST route, Ajax handler, cron-invoked path, or Contract v1
  operation-allow-list change. `OutboundContractController` and
  `SupportChatContractClient` are unchanged.
- No quiescence mechanism, `QuiescenceStateProvider` implementation, or
  pause/drain logic of any kind — that interface remains defined and owned
  entirely in the Support Chat repository (ADR-0039 §3); this closure adds
  no code toward it.
- No binding creation for existing Telegram topics, no cutover/soak/rollback
  code, no AI-related change.
- No removal of this plugin's legacy Conversations tab, AI tab, chat widget,
  or chat settings.
- No plugin version change (`0.16.0` unchanged). No `db_version` change
  (`32` unchanged) — this work package required no schema change; the plan's
  own §5 anticipated this ("no new database table, no schema/migration
  change"), confirmed true by the actual implementation.
- No release, tag, or deployment.

## Security and privacy verification

- `LegacyExportServiceV1::assert_wp_cli_context()` is the class's own,
  unconditional, self-enforced check — confirmed by unit tests covering
  every externally reachable invocation context sharing the same
  `defined('WP_CLI') && WP_CLI` precondition (ADR-0008 §4). No caller
  convention, capability check, or nonce is relied on instead.
- Redaction is verified at the source, not merely by omission in this
  document: the integration test suite asserts, against a real database
  row and a real `CredentialVault`, that every ADR-0008-excluded field's
  actual stored value is absent from a genuine export — not just that the
  allow-listed keys are the only ones present.
- Plaintext returned by `export_batch()` exists only as the PHP return
  value for the duration of the caller's own processing; this
  implementation never logs it, never writes it to any WP-CLI output of
  its own (this repository registers no WP-CLI command), and never
  persists it outside the plugin's own existing `body_ciphertext` columns.
- `NoSupportChatSqlTest` (pre-existing, `tests/unit/SupportChatAdapter/NoSupportChatSqlTest.php`)
  continues to pass against `src/SupportChatAdapter/Migration/LegacyExportServiceV1.php`
  — this class never touches `$wpdb` directly and contains no Support Chat
  table-name literal, confirming it reaches legacy data exclusively through
  `ConversationRepository`/`MessageRepository`/`ConversationNoteRepository`.

## Validation

- `bin/docker/phpcs.sh` — clean (0 errors, 0 warnings) across all 504
  inspected files, including the three new files and the two amended files.
- `bin/docker/phpstan.sh` (level 5) — `[OK] No errors`.
- `bin/docker/test-unit.sh` — 389 tests, 1206 assertions, OK (1 pre-existing
  unrelated skip); the 12 new `LegacyExportServiceV1Test` unit tests pass
  in isolation (`--filter LegacyExportServiceV1Test`: 12 tests, 34
  assertions, OK).
- `bin/docker/test-integration-wp-only.sh --wp-version=6.9` — 1036 tests,
  3394 assertions, OK (58 pre-existing unrelated skips; interspersed
  `WordPress database error` lines are expected negative-path output from
  pre-existing tests intentionally triggering unique-constraint violations,
  not failures).
- `bin/docker/test-integration-wp-only.sh --wp-version=7.1` — 1036 tests,
  3394 assertions, OK (same pre-existing skip/negative-path profile).
- `bin/docker/test-integration-wc-present.sh --wp-version=7.1 --wc-version=11.0.1`
  — 1036 tests, 4170 assertions, OK (7 pre-existing unrelated skips).
- `bin/docker/test-package.sh --wp-version=7.1 --php-version=8.3` —
  `== PACKAGE TEST PASSED for WordPress 7.1 ==`.
- `bin/docker/test-js.sh` — 57/57 passing (no JavaScript file was touched
  by this work package; run for completeness).
- `bin/docker/composer.sh run-script check-doc-links` — fails with 19
  pre-existing unresolved-link errors, all in one file this branch never
  touched: `docs/plans/m07-1-conversation-topic-lifecycle-and-repair-plan-v1.md`
  (last modified by commit `305c8d1`, unrelated to this branch; confirmed
  via `git diff --name-only origin/main -- <that file>` returning empty).
  This matches the same pre-existing staleness already documented in
  `docs/closure/ut-support-chat-adr-0008-legacy-export-boundary-pin-closure.md`.
  This repository's CI does not wire doc-link checking into any workflow
  job (confirmed by inspection of `.github/workflows/ci.yml`), so this
  finding blocks nothing in CI; it is recorded here rather than silently
  ignored.

## Next

Per this milestone's frozen plan (§11, definition of done) and ADR-0039 §5:
this repository's own obligation under ADR-0039 is now closed pending
Product Owner acceptance and merge. **Support Chat's SC-M03 work packages
3–4 (the legacy migration engine consuming this service) may not begin
implementation until this PR merges to `main`** — the two-repository gate
ADR-0039 §5 and Support Chat's own ADR-0008 Compatibility/Migration Impact
section both require.

## Product Owner acceptance

Pending. This PR is opened for review and is **not merged** by this task.
